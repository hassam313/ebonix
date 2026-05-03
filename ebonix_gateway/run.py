"""
Ebonix Gateway - Entry Point
"""
import sys
import logging
import uvicorn
from config import config

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    stream=sys.stdout,
    force=True
)

logger = logging.getLogger(__name__)

if __name__ == "__main__":
    print("=" * 80, flush=True)
    print("🚀 EBONIX GATEWAY STARTING", flush=True)
    print("=" * 80, flush=True)
    print(f"Host: {config.HOST}:{config.PORT}", flush=True)
    print("API keys: loaded per-request from admin panel (not stored in gateway)", flush=True)
    print("=" * 80, flush=True)
    print("", flush=True)

    try:
        uvicorn.run(
            "main:app",
            host=config.HOST,
            port=config.PORT,
            reload=False,
            log_level="info",
            access_log=True
        )
    except Exception as e:
        print(f"❌ ERROR: {e}", flush=True)
        sys.exit(1)
