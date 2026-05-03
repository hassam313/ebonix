"""
Ebonix Gateway Configuration

API KEYS are NOT stored here. They live in the admin panel DB (qa_options table)
and are passed per-request by PHP. The gateway never touches a key file.

Only the gateway auth token lives in .env — it protects the gateway endpoint itself.
"""
import os
from pathlib import Path
from dotenv import load_dotenv

_env_path = Path(__file__).resolve().parent.parent / '.env'
load_dotenv(dotenv_path=_env_path, override=False)


def _require(key: str) -> str:
    val = os.environ.get(key, '').strip()
    if not val:
        raise RuntimeError(
            f"\n\n[EBONIX CONFIG ERROR] Required environment variable missing: {key}\n"
            f"Add it to your .env file at: {_env_path}\n"
        )
    return val


class Config:
    # ── Server ────────────────────────────────────────────────────────────────
    HOST = "0.0.0.0"
    PORT = 8001

    # ── Gateway auth token ────────────────────────────────────────────────────
    # Must match the token PHP sends in: Authorization: Bearer <token>
    GATEWAY_AUTH_TOKEN = _require('GATEWAY_AUTH_TOKEN')

    # ── Fal model identifiers (non-secret, safe to have here) ─────────────────
    FAL_KONTEXT_MODEL    = "fal-ai/flux-pro/kontext"
    FAL_NANO_BANANA_MODEL = os.environ.get('FAL_NANO_BANANA_MODEL', 'fal-ai/nano-banana-2/edit')

    # ── Timeouts (seconds) ────────────────────────────────────────────────────
    IMAGE_TIMEOUT        = 180.0
    VIDEO_CREATE_TIMEOUT = 60.0
    VIDEO_TOTAL_TIMEOUT  = 300.0


config = Config()
