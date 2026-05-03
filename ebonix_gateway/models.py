"""
Ebonix Gateway - Model Router
"""
import base64
import logging
import time
import asyncio
import httpx
from typing import Any, Dict, Optional

from config import config

logger = logging.getLogger(__name__)


class ProviderError(Exception):
    """Raised when a provider fails."""
    pass


def _fal_key(keys: dict) -> str:
    k = (keys.get("fal_api_key") or "").strip()
    if not k:
        raise ProviderError("Fal API key not configured — add it in Admin → AI Settings")
    return k


def _google_key(keys: dict) -> str:
    k = (keys.get("google_api_key") or "").strip()
    if not k:
        raise ProviderError("Google API key not configured — add it in Admin → AI Settings")
    return k


class ModelRouter:

    # =========================================================================
    # IMAGE GENERATION
    # =========================================================================

    @staticmethod
    async def generate_image(
        provider: str, model: str, prompt: str, size: str,
        negative_prompt: str = "", keys: dict = None
    ) -> Dict[str, Any]:
        keys = keys or {}
        if provider == "google":
            return await ModelRouter._google_imagen(prompt, size, keys)
        raise ProviderError(f"Unknown image provider: {provider}")

    @staticmethod
    async def _google_imagen(prompt: str, size: str, keys: dict) -> Dict[str, Any]:
        """Google Imagen 4 — text-to-image."""
        api_key = _google_key(keys)

        aspect_map = {
            "1024x1024": "1:1",
            "1024x1792": "9:16",
            "1792x1024": "16:9",
        }
        aspect = aspect_map.get(size, "1:1")
        url    = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"imagen-4.0-generate-001:predict?key={api_key}"
        )
        t0 = time.time()

        try:
            async with httpx.AsyncClient(timeout=180.0) as client:
                r = await client.post(url, json={
                    "instances":  [{"prompt": prompt}],
                    "parameters": {
                        "sampleCount":      1,
                        "aspectRatio":      aspect,
                        "personGeneration": "ALLOW_ALL",
                    },
                })

            if r.status_code != 200:
                raise ProviderError(f"Google HTTP {r.status_code}: {r.text[:500]}")

            b64 = r.json()["predictions"][0]["bytesBase64Encoded"]
            return {
                "type":       "base64",
                "mime":       "image/webp",
                "base64":     b64,
                "latency_ms": int((time.time() - t0) * 1000),
            }

        except httpx.TimeoutException:
            raise ProviderError("Google Imagen API timeout")
        except ProviderError:
            raise
        except Exception as exc:
            raise ProviderError(f"Google Imagen error: {exc}")

    # =========================================================================
    # VIDEO GENERATION
    # =========================================================================

    @staticmethod
    async def generate_video(
        provider: str, model: str, prompt: str,
        aspect_ratio: str = "16:9", resolution: str = "540p",
        image_url: str = None, keys: dict = None
    ) -> Dict[str, Any]:
        keys = keys or {}
        if provider == "google":
            return await ModelRouter._google_veo(prompt, model, image_url, keys)
        raise ProviderError(f"Unknown video provider: {provider}")

    @staticmethod
    async def _google_veo(
        prompt: str, model: str, image_url: Optional[str], keys: dict
    ) -> Dict[str, Any]:
        """Google Veo 3 / Veo 3 Fast."""
        api_key = _google_key(keys)

        endpoint = (
            "veo-3.1-fast-generate-preview:predictLongRunning"
            if model == "veo-3-fast"
            else "veo-3.1-generate-preview:predictLongRunning"
        )
        url = (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"{endpoint}?key={api_key}"
        )
        payload = {"instances": [{"prompt": prompt}]}
        if image_url:
            payload["instances"][0]["file"] = {"file_uri": image_url}

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(url, json=payload)

            if r.status_code != 200:
                raise ProviderError(f"Veo HTTP {r.status_code}: {r.text[:500]}")

            operation_name = r.json().get("name")
            if not operation_name:
                raise ProviderError("Veo returned no operation name")

            status_url = (
                f"https://generativelanguage.googleapis.com/v1beta/"
                f"{operation_name}?key={api_key}"
            )

            for _ in range(60):
                await asyncio.sleep(10)
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(status_url)
                status = r.json()
                if status.get("done"):
                    uri = (
                        status["response"]["generateVideoResponse"]
                               ["generatedSamples"][0]["video"]["uri"]
                    )
                    video_url = f"{uri}{'&' if '?' in uri else '?'}key={api_key}"
                    return {"video_url": video_url, "status": "completed"}
                if status.get("error"):
                    raise ProviderError(f"Veo error: {status['error']}")

            raise ProviderError("Veo timeout after 10 minutes")

        except httpx.TimeoutException:
            raise ProviderError("Veo API timeout")
        except ProviderError:
            raise
        except Exception as exc:
            raise ProviderError(f"Veo error: {exc}")

    # =========================================================================
    # FAL AI KLING v2.1 PRO — image-to-video
    # =========================================================================

    @staticmethod
    async def fal_kling_video_i2v(
        image_b64: str,
        mime_type: str,
        prompt: str,
        aspect_ratio: str = "16:9",
        keys: dict = None,
    ) -> Dict[str, Any]:
        """
        Fal AI Kling v2.1 Pro — image-to-video.

        Flow:
          1. Upload reference frame to Fal storage (initiate → PUT)
          2. Submit to fal-ai/kling-video/v2.1/pro/image-to-video queue
          3. Poll response_url (90 × 6s = 540s max)
          4. Return video_url
        """
        keys   = keys or {}
        fal_key = _fal_key(keys)
        auth   = {"Authorization": f"Key {fal_key}"}
        t0     = time.time()
        binary = base64.b64decode(image_b64)

        # ── Step 1a: Initiate Fal storage upload ─────────────────────────────
        file_name    = "start_frame.jpg" if ("jpeg" in mime_type or "jpg" in mime_type) else "start_frame.png"
        content_type = mime_type

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(
                    "https://rest.alpha.fal.ai/storage/upload/initiate",
                    headers={**auth, "Content-Type": "application/json"},
                    json={"file_name": file_name, "content_type": content_type},
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage initiate timed out")

        if r.status_code != 200:
            raise ProviderError(
                f"Fal storage initiate HTTP {r.status_code}: {r.text[:300]}"
            )

        initiate_data = r.json()
        upload_url    = initiate_data.get("upload_url", "")
        fal_image_url = initiate_data.get("file_url", "")
        if not upload_url or not fal_image_url:
            raise ProviderError(f"Fal storage initiate missing fields: {initiate_data}")

        # ── Step 1b: PUT raw bytes to presigned upload_url ────────────────────
        try:
            async with httpx.AsyncClient(timeout=90.0) as client:
                r = await client.put(
                    upload_url,
                    headers={"Content-Type": content_type},
                    content=binary,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage PUT timed out")

        if r.status_code not in (200, 201, 204):
            raise ProviderError(
                f"Fal storage PUT HTTP {r.status_code}: {r.text[:300]}"
            )

        logger.info(f"Fal Kling i2v storage OK: {fal_image_url}")

        # ── Step 2: Submit to Kling i2v queue ─────────────────────────────────
        aspect_map = {"16:9": "16:9", "9:16": "9:16", "1:1": "1:1", "4:3": "4:3", "3:4": "3:4"}
        fal_aspect = aspect_map.get(aspect_ratio, "16:9")

        submit_payload = {
            "prompt":       prompt,
            "image_url":    fal_image_url,
            "duration":     "5",
            "aspect_ratio": fal_aspect,
        }

        try:
            async with httpx.AsyncClient(timeout=60.0) as client:
                r = await client.post(
                    "https://queue.fal.run/fal-ai/kling-video/v2.1/pro/image-to-video",
                    headers={**auth, "Content-Type": "application/json"},
                    json=submit_payload,
                )
        except httpx.TimeoutException:
            raise ProviderError("Kling i2v queue submit timed out")

        if r.status_code not in (200, 201):
            raise ProviderError(
                f"Kling i2v submit HTTP {r.status_code}: {r.text[:300]}"
            )

        submit_body = r.json()
        request_id  = submit_body.get("request_id", "")
        if not request_id:
            raise ProviderError(f"Kling i2v returned no request_id: {r.text[:200]}")

        response_url = (
            submit_body.get("response_url")
            or f"https://queue.fal.run/fal-ai/kling-video/v2.1/pro/image-to-video/requests/{request_id}"
        )

        logger.info(f"Kling i2v submitted: request_id={request_id}")

        # ── Step 3: Poll response_url — 90 × 6s = 540s max ───────────────────
        for attempt in range(90):
            await asyncio.sleep(6)

            try:
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(response_url, headers=auth)
            except Exception as poll_exc:
                logger.warning(f"Kling i2v poll attempt {attempt} exception: {poll_exc}")
                continue

            logger.info(
                f"Kling i2v poll attempt={attempt} HTTP={r.status_code} "
                f"elapsed={int(time.time()-t0)}s"
            )

            if r.status_code == 200:
                body      = r.json()
                video     = body.get("video", {})
                video_url = video.get("url", "") if isinstance(video, dict) else ""
                if video_url:
                    logger.info(f"Kling i2v DONE: {video_url} in {int(time.time()-t0)}s")
                    return {"video_url": video_url, "latency_ms": int((time.time() - t0) * 1000)}

                state = body.get("status", "").upper()
                if state == "FAILED":
                    err = body.get("error") or body.get("detail") or "Unknown Kling error"
                    raise ProviderError(f"Kling i2v FAILED: {err}")

                logger.info(f"Kling i2v poll attempt={attempt} state={state or 'pending'}")
                continue

            if r.status_code == 202:
                logger.info(f"Kling i2v poll attempt={attempt} still processing (202)")
                continue

            logger.warning(
                f"Kling i2v poll attempt={attempt} HTTP {r.status_code}: {r.text[:200]}"
            )

        elapsed = int(time.time() - t0)
        raise ProviderError(f"Kling i2v timed out after {elapsed}s (90 attempts × 6s)")

    # =========================================================================
    # FAL AI FLUX.1 KONTEXT (selfie image-to-image transformation)
    # =========================================================================

    @staticmethod
    async def fal_flux_kontext(
        image_b64: str,
        mime_type: str,
        prompt: str,
        num_images: int = 2,
        keys: dict = None,
    ) -> Dict[str, Any]:
        """
        Fal AI FLUX.1 Kontext — image-to-image transformation.

        Flow:
          1. Initiate Fal storage upload → get presigned upload_url + file_url
          2. PUT raw bytes to upload_url
          3. Submit job to Fal queue → capture request_id + response_url
          4. Poll response_url directly (15 × 6s = 90s max)
          5. Return image URLs from result
        """
        keys    = keys or {}
        fal_key = _fal_key(keys)
        auth    = {"Authorization": f"Key {fal_key}"}
        t0      = time.time()
        binary  = base64.b64decode(image_b64)

        # ── Step 1a: Initiate storage upload ─────────────────────────────────
        file_name    = "ref.jpg" if ("jpeg" in mime_type or "jpg" in mime_type) else "ref.png"
        content_type = mime_type

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(
                    "https://rest.alpha.fal.ai/storage/upload/initiate",
                    headers={**auth, "Content-Type": "application/json"},
                    json={"file_name": file_name, "content_type": content_type},
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage initiate timed out")

        if r.status_code != 200:
            raise ProviderError(
                f"Fal storage initiate HTTP {r.status_code}: {r.text[:300]}"
            )

        initiate_data = r.json()
        upload_url    = initiate_data.get("upload_url", "")
        fal_image_url = initiate_data.get("file_url", "")
        if not upload_url or not fal_image_url:
            raise ProviderError(f"Fal storage initiate missing fields: {initiate_data}")

        # ── Step 1b: PUT raw bytes to presigned upload_url ────────────────────
        try:
            async with httpx.AsyncClient(timeout=90.0) as client:
                r = await client.put(
                    upload_url,
                    headers={"Content-Type": content_type},
                    content=binary,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage PUT timed out (90s)")

        if r.status_code not in (200, 201, 204):
            raise ProviderError(
                f"Fal storage PUT HTTP {r.status_code}: {r.text[:300]}"
            )

        logger.info(f"Fal storage OK: {fal_image_url}")

        # ── Step 2: Submit job to queue ───────────────────────────────────────
        submit_payload = {
            "prompt":                prompt,
            "image_url":             fal_image_url,
            "num_images":            max(1, min(num_images, 4)),
            "guidance_scale":        2.5,
            "num_inference_steps":   28,
            "output_format":         "jpeg",
            "safety_tolerance":      "2",
            "enable_safety_checker": False,
        }

        try:
            async with httpx.AsyncClient(timeout=60.0) as client:
                r = await client.post(
                    "https://queue.fal.run/fal-ai/flux-pro/kontext",
                    headers={**auth, "Content-Type": "application/json"},
                    json=submit_payload,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal queue submit timed out")

        if r.status_code not in (200, 201):
            raise ProviderError(
                f"Fal queue submit HTTP {r.status_code}: {r.text[:300]}"
            )

        submit_body = r.json()
        request_id  = submit_body.get("request_id", "")
        if not request_id:
            raise ProviderError(f"Fal returned no request_id. Body: {r.text[:200]}")

        # Use response_url from submission body if present; fall back to constructed URL.
        # Do NOT use /status — it returns 405 (method not allowed on this endpoint).
        response_url = (
            submit_body.get("response_url")
            or f"https://queue.fal.run/fal-ai/flux-pro/kontext/requests/{request_id}"
        )

        logger.info(f"Fal job submitted: request_id={request_id} response_url={response_url}")

        # ── Step 3: Poll response_url directly — 15 × 6s = 90s max ──────────
        for attempt in range(15):
            await asyncio.sleep(6)

            try:
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(response_url, headers=auth)
            except Exception as poll_exc:
                logger.warning(f"Fal poll attempt {attempt} exception: {poll_exc}")
                continue

            logger.info(
                f"Fal poll attempt={attempt} HTTP={r.status_code} "
                f"elapsed={int(time.time()-t0)}s"
            )

            if r.status_code == 200:
                body = r.json()

                images = body.get("images", [])
                if images:
                    urls = [img["url"] for img in images if img.get("url")]
                    if urls:
                        logger.info(
                            f"Fal KONTEXT DONE: {len(urls)} image(s) "
                            f"in {int(time.time()-t0)}s"
                        )
                        return {"urls": urls, "latency_ms": int((time.time() - t0) * 1000)}

                state = body.get("status", "").upper()
                if state == "FAILED":
                    err = body.get("error") or body.get("detail") or "Unknown Fal error"
                    raise ProviderError(f"Fal generation FAILED: {err}")

                logger.info(f"Fal poll attempt={attempt} state={state or 'pending'} no images yet")
                continue

            if r.status_code == 202:
                logger.info(f"Fal poll attempt={attempt} still processing (202)")
                continue

            logger.warning(f"Fal poll attempt={attempt} unexpected HTTP {r.status_code}: {r.text[:200]}")

        elapsed = int(time.time() - t0)
        raise ProviderError(f"Fal KONTEXT timed out after {elapsed}s (15 attempts × 6s)")

    # =========================================================================
    # FAL AI NANO BANANA 2 (selfie image-to-image transformation)
    # =========================================================================

    @staticmethod
    async def fal_nano_banana(
        image_b64: str,
        mime_type: str,
        prompt: str,
        keys: dict = None,
    ) -> Dict[str, Any]:
        """
        Fal AI Nano Banana 2 — image-to-image selfie transformation.

        Flow:
          1. Upload image to Fal storage (initiate → PUT)
          2. POST to queue.fal.run/fal-ai/nano-banana-2/edit
          3. Poll response_url from submit body (NOT /status — returns 405)
             15 attempts × 6s = 90s max
          4. Return image URLs from result
        """
        keys    = keys or {}
        fal_key = _fal_key(keys)
        auth    = {"Authorization": f"Key {fal_key}"}
        t0      = time.time()
        binary  = base64.b64decode(image_b64)

        # ── Step 1a: Initiate Fal storage upload ─────────────────────────────
        file_name    = "ref.jpg" if ("jpeg" in mime_type or "jpg" in mime_type) else "ref.png"
        content_type = mime_type

        try:
            async with httpx.AsyncClient(timeout=30.0) as client:
                r = await client.post(
                    "https://rest.alpha.fal.ai/storage/upload/initiate",
                    headers={**auth, "Content-Type": "application/json"},
                    json={"file_name": file_name, "content_type": content_type},
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage initiate timed out")

        if r.status_code != 200:
            raise ProviderError(
                f"Fal storage initiate HTTP {r.status_code}: {r.text[:300]}"
            )

        initiate_data = r.json()
        upload_url    = initiate_data.get("upload_url", "")
        fal_image_url = initiate_data.get("file_url", "")
        if not upload_url or not fal_image_url:
            raise ProviderError(f"Fal storage initiate missing fields: {initiate_data}")

        # ── Step 1b: PUT raw bytes to presigned upload_url ────────────────────
        try:
            async with httpx.AsyncClient(timeout=90.0) as client:
                r = await client.put(
                    upload_url,
                    headers={"Content-Type": content_type},
                    content=binary,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal storage PUT timed out")

        if r.status_code not in (200, 201, 204):
            raise ProviderError(
                f"Fal storage PUT HTTP {r.status_code}: {r.text[:300]}"
            )

        logger.info(f"Fal Nano Banana storage OK: {fal_image_url}")

        # ── Step 2: Submit job to Nano Banana 2 queue ─────────────────────────
        submit_payload = {
            "prompt":       prompt,
            "image_urls":   [fal_image_url],
            "aspect_ratio": "auto",
        }

        nano_model = config.FAL_NANO_BANANA_MODEL

        try:
            async with httpx.AsyncClient(timeout=60.0) as client:
                r = await client.post(
                    f"https://queue.fal.run/{nano_model}",
                    headers={**auth, "Content-Type": "application/json"},
                    json=submit_payload,
                )
        except httpx.TimeoutException:
            raise ProviderError("Fal Nano Banana queue submit timed out")

        if r.status_code not in (200, 201):
            raise ProviderError(
                f"Fal Nano Banana submit HTTP {r.status_code}: {r.text[:300]}"
            )

        submit_body = r.json()
        request_id  = submit_body.get("request_id", "")
        if not request_id:
            raise ProviderError(f"Fal Nano Banana returned no request_id. Body: {r.text[:200]}")

        # Use response_url from submit body. Do NOT poll /status — returns 405.
        response_url = (
            submit_body.get("response_url")
            or f"https://queue.fal.run/{nano_model}/requests/{request_id}"
        )

        logger.info(f"Fal Nano Banana submitted: request_id={request_id}")

        # ── Step 3: Poll response_url — 15 × 6s = 90s max ────────────────────
        for attempt in range(15):
            await asyncio.sleep(6)

            try:
                async with httpx.AsyncClient(timeout=30.0) as client:
                    r = await client.get(response_url, headers=auth)
            except Exception as poll_exc:
                logger.warning(f"Fal Nano Banana poll attempt {attempt} exception: {poll_exc}")
                continue

            logger.info(
                f"Fal Nano Banana poll attempt={attempt} HTTP={r.status_code} "
                f"elapsed={int(time.time()-t0)}s"
            )

            if r.status_code == 200:
                body   = r.json()
                images = body.get("images", [])
                if images:
                    urls = [img["url"] for img in images if img.get("url")]
                    if urls:
                        logger.info(
                            f"Fal Nano Banana DONE: {len(urls)} image(s) "
                            f"in {int(time.time()-t0)}s"
                        )
                        return {"urls": urls, "latency_ms": int((time.time() - t0) * 1000)}

                state = body.get("status", "").upper()
                if state == "FAILED":
                    err = body.get("error") or body.get("detail") or "Unknown Fal error"
                    raise ProviderError(f"Fal Nano Banana FAILED: {err}")

                logger.info(f"Fal Nano Banana poll attempt={attempt} state={state or 'pending'}")
                continue

            if r.status_code == 202:
                logger.info(f"Fal Nano Banana poll attempt={attempt} still processing (202)")
                continue

            logger.warning(
                f"Fal Nano Banana poll attempt={attempt} HTTP {r.status_code}: {r.text[:200]}"
            )

        elapsed = int(time.time() - t0)
        raise ProviderError(f"Fal Nano Banana timed out after {elapsed}s (15 attempts × 6s)")
