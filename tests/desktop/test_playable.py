"""Every delivered MP3/MP4 must be 100% playable: damaged or incomplete files are rejected and never reach the user."""
from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path

import pytest

from app.core.errors import ProcessingFailedError
from app.core.media_downloader import MSG_DAMAGED, check_decode, check_probe, decode_timeout, probe_duration

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg required")


def url(vid: str) -> str:
    return f"https://www.youtube.com/watch?v={vid}"


def full_decode_errors(path: Path) -> str:
    """Independent check: decode the whole file and return everything ffmpeg complains about."""
    result = subprocess.run(["ffmpeg", "-nostdin", "-v", "error", "-xerror", "-i", str(path), "-f", "null", "-"], capture_output=True, text=True)
    return (result.stderr + ("" if result.returncode == 0 else f" exit={result.returncode}")).strip()


@pytest.mark.parametrize("fmt", ["mp4", "mp3"])
def test_delivered_files_decode_cleanly_to_the_end(service, tmp_path, fmt):
    info = service.analyze(url("okplayable1"))
    path = service.download(info, fmt, "best", 192, tmp_path / "out")
    assert full_decode_errors(path) == ""
    probe = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "json", str(path)], capture_output=True, text=True)
    assert abs(float(json.loads(probe.stdout)["format"]["duration"]) - 30) <= 1.5


@pytest.mark.parametrize("vid,fmt", [
    ("truncvideo1", "mp4"), ("truncvideo2", "mp3"),      # cut off in the middle
    ("junkvideo01", "mp4"), ("junkvideo02", "mp3"),      # not media at all
    ("bitrotvid01", "mp4"),                              # corrupted payload
    ("shortvideo1", "mp3"), ("shortvideo2", "mp4"),      # valid but much shorter than announced
])
def test_damaged_or_incomplete_files_are_discarded(service, tmp_path, vid, fmt):
    info = service.analyze(url(vid))
    out = tmp_path / "out"
    with pytest.raises(ProcessingFailedError) as e:
        service.download(info, fmt, "best", 192, out)
    assert e.value.message == MSG_DAMAGED
    assert e.value.detail, "the technical reason is kept for the log"
    assert not list(out.glob("*")), "nothing reaches the output folder"
    assert list((tmp_path / "scratch").glob("*")) == [], "scratch removed"


# ------------------------------------------------------------------ pure checks (same rules as the web backend)
def probe(name, duration, streams):
    return json.dumps({"format": {"format_name": name, **({"duration": str(duration)} if duration is not None else {})}, "streams": streams})


V = {"codec_type": "video", "codec_name": "h264"}
A = {"codec_type": "audio", "codec_name": "aac"}
M = {"codec_type": "audio", "codec_name": "mp3"}


def test_good_probe_results_pass():
    assert check_probe(probe("mov,mp4,m4a,3gp,3g2,mj2", 213.1, [V, A]), "mp4", 213) is None
    assert check_probe(probe("mp3", 213.0, [M]), "mp3", 214) is None
    assert check_probe(probe("mp3", 213.0, [M]), "mp3", None) is None


@pytest.mark.parametrize("raw,fmt,expected", [
    ("garbage", "mp4", 10), ("{}", "mp3", 10),
    (probe("matroska,webm", 10.0, [V]), "mp4", 10),
    (probe("mov,mp4", 10.0, [A]), "mp4", 10),
    (probe("mp3", 10.0, []), "mp3", 10),
    (probe("wav", 10.0, [M]), "mp3", 10),
    (probe("mp3", 0.0, [M]), "mp3", None),
    (probe("mp3", None, [M]), "mp3", None),
    (probe("mp3", 100.0, [M]), "mp3", 200),
    (probe("mov,mp4", 400.0, [V]), "mp4", 200),
    (probe("mov,mp4", 10.0, [{"codec_type": "video"}]), "mp4", 10),
])
def test_bad_probe_results_fail(raw, fmt, expected):
    assert check_probe(raw, fmt, expected)


def test_duration_tolerance_and_decode_length():
    assert check_probe(probe("mp3", 205.0, [M]), "mp3", 200) is None
    assert check_probe(probe("mp3", 188.0, [M]), "mp3", 200)
    assert check_probe(probe("mp3", 41.0, [M]), "mp3", 40) is None
    assert check_decode("out_time_us=1000000\nout_time_us=213000000\nprogress=end\n", 213.0) is None
    assert check_decode("out_time_ms=212000000\nprogress=end\n", 213.0) is None
    assert check_decode("out_time_us=18000000\nprogress=end\n", 30.0), "header promises 30 s but only 18 s decode"
    assert check_decode("progress=end\n", 30.0), "no progress output at all"
    assert probe_duration('{"format":{"duration":"213.000"}}') == 213.0 and probe_duration("nope") == 0.0
    assert decode_timeout(None) == 300 and decode_timeout(14400) == 14400 * 2 + 120


def test_cancel_during_verification_stops_the_checker(service, engine_parts, tmp_path):
    """Cancel must also kill ffprobe/ffmpeg of the verification step (no orphans)."""
    import threading
    import time

    from app.core.errors import CancelledError

    engine, pm = engine_parts
    info = service.analyze(url("okplayable2"))
    original = engine._run_collect
    started = threading.Event()

    def slow_collect(key, cmd, timeout):
        started.set()
        time.sleep(0.6)  # widen the window in which Cancel can arrive during verification
        return original(key, cmd, timeout)

    engine._run_collect = slow_collect
    result = {}

    def run():
        try:
            service.download(info, "mp3", "best", 192, tmp_path / "out", job_id="job-v")
        except Exception as exc:  # noqa: BLE001
            result["exc"] = exc

    t = threading.Thread(target=run)
    t.start()
    # the first slow_collect call may be the analysis? no: analysis uses _run_info; this is verification
    assert started.wait(30)
    service.cancel("job-v")
    t.join(30)
    assert isinstance(result.get("exc"), CancelledError)
    assert not (tmp_path / "out").exists() or not list((tmp_path / "out").glob("*"))
