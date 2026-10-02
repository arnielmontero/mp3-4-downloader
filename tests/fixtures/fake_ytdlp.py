"""Deterministic stand-in for yt-dlp used by the desktop tests (same conventions as fake-yt-dlp, PHP).

Behaviour is chosen by the video id prefix:  slow* (long download + child process), fail*, priv*, live*,
weird* (title with illegal filename characters), unsz* (size unknown, endless), other = fast.
Invocation log: $FAKE_YTDLP_LOG (JSON lines) when set.  Uses the real ffmpeg found on PATH / FFMPEG_EXE.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import time

args = sys.argv[1:]
log_path = os.environ.get("FAKE_YTDLP_LOG")
if log_path:
    with open(log_path, "a", encoding="utf-8") as fh:
        fh.write(json.dumps({"pid": os.getpid(), "args": args}) + "\n")

if "--version" in args:
    print("2099.01.01")
    sys.exit(0)

url = args[-1]
m = re.search(r"v=([A-Za-z0-9_-]{11})", url)
vid = m.group(1) if m else "unknownvid1"


def arg(name: str):
    return args[args.index(name) + 1] if name in args else None


if "--flat-playlist" in args:  # search: "ytsearchN:query"
    m2 = re.match(r"ytsearch(\d+):(.*)$", url, re.S)
    if not m2:
        print("ERROR: unsupported URL", file=sys.stderr)
        sys.exit(1)
    if "failsearch" in m2.group(2):
        print("ERROR: Unable to download webpage: <urlopen error [Errno -3] Temporary failure in name resolution>", file=sys.stderr)
        sys.exit(1)
    ids = [] if "nothingfound" in m2.group(2) else ["okvideo0001", "okvideo0002", "okvideo0003", "slowvideo01", "livevideo01"]
    for n, rid in enumerate(ids[: int(m2.group(1))]):
        print("YTDS " + json.dumps({
            "id": rid, "title": f"Result {n + 1} for {m2.group(2)}", "uploader": "Fake Channel", "channel": "Fake Channel",
            "duration": 180 + n, "live_status": "is_live" if rid.startswith("live") else "not_live",
        }))
    sys.exit(0)

if "--print" in args:
    if vid.startswith("priv"):
        print(f"ERROR: [youtube] {vid}: Private video. Sign in if you've been granted access to this video", file=sys.stderr)
        sys.exit(1)
    if vid.startswith("net"):
        print("ERROR: Unable to download webpage: <urlopen error [Errno -3] Temporary failure in name resolution>", file=sys.stderr)
        sys.exit(1)
    title = "My Video: Episode 1 / Test?" if vid.startswith("weird") else f"Fake video {vid}"
    info = {
        "id": vid, "title": title, "uploader": "Fake Channel", "channel": "Fake Channel",
        "duration": 60 if vid.startswith("slow") else 30,
        "thumbnail": f"https://i.ytimg.com/vi/{vid}/hqdefault.jpg",
        "is_live": vid.startswith("live"), "live_status": "is_live" if vid.startswith("live") else "not_live",
        "webpage_url": f"https://www.youtube.com/watch?v={vid}",
        "filesize": None if vid.startswith("unsz") else 1024 * 1024, "filesize_approx": None,
    }
    formats = [{"format_id": "140", "ext": "m4a", "height": None, "vcodec": "none", "acodec": "mp4a.40.2", "abr": 129.5, "filesize": 500000}]
    for h in (144, 240, 360, 480, 720, 1080):
        formats.append({"format_id": str(h), "ext": "mp4", "height": h, "vcodec": "avc1", "acodec": "none", "abr": 0, "filesize": h * 4000})
    print("YTDINFO " + json.dumps(info))
    print("YTDFMT " + json.dumps(formats))
    sys.exit(0)

# ----------------------------------------------------------------------------- download
if vid.startswith("fail"):
    print("ERROR: unable to download video data: HTTP Error 403: Forbidden", file=sys.stderr)
    sys.exit(1)

template = arg("-o")
out_dir = os.path.dirname(template)
is_mp3 = "-x" in args
final = os.path.join(out_dir, f"{vid}.{'mp3' if is_mp3 else 'mp4'}")
partial = os.path.join(out_dir, f"{vid}.f137.mp4.part")

child = None
if vid.startswith("slow"):
    child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(120)"])  # stands in for FFmpeg

total = 1024 * 1024
steps = 120 if vid.startswith("slow") else 6
with open(partial, "wb") as fh:
    for i in range(1, steps + 1):
        fh.write(b"x" * (total // steps))
        fh.flush()
        print(f"YTDP|downloading|{total * i // steps}|{total}|NA|2097152|1|{os.path.basename(partial)}", flush=True)
        time.sleep(0.25)
print(f"YTDP|finished|{total}|{total}|NA|NA|NA|{os.path.basename(partial)}", flush=True)
os.unlink(partial)
print("[Merger] Merging formats", flush=True)

ffmpeg = os.environ.get("FFMPEG_EXE") or shutil.which("ffmpeg") or "ffmpeg"
if is_mp3:
    kbps = (arg("--audio-quality") or "192K").rstrip("K")
    cmd = [ffmpeg, "-y", "-loglevel", "error", "-f", "lavfi", "-i", "sine=frequency=440:duration=2", "-b:a", f"{kbps}k",
           "-metadata", f"title=Fake video {vid}", "-metadata", "artist=Fake Channel", final]
else:
    cmd = [ffmpeg, "-y", "-loglevel", "error", "-f", "lavfi", "-i", "testsrc=size=320x240:rate=15:duration=2", "-f", "lavfi",
           "-i", "sine=frequency=440:duration=2", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "aac", "-shortest", final]
result = subprocess.run(cmd, capture_output=True, text=True)
if result.returncode != 0:
    print("ERROR: Postprocessing: ffmpeg exited:", result.stderr, file=sys.stderr)
    sys.exit(1)
sys.exit(0)
