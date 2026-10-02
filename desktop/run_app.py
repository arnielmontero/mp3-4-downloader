"""PyInstaller / developer entry point: `python desktop/run_app.py`."""
import sys
from pathlib import Path

# allow running from a source checkout without installing the package
sys.path.insert(0, str(Path(__file__).resolve().parent))

from app.main import main  # noqa: E402

if __name__ == "__main__":
    raise SystemExit(main())
