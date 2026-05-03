"""
Ebonix Gateway - Main FastAPI Application
"""
from fastapi import FastAPI, HTTPException, Depends, Header, Request
from contextlib import asynccontextmanager
from typing import Dict, Any
import logging
import sys

from config import config
from models import ModelRouter, ProviderError
from representation import representation_engine

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("=" * 80)
    logger.info("🚀 EBONIX GATEWAY STARTED")
    logger.info(f"Host: {config.HOST}  Port: {config.PORT}")
    logger.info("API keys are supplied per-request from admin panel (no keys stored in gateway)")
    logger.info("=" * 80)
    yield
    logger.info("🛑 Gateway shutting down")


app = FastAPI(title="Ebonix Gateway", version="1.0.0", lifespan=lifespan)


@app.middleware("http")
async def log_requests(request: Request, call_next):
    logger.info(f"📥 {request.method} {request.url.path}")
    response = await call_next(request)
    logger.info(f"📤 {response.status_code}")
    return response


async def verify_token(authorization: str = Header(None)):
    if not authorization:
        raise HTTPException(status_code=401, detail="Missing authorization header")
    token = authorization.replace("Bearer ", "").strip()
    if token != config.GATEWAY_AUTH_TOKEN:
        raise HTTPException(status_code=403, detail="Invalid token")
    return token


def _extract_keys(request: dict) -> dict:
    """Pull API keys from the request payload (sent by PHP from admin panel DB)."""
    return {
        "fal_api_key":    (request.get("fal_api_key")    or "").strip(),
        "google_api_key": (request.get("google_api_key") or "").strip(),
        "luma_api_key":   (request.get("luma_api_key")   or "").strip(),
        "decart_api_key": (request.get("decart_api_key") or "").strip(),
        "openai_api_key": (request.get("openai_api_key") or "").strip(),
    }


# ─────────────────────────────────────────────────────────────────────────────
# HEALTH / ROOT
# ─────────────────────────────────────────────────────────────────────────────

@app.get("/")
async def root():
    return {
        "gateway": "Ebonix AI Gateway",
        "version": "1.0.0",
        "status":  "running",
    }


@app.get("/health")
async def health():
    return {
        "status":  "ok",
        "message": "API keys are configured in Admin → AI Settings, not stored in gateway",
    }


# ─────────────────────────────────────────────────────────────────────────────
# TEXT-TO-IMAGE
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/generate")
async def generate_image(request: Dict[str, Any], token: str = Depends(verify_token)):
    logger.info("🖼️  IMAGE GENERATION REQUEST")
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="prompt required")

        keys = _extract_keys(request)
        if not keys["google_api_key"]:
            return {"success": False, "error": "Google API key not configured — add it in Admin → AI Settings (Ebonix Images 2.0 API Key)"}

        rules           = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt = representation_engine.apply_rules(prompt, rules)
        logger.info(f"Enhanced: {enhanced_prompt[:80]}")

        result    = await ModelRouter.generate_image("google", "imagen-4", enhanced_prompt, request.get("size", "1024x1024"), keys=keys)
        image_url = result.get("url") or f"data:image/webp;base64,{result.get('base64', '')}"

        return {
            "success":         True,
            "image_url":       image_url,
            "enhanced_prompt": enhanced_prompt,
            "latency_ms":      result.get("latency_ms", 0),
        }

    except HTTPException:
        raise
    except ProviderError as exc:
        logger.error(f"❌ Provider: {exc}")
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# VIDEO GENERATION
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/generate_video")
async def generate_video(request: Dict[str, Any], token: str = Depends(verify_token)):
    logger.info("🎬 VIDEO GENERATION REQUEST")
    try:
        prompt = request.get("prompt", "").strip()
        if not prompt:
            raise HTTPException(status_code=400, detail="prompt required")

        keys = _extract_keys(request)
        if not keys["google_api_key"]:
            return {"success": False, "error": "Google API key not configured — add it in Admin → AI Settings (Ebonix Images 2.0 API Key)"}

        rules           = request.get("representation_rules", {"default_representation": "diverse_black"})
        enhanced_prompt = representation_engine.apply_rules(prompt, rules)

        model = request.get("model", "veo3f")
        provider_map = {"veo3": ("google", "veo-3"), "veo3f": ("google", "veo-3-fast")}
        if model not in provider_map:
            model = "veo3f"
        provider, model_name = provider_map[model]

        result   = await ModelRouter.generate_video(provider, model_name, enhanced_prompt,
                       request.get("aspect_ratio", "16:9"), request.get("resolution", "540p"),
                       request.get("image_url"), keys=keys)
        response = {"success": True, "enhanced_prompt": enhanced_prompt}
        if "video_url" in result:
            response.update({"video_url": result["video_url"], "status": "completed"})
        elif "job_id" in result:
            response.update({"job_id": result["job_id"], "status": "processing"})
        return response

    except HTTPException:
        raise
    except ProviderError as exc:
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# IMAGE-TO-VIDEO (Fal AI Kling — animates a reference image)
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/image_to_video")
async def image_to_video(request: Dict[str, Any], token: str = Depends(verify_token)):
    """
    Image-to-video using Fal AI Kling v2.1 Pro.
    Accepts image_b64 (base64, no data URI prefix), prompt, aspect_ratio.
    Uploads reference frame to Fal storage, then submits Kling i2v job.
    """
    logger.info("=" * 80)
    logger.info("🎬 IMAGE-TO-VIDEO REQUEST (Kling)")
    logger.info("=" * 80)

    try:
        image_b64    = request.get("image_b64", "").strip()
        mime_type    = request.get("mime_type", "image/jpeg")
        prompt       = request.get("prompt", "").strip()
        aspect_ratio = request.get("aspect_ratio", "16:9")

        if not image_b64:
            raise HTTPException(status_code=400, detail="image_b64 is required")
        if not prompt:
            raise HTTPException(status_code=400, detail="prompt required")

        keys = _extract_keys(request)
        if not keys["fal_api_key"]:
            return {"success": False, "error": "Fal API key not configured — add it in Admin → AI Settings (KingStudio Api Key)"}

        logger.info(f"Prompt (first 80): {prompt[:80]} | Aspect: {aspect_ratio}")

        result = await ModelRouter.fal_kling_video_i2v(image_b64, mime_type, prompt, aspect_ratio, keys=keys)

        return {
            "success":   True,
            "video_url": result["video_url"],
            "status":    "completed",
        }

    except HTTPException:
        raise
    except ProviderError as exc:
        logger.error(f"❌ Kling i2v error: {exc}")
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Image-to-video error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# SELFIE TRANSFORMATION
# ─────────────────────────────────────────────────────────────────────────────

@app.post("/transform_selfie")
async def transform_selfie(request: Dict[str, Any], token: str = Depends(verify_token)):
    """
    Selfie transformation pipeline:
      1. Gemini Vision detects if person is Black / African-descent
      2. Black  → protection prompt (preserve melanin, hair, features)
         Other  → style-only prompt (NEVER touch skin tone or race)
      3. Fal AI Nano Banana 2 performs the transformation
      4. Returns image_urls array
    """
    logger.info("=" * 80)
    logger.info("📸 SELFIE TRANSFORMATION REQUEST")
    logger.info("=" * 80)

    try:
        image_b64         = request.get("image_b64", "").strip()
        mime_type         = request.get("mime_type", "image/jpeg")
        style_preset      = request.get("style_preset", "selfie_soft_glam")
        additional_prompt = request.get("additional_prompt", "").strip()

        if not image_b64:
            raise HTTPException(status_code=400, detail="image_b64 is required")

        keys = _extract_keys(request)
        if not keys["fal_api_key"]:
            return {"success": False, "error": "Fal API key not configured — add it in Admin → AI Settings (KingStudio Api Key)"}

        logger.info(f"Style: {style_preset} | Mime: {mime_type} | Extra: {additional_prompt[:50]}")

        # ── Step 1: Detect person ────────────────────────────────────────────
        logger.info("🔍 Running person detection via Gemini Vision...")
        detection  = await representation_engine.detect_person(image_b64, mime_type, keys["google_api_key"])
        is_black   = detection.get("is_black", False)
        confidence = detection.get("confidence", "unknown")
        logger.info(f"Detection result: is_black={is_black}  confidence={confidence}")

        # ── Step 2: Build appropriate prompt ────────────────────────────────
        if is_black:
            logger.info("✊ Black person detected → applying PROTECTION rules")
            prompt = representation_engine.build_black_protection_prompt(
                style_preset, additional_prompt
            )
        else:
            logger.info("🎨 Non-Black person → applying STYLE-ONLY rules")
            prompt = representation_engine.build_style_only_prompt(
                style_preset, additional_prompt
            )

        logger.info(f"📝 Prompt (first 120 chars): {prompt[:120]}")

        # ── Step 3: Transform via Fal Nano Banana 2 ─────────────────────────
        logger.info("🚀 Sending to Fal AI Nano Banana 2...")
        result = await ModelRouter.fal_nano_banana(image_b64, mime_type, prompt, keys=keys)

        urls = result.get("urls", [])
        logger.info(f"✅ SELFIE DONE — {len(urls)} image(s) returned")

        return {
            "success":        True,
            "image_urls":     urls,
            "detected_black": is_black,
            "prompt_used":    prompt,
        }

    except HTTPException:
        raise
    except ProviderError as exc:
        logger.error(f"❌ Fal error: {exc}")
        return {"success": False, "error": str(exc)}
    except Exception as exc:
        logger.error(f"❌ Selfie error: {exc}", exc_info=True)
        return {"success": False, "error": str(exc)}


# ─────────────────────────────────────────────────────────────────────────────
# LAUNCH
# ─────────────────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=config.HOST, port=config.PORT, log_level="info")
