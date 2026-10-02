"""Structured, sanitised file logging in %APPDATA%\\YouTubeDownloader\\logs (JSON lines, rotated)."""
from __future__ import annotations

import json
import logging
import logging.handlers
import re
from datetime import datetime, timezone
from pathlib import Path

from . import paths

_CONTROL = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")
_SECRET = re.compile(
    r"(cookie|set-cookie|authorization|bearer|token|passwd|password|secret|api[_-]?key|po_token)(\W*[:=]\W*|\s+)(?:(?:bearer|basic)\s+)?[^\s,;|]+",
    re.I,
)
_QUERY_SECRET = re.compile(r"([?&](?:key|token|signature|sig|sparams|cookie|auth)[^=&\s]*=)[^&\s]+", re.I)
_MAX = 500


def sanitize(text: str) -> str:
    """Strip control characters, redact credentials and truncate - applied to every log message."""
    text = _CONTROL.sub("", str(text))
    text = re.sub(r"\r?\n", " | ", text)
    text = _SECRET.sub(r"\1=[redacted]", text)
    text = _QUERY_SECRET.sub(r"\1[redacted]", text)
    return text[:_MAX] + "..." if len(text) > _MAX else text


class _JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        entry = {
            "ts": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "level": record.levelname.lower(),
            "job_id": getattr(record, "job_id", None),
            "op": getattr(record, "op", None),
            "status": getattr(record, "status", None),
            "duration_ms": getattr(record, "duration_ms", None),
            "error_code": getattr(record, "error_code", None),
            "message": sanitize(record.getMessage()),
        }
        return json.dumps(entry, ensure_ascii=False)


def setup_logging(log_dir: Path | None = None) -> Path:
    """Configure the 'ytd' logger tree once; returns the log file path."""
    directory = log_dir or (paths.data_dir() / "logs")
    directory.mkdir(parents=True, exist_ok=True)
    logfile = directory / "app.log"
    root = logging.getLogger("ytd")
    root.setLevel(logging.INFO)
    root.propagate = False
    for handler in list(root.handlers):
        root.removeHandler(handler)
        handler.close()
    handler = logging.handlers.RotatingFileHandler(logfile, maxBytes=1_000_000, backupCount=5, encoding="utf-8")
    handler.setFormatter(_JsonFormatter())
    root.addHandler(handler)
    return logfile


def event(logger: logging.Logger, level: int, message: str, **context: object) -> None:
    """Log with the structured fields (job_id, op, status, duration_ms, error_code)."""
    logger.log(level, message, extra=context)
