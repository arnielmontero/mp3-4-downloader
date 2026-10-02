"""Integration tests: real subprocess handling + real FFmpeg, with the fake yt-dlp (no YouTube needed)."""
from __future__ import annotations

import json
import shutil
import subprocess
import threading
import time
from pathlib import Path

import pytest

from app.core.errors import (
    CancelledError,
    DownloadFailedError,
    InvalidUrlError,
    NetworkError,
    OutputFolderError,
    UnsupportedDomainError,
    VideoUnavailableError,
)
from app.core.media_downloader import Progress
from conftest import process_alive_with

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg required for integration tests")


def url(vid: str) -> str:
    return f"https://www.youtube.com/watch?v={vid}"


def probe(path: Path) -> dict:
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration,bit_rate:stream=codec_name:format_tags=title,artist", "-of", "json", str(path)],
        capture_output=True, text=True, encoding="utf-8",
    ).stdout
    return json.loads(out)


def calls(call_log: Path) -> list[dict]:
    return [json.loads(line) for line in call_log.read_text(encoding="utf-8").splitlines()] if call_log.exists() else []


# ------------------------------------------------------------------ analyze
def test_analyze_valid_url_returns_metadata(service):
    info = service.analyze("https://youtu.be/okvideo0001")
    assert info.title == "Fake video okvideo0001" and info.uploader == "Fake Channel"
    assert info.duration == 30 and info.duration_formatted == "0:30"
    assert info.qualities == ["best", "1080p", "720p", "480p", "360p", "240p", "144p"]
    assert info.has_video and info.has_audio and info.thumbnail.startswith("https://i.ytimg.com/")


def test_analyze_downloads_nothing_and_rejects_before_spawning(service, call_log, tmp_path):
    service.analyze(url("okvideo0001"))
    assert all("-o" not in c["args"] for c in calls(call_log))
    before = len(calls(call_log))
    for bad in ("", "nonsense", "http://localhost/", "file:///etc/passwd", "https://youtu.be/dQw4w9WgXcQ;id"):
        with pytest.raises((InvalidUrlError, UnsupportedDomainError)):
            service.analyze(bad)
    assert len(calls(call_log)) == before, "invalid input must never start yt-dlp"


def test_analyze_unavailable_live_and_network_errors(service):
    with pytest.raises(VideoUnavailableError) as e:
        service.analyze(url("privvideo01"))
    assert e.value.message == "The requested video could not be downloaded."
    with pytest.raises(Exception) as live:
        service.analyze(url("livevideo01"))
    assert "Live" in str(live.value)
    with pytest.raises(NetworkError) as net:
        service.analyze(url("netvideo001"))
    assert net.value.message == "Unable to connect. Please check your internet connection."


# ------------------------------------------------------------------ downloads
def test_mp4_download_into_output_folder_with_safe_name(service, tmp_path, call_log):
    info = service.analyze(url("weirdtitle1"))
    out = tmp_path / "out"
    seen: list[Progress] = []
    path = service.download(info, "mp4", "720p", 192, out, on_progress=seen.append)
    assert path.parent == out and path.name == "My Video - Episode 1 - Test.mp4"
    assert any(c["args"].count("-S") and "res:720," in c["args"][c["args"].index("-S") + 1] for c in calls(call_log))
    codecs = [s["codec_name"] for s in probe(path)["streams"]]
    assert "h264" in codecs and "aac" in codecs
    assert not (tmp_path / "scratch" / "x").exists()
    assert list((tmp_path / "scratch").glob("*")) == [], "scratch folder removed"
    percents = [p.percent for p in seen if p.percent is not None]
    assert percents == sorted(percents) and any(0 < p < 100 for p in percents), "real intermediate progress, monotonic"
    assert seen[-1].stage == "finished" and seen[-1].percent == 100.0


def test_mp3_download_uses_requested_bitrate_and_metadata(service, tmp_path, call_log):
    info = service.analyze(url("okmp3video1"))
    path = service.download(info, "mp3", "best", 320, tmp_path / "out")
    assert path.suffix == ".mp3"
    meta = probe(path)
    assert meta["streams"][0]["codec_name"] == "mp3"
    tags = {k.lower(): v for k, v in meta["format"].get("tags", {}).items()}
    assert tags["title"] == "Fake video okmp3video1" and tags["artist"] == "Fake Channel"
    assert any("320K" in c["args"] for c in calls(call_log))
    assert 280_000 < int(meta["format"]["bit_rate"]) < 340_000


def test_second_download_of_same_title_gets_a_unique_name(service, tmp_path):
    info = service.analyze(url("okvideo0002"))
    out = tmp_path / "out"
    first = service.download(info, "mp3", "best", 128, out)
    second = service.download(info, "mp3", "best", 128, out)
    assert first.name == "Fake video okvideo0002.mp3" and second.name == "Fake video okvideo0002 (1).mp3"
    assert first.exists() and second.exists()


def test_failed_download_maps_to_friendly_error_and_cleans_scratch(service, tmp_path):
    info = service.analyze(url("failvideo01"))
    with pytest.raises(DownloadFailedError) as e:
        service.download(info, "mp4", "best", 192, tmp_path / "out")
    assert e.value.message == "The requested video could not be downloaded."
    assert "403" in e.value.detail and "403" not in e.value.message  # raw text only for the log
    assert list((tmp_path / "scratch").glob("*")) == []
    assert not list((tmp_path / "out").glob("*"))


def test_unwritable_output_folder_is_reported_before_downloading(service, tmp_path, call_log):
    info = service.analyze(url("okvideo0003"))
    blocker = tmp_path / "file"
    blocker.write_text("x")
    before = len(calls(call_log))
    with pytest.raises(OutputFolderError) as e:
        service.download(info, "mp4", "best", 192, blocker / "sub")
    assert e.value.message == "The selected output folder is not writable."
    assert len(calls(call_log)) == before


def test_invalid_parameters_are_rejected(service, tmp_path):
    info = service.analyze(url("okvideo0004"))
    for fmt, quality, bitrate in (("avi", "best", 192), ("mp4", "9999p", 192), ("mp3", "best", 999)):
        with pytest.raises(Exception):
            service.download(info, fmt, quality, bitrate, tmp_path / "out")


# ------------------------------------------------------------------ cancel
def test_cancel_stops_whole_process_tree_and_cleans_up(service, engine_parts, tmp_path):
    engine, pm = engine_parts
    info = service.analyze(url("slowvideo01"))
    job = "job-cancel-1"
    result: dict = {}

    def run():
        try:
            service.download(info, "mp4", "best", 192, tmp_path / "out", job_id=job)
        except Exception as exc:  # noqa: BLE001
            result["exc"] = exc

    t = threading.Thread(target=run)
    t.start()
    deadline = time.time() + 20
    while time.time() < deadline and not (engine.get_progress(job) and engine.get_progress(job).stage == "downloading" and engine.get_progress(job).downloaded_bytes > 0):
        time.sleep(0.1)
    assert engine.get_progress(job).stage == "downloading"
    assert pm.is_running(job)
    assert any("sleep(120)" in line for line in process_alive_with("sleep(120)")), "child (FFmpeg stand-in) running"

    started = time.time()
    service.cancel(job)
    t.join(15)
    assert not t.is_alive() and isinstance(result.get("exc"), CancelledError)
    assert time.time() - started < 10
    time.sleep(0.5)
    assert not pm.is_running(job)
    assert not [l for l in process_alive_with("sleep(120)")], "no orphaned child process after cancel"
    assert list((tmp_path / "scratch").glob("*")) == [], "incomplete temp data removed"
    assert not list((tmp_path / "out").glob("*")), "nothing half-written in the output folder"


def test_cancel_only_affects_its_own_job(service, engine_parts, tmp_path):
    engine, pm = engine_parts
    info_a, info_b = service.analyze(url("slowvideo0a")), service.analyze(url("slowvideo0b"))
    results: dict = {}

    def run(name, info, job):
        try:
            results[name] = service.download(info, "mp4", "best", 192, tmp_path / "out", job_id=job)
        except Exception as exc:  # noqa: BLE001
            results[name] = exc

    ta = threading.Thread(target=run, args=("a", info_a, "job-a"))
    tb = threading.Thread(target=run, args=("b", info_b, "job-b"))
    ta.start()
    tb.start()
    deadline = time.time() + 20
    while time.time() < deadline and not (pm.is_running("job-a") and pm.is_running("job-b")):
        time.sleep(0.1)
    time.sleep(1)
    service.cancel("job-a")
    ta.join(15)
    assert isinstance(results["a"], CancelledError)
    assert pm.is_running("job-b"), "the other job keeps running"
    service.cancel("job-b")
    tb.join(15)
    assert isinstance(results["b"], CancelledError)


def test_kill_all_leaves_no_processes(engine_parts, service, tmp_path):
    engine, pm = engine_parts
    info = service.analyze(url("slowvideo0c"))
    t = threading.Thread(target=lambda: _swallow(lambda: service.download(info, "mp4", "best", 192, tmp_path / "out", job_id="job-c")))
    t.start()
    deadline = time.time() + 20
    while time.time() < deadline and not pm.is_running("job-c"):
        time.sleep(0.1)
    pm.kill_all()
    t.join(15)
    time.sleep(0.5)
    assert not process_alive_with("sleep(120)")


def _swallow(fn):
    try:
        fn()
    except Exception:  # noqa: BLE001
        pass


def test_versions_reported(engine_parts):
    engine, _ = engine_parts
    v = engine.versions()
    assert v["yt_dlp"] == "2099.01.01"
    assert v["ffmpeg"]


# ------------------------------------------------------------------ search
def test_search_returns_downloadable_results_without_live_streams(service):
    results = service.search("  never   gonna ")
    assert [r.video_id for r in results] == ["okvideo0001", "okvideo0002", "okvideo0003", "slowvideo01"]
    first = results[0]
    assert first.title == "Result 1 for never gonna" and first.uploader == "Fake Channel"
    assert first.duration_formatted == "3:00"
    assert first.thumbnail == "https://i.ytimg.com/vi/okvideo0001/mqdefault.jpg"
    assert first.webpage_url == "https://www.youtube.com/watch?v=okvideo0001"


def test_search_link_gives_one_result_and_bad_input_never_spawns(service, call_log):
    assert [r.video_id for r in service.search("https://youtu.be/okvideo0001")] == ["okvideo0001"]
    before = len(calls(call_log))
    for bad in ("", "x" * 101, "http://localhost/x"):
        with pytest.raises(Exception):
            service.search(bad)
    assert len(calls(call_log)) == before


def test_search_empty_and_network_failure(service):
    assert service.search("nothingfound") == []
    with pytest.raises(NetworkError) as e:
        service.search("failsearch")
    assert e.value.message == "Unable to connect. Please check your internet connection."


def test_search_result_can_be_downloaded(service, tmp_path):
    result = service.search("song")[0]
    path = service.download(result.to_video_info(), "mp3", "best", 192, tmp_path / "out")
    assert path.name == "Result 1 for song.mp3" and path.is_file()
