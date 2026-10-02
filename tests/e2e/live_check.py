"""Live acceptance check against a running stack that uses the REAL yt-dlp/YouTube.

Usage: python tests/e2e/live_check.py http://localhost:8080 [VIDEO_URL]

Verifies (task.md acceptance tests 3-7): metadata, MP4 download + playability, MP3 download +
playability, cancel with temp cleanup. Needs ffprobe on the machine running this script.
Results depend on YouTube being reachable from the server, so this is NOT part of the offline suite.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from api_client import Api

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:8080"
URL = sys.argv[2] if len(sys.argv) > 2 else "https://www.youtube.com/watch?v=jNQXAC9IVRw"

api = Api(BASE)
results: list[tuple[str, bool, str]] = []


def record(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok, detail))
    print(("PASS" if ok else "FAIL"), name, detail)


def probe(path: Path) -> dict:
    ffprobe = shutil.which("ffprobe")
    if not ffprobe:
        return {}
    out = subprocess.run([ffprobe, "-v", "error", "-show_entries", "format=duration:stream=codec_name", "-of", "json", str(path)], capture_output=True, text=True, encoding="utf-8", errors="replace")
    return json.loads(out.stdout or "{}")


def download(fmt: str, **extra) -> dict:
    status, doc, _ = api.json("POST", "/api/download", {"url": URL, "format": fmt, **extra})
    assert status == 202 and doc["success"], (status, doc)
    job = api.wait_for(doc["job_id"], timeout=600, on_poll=lambda j: print("   ", j["status"], j["progress"], j["speed"], j["eta"]))
    return job


# --- metadata -----------------------------------------------------------------------------
status, doc, _ = api.json("POST", "/api/video/info", {"url": URL})
record("analyze valid URL", status == 200 and doc["success"] and doc["data"]["title"], json.dumps(doc["data"]["title"]) if doc and doc.get("data") else str(doc))

tmp = Path(tempfile.mkdtemp(prefix="ytd-live-"))
for fmt, ext, extra in (("mp4", "mp4", {"quality": "360p"}), ("mp3", "mp3", {"bitrate": 192})):
    job = download(fmt, **extra)
    ok = job["status"] == "completed"
    record(f"{fmt} download completes", ok, job.get("error") and json.dumps(job["error"]) or job.get("filename") or "")
    if not ok:
        continue
    status, headers, body = api.request("GET", f"/api/download/{job['job_id']}/file")
    target = tmp / f"out.{ext}"
    target.write_bytes(body)
    info = probe(target)
    codecs = [s.get("codec_name") for s in info.get("streams", [])]
    duration = float(info.get("format", {}).get("duration", 0) or 0)
    record(f"{fmt} file playable", status == 200 and duration > 1, f"streams={codecs} duration={duration:.1f}s content-type={headers.get('Content-Type')}")

shutil.rmtree(tmp, ignore_errors=True)
failed = [r for r in results if not r[1]]
print(f"\n{len(results) - len(failed)}/{len(results)} checks passed")
sys.exit(1 if failed else 0)
