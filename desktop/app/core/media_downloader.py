"""Media engine abstraction + the yt-dlp implementation.

The GUI and services only talk to :class:`MediaDownloader`; yt-dlp/FFmpeg specifics (command line,
progress parsing, error mapping) live in :class:`YtDlpDownloader`.
"""
from __future__ import annotations

import json
import logging
import re
import shutil
import subprocess
import threading
import time
from abc import ABC, abstractmethod
from collections import deque
from dataclasses import dataclass
from pathlib import Path
from typing import Callable

from . import file_service, paths
from .errors import (
    MSG_ENGINE,
    MSG_NETWORK,
    MSG_PROCESSING,
    MSG_UNAVAILABLE,
    AppError,
    CancelledError,
    DownloadFailedError,
    EngineMissingError,
    NetworkError,
    ProcessingFailedError,
    VideoUnavailableError,
)
from .process_manager import ProcessManager
from .security import DEFAULT_BITRATE, safe_thumbnail

log = logging.getLogger("ytd.engine")

INFO_TAG = "YTDINFO "
FORMATS_TAG = "YTDFMT "
PROGRESS_TAG = "YTDP|"
_STANDARD_HEIGHTS = (144, 240, 360, 480, 720, 1080, 1440, 2160, 4320)
_PROCESSING = re.compile(
    r"^\[(Merger|ExtractAudio|VideoConvertor|VideoRemuxer|Metadata|EmbedThumbnail|Fixup\w+|ModifyChapters|MoveFiles)\]"
)


# --------------------------------------------------------------------------------------- data


@dataclass
class VideoInfo:
    video_id: str
    title: str
    uploader: str | None
    duration: int | None
    thumbnail: str | None
    webpage_url: str
    is_live: bool
    has_video: bool
    has_audio: bool
    qualities: list[str]
    source_audio_bitrate: int | None
    filesize: int | None

    @property
    def duration_formatted(self) -> str:
        return format_duration(self.duration) if self.duration is not None else "Unknown"


@dataclass
class Progress:
    stage: str = "starting"  # analyzing | downloading | processing | finished
    percent: float | None = None
    downloaded_bytes: int = 0
    total_bytes: int | None = None
    speed_bps: float | None = None
    eta_seconds: int | None = None
    #: True when no exact percentage is available (the UI then shows a busy bar instead of a fake %)
    indeterminate: bool = False


ProgressCallback = Callable[[Progress], None]


def format_duration(seconds: int) -> str:
    h, rem = divmod(max(0, int(seconds)), 3600)
    m, s = divmod(rem, 60)
    return f"{h}:{m:02d}:{s:02d}" if h else f"{m}:{s:02d}"


def format_bytes(value: float | int | None) -> str:
    if value is None:
        return ""
    value = float(value)
    for unit in ("B", "KiB", "MiB", "GiB", "TiB"):
        if value < 1024 or unit == "TiB":
            return f"{value:.0f} {unit}" if unit == "B" else f"{value:.1f} {unit}"
        value /= 1024
    return ""


# --------------------------------------------------------------------------------------- interface


class MediaDownloader(ABC):
    """Replaceable media engine."""

    @abstractmethod
    def get_info(self, url: str, job_id: str = "info") -> VideoInfo:
        """Metadata only - never downloads media."""

    @abstractmethod
    def download_mp4(self, url: str, quality: str, out_dir: Path, job_id: str, on_progress: ProgressCallback | None = None) -> Path:
        """Download a video as .mp4 into out_dir (a scratch folder) and return the file."""

    @abstractmethod
    def download_mp3(self, url: str, bitrate: int, out_dir: Path, job_id: str, on_progress: ProgressCallback | None = None) -> Path:
        """Download audio, convert to .mp3 at `bitrate` kbps, return the file."""

    @abstractmethod
    def cancel(self, job_id: str) -> None:
        """Stop the job's processes (yt-dlp and any FFmpeg it started)."""

    @abstractmethod
    def get_progress(self, job_id: str) -> Progress | None:
        """Latest progress snapshot of a running job."""

    @abstractmethod
    def versions(self) -> dict[str, str | None]:
        """{'yt_dlp': ..., 'ffmpeg': ...}"""


# --------------------------------------------------------------------------------------- yt-dlp


class YtDlpDownloader(MediaDownloader):
    def __init__(
        self,
        processes: ProcessManager,
        ytdlp_cmd: list[str] | None = None,
        ffmpeg_path: Path | None = None,
        deno_path: Path | None = None,
        cache_dir: Path | None = None,
        info_timeout: int = 90,
    ) -> None:
        self._processes = processes
        self._ytdlp_cmd = ytdlp_cmd
        self._ffmpeg = ffmpeg_path
        self._deno = deno_path
        self._cache_dir = cache_dir or (paths.data_dir() / "cache")
        self._info_timeout = info_timeout
        self._progress: dict[str, Progress] = {}
        self._cancelled: set[str] = set()
        self._lock = threading.Lock()

    # ----------------------------------------------------------- command construction

    def _ytdlp(self) -> list[str]:
        if self._ytdlp_cmd:
            return list(self._ytdlp_cmd)
        found = paths.find_binary("yt-dlp")
        if found is None:
            raise EngineMissingError(MSG_ENGINE)
        return [str(found)]

    def _ffmpeg_exe(self) -> Path | None:
        return self._ffmpeg or paths.find_binary("ffmpeg")

    def _base_args(self) -> list[str]:
        args = [
            *self._ytdlp(),
            "--ignore-config", "--no-playlist", "--no-warnings", "--no-color", "--no-mtime",
            "--socket-timeout", "30", "--retries", "3", "--fragment-retries", "3",
            "--cache-dir", str(self._cache_dir),
        ]
        ffmpeg = self._ffmpeg_exe()
        if ffmpeg is not None:
            args += ["--ffmpeg-location", str(ffmpeg)]
        deno = self._deno or paths.find_binary("deno")
        if deno is not None:
            args += ["--js-runtimes", f"deno:{deno}"]
        return args

    @staticmethod
    def mp4_format_args(quality: str) -> list[str]:
        """Prefer H.264/AAC (plays everywhere), cap resolution when a quality is chosen, merge to .mp4."""
        sort = "vcodec:avc1,res,acodec:m4a,channels:2" if quality == "best" else f"res:{int(quality.rstrip('p'))},vcodec:avc1,acodec:m4a,channels:2"
        return ["-f", "bv+ba/bv*+ba/b", "-S", sort, "--merge-output-format", "mp4", "--remux-video", "mp4"]

    @staticmethod
    def mp3_format_args(bitrate: int) -> list[str]:
        # description is dropped: it is huge and not useful MP3 metadata (title/artist/album/date remain)
        return ["-f", "ba/b", "-x", "--audio-format", "mp3", "--audio-quality", f"{bitrate}K", "--embed-metadata",
                "--replace-in-metadata", "description", "(?s).+", ""]

    def build_info_command(self, url: str, fmt: str = "", quality: str = "best") -> list[str]:
        cmd = self._base_args()
        if fmt == "mp4":
            cmd += self.mp4_format_args(quality)[:4]
        elif fmt == "mp3":
            cmd += ["-f", "ba/b"]
        cmd += [
            "--print", INFO_TAG + "%(.{id,title,uploader,channel,duration,thumbnail,is_live,live_status,webpage_url,filesize,filesize_approx})j",
            "--print", FORMATS_TAG + "%(formats.:.{format_id,ext,height,vcodec,acodec,abr,filesize,filesize_approx})j",
            "--", url,
        ]
        return cmd

    def build_download_command(self, url: str, fmt: str, quality: str, bitrate: int, out_dir: Path) -> list[str]:
        cmd = self._base_args() + [
            "--newline", "--progress",
            "--progress-template",
            "download:" + PROGRESS_TAG + "%(progress.status)s|%(progress.downloaded_bytes)s|%(progress.total_bytes)s|"
            "%(progress.total_bytes_estimate)s|%(progress.speed)s|%(progress.eta)s|%(progress.filename)s",
            "-o", str(out_dir / "%(id)s.%(ext)s"),
        ]
        cmd += self.mp3_format_args(bitrate) if fmt == "mp3" else self.mp4_format_args(quality)
        return cmd + ["--", url]

    # ----------------------------------------------------------- MediaDownloader API

    def get_info(self, url: str, job_id: str = "info") -> VideoInfo:
        info, _ = self._run_info(url, "", "best", job_id)
        return info

    def _run_info(self, url: str, fmt: str, quality: str, job_id: str) -> tuple[VideoInfo, int | None]:
        cmd = self.build_info_command(url, fmt, quality)
        proc = self._processes.start(job_id, cmd)
        out: list[str] = []
        timed_out = threading.Event()

        def watchdog() -> None:
            if not done.wait(self._info_timeout):
                timed_out.set()
                self._processes.terminate(job_id)

        done = threading.Event()
        threading.Thread(target=watchdog, daemon=True).start()
        try:
            assert proc.stdout is not None
            for raw in proc.stdout:
                out.append(raw.decode("utf-8", "replace").rstrip("\r\n"))
            proc.wait()
        finally:
            done.set()
            self._processes.release(job_id)
        if self._is_cancelled(job_id.split(":")[0]):
            raise CancelledError("Cancelled.")
        if timed_out.is_set():
            raise NetworkError(MSG_NETWORK, detail="info timeout")
        if proc.returncode != 0:
            raise classify_error("\n".join(line for line in out if not line.startswith("YTD")))
        info_line = next((line for line in out if line.startswith(INFO_TAG)), None)
        fmt_line = next((line for line in out if line.startswith(FORMATS_TAG)), None)
        if info_line is None:
            raise VideoUnavailableError(MSG_UNAVAILABLE, detail="no info output")
        try:
            raw_info = json.loads(info_line[len(INFO_TAG):])
            formats = json.loads(fmt_line[len(FORMATS_TAG):]) if fmt_line else []
        except ValueError as exc:
            raise VideoUnavailableError(MSG_UNAVAILABLE, detail=f"bad info json: {exc}") from exc
        info = normalize_info(raw_info, formats if isinstance(formats, list) else [])
        size = raw_info.get("filesize") or raw_info.get("filesize_approx")
        return info, int(size) if isinstance(size, (int, float)) else None

    def download_mp4(self, url, quality, out_dir, job_id, on_progress=None):
        return self._download(url, "mp4", quality, DEFAULT_BITRATE, out_dir, job_id, on_progress)

    def download_mp3(self, url, bitrate, out_dir, job_id, on_progress=None):
        return self._download(url, "mp3", "best", bitrate, out_dir, job_id, on_progress)

    def _download(self, url: str, fmt: str, quality: str, bitrate: int, out_dir: Path, job_id: str, on_progress: ProgressCallback | None) -> Path:
        started = time.monotonic()
        self._set_progress(job_id, Progress(stage="analyzing", indeterminate=True), on_progress)
        # 1) resolve which streams will be fetched, to give exact overall progress and honour limits
        try:
            _info, expected = self._run_info(url, fmt, quality, job_id + ":info")
        except CancelledError:
            self._pop_cancelled(job_id)
            raise

        # 2) the actual download
        out_dir.mkdir(parents=True, exist_ok=True)
        cmd = self.build_download_command(url, fmt, quality, bitrate, out_dir)
        proc = self._processes.start(job_id, cmd)
        state = _ProgressState(expected_total=expected or 0)
        tail: deque[str] = deque(maxlen=40)
        try:
            assert proc.stdout is not None
            for raw in proc.stdout:
                line = raw.decode("utf-8", "replace").rstrip("\r\n")
                if not line:
                    continue
                if state.feed(line):
                    self._set_progress(job_id, state.snapshot(), on_progress)
                else:
                    tail.append(line)
            proc.wait()
        finally:
            cancelled = self._pop_cancelled(job_id)
            self._processes.release(job_id)

        if cancelled:
            raise CancelledError("Cancelled.")
        if proc.returncode != 0:
            err = classify_error("\n".join(tail))
            log.warning("yt-dlp exited with %s: %s", proc.returncode, "\n".join(tail), extra={"job_id": job_id, "op": "download", "status": "failed", "error_code": err.code})
            raise err
        produced = file_service.find_output(out_dir, fmt)
        if produced is None:
            log.warning("no %s produced; output: %s", fmt, "\n".join(tail), extra={"job_id": job_id, "op": "download", "status": "failed"})
            raise ProcessingFailedError(MSG_PROCESSING, detail="no output file")
        self._set_progress(job_id, Progress(stage="finished", percent=100.0, downloaded_bytes=produced.stat().st_size, total_bytes=produced.stat().st_size), on_progress)
        log.info("engine finished", extra={"job_id": job_id, "op": "download", "status": "ok", "duration_ms": int((time.monotonic() - started) * 1000)})
        return produced

    def cancel(self, job_id: str) -> None:
        with self._lock:
            self._cancelled.add(job_id)
        # the job runs up to two processes in sequence (analysis, then download)
        for pid_key in (job_id, job_id + ":info"):
            self._processes.terminate(pid_key)

    def get_progress(self, job_id: str) -> Progress | None:
        with self._lock:
            return self._progress.get(job_id)

    def versions(self) -> dict[str, str | None]:
        result: dict[str, str | None] = {"yt_dlp": None, "ffmpeg": None}
        try:
            out = _run_capture([*self._ytdlp(), "--version"])
            result["yt_dlp"] = out.split()[0] if out.split() else None
        except (AppError, OSError, subprocess.SubprocessError):
            pass
        ffmpeg = self._ffmpeg_exe()
        if ffmpeg is not None:
            try:
                match = re.search(r"ffmpeg version\s+(\S+)", _run_capture([str(ffmpeg), "-version"]))
                result["ffmpeg"] = match.group(1) if match else None
            except (OSError, subprocess.SubprocessError):
                pass
        return result

    # ----------------------------------------------------------- internals

    def _set_progress(self, job_id: str, progress: Progress, callback: ProgressCallback | None) -> None:
        with self._lock:
            self._progress[job_id] = progress
        if callback is not None:
            callback(progress)

    def _is_cancelled(self, job_id: str) -> bool:
        with self._lock:
            return job_id in self._cancelled

    def _pop_cancelled(self, job_id: str) -> bool:
        with self._lock:
            if job_id in self._cancelled:
                self._cancelled.discard(job_id)
                return True
            return False


# --------------------------------------------------------------------------------------- helpers


class _ProgressState:
    """Turns yt-dlp's progress template lines into overall progress (across the video + audio streams)."""

    def __init__(self, expected_total: int = 0) -> None:
        self.expected_total = expected_total
        self.finished_bytes = 0
        self.current_file: str | None = None
        self.current_bytes = 0
        self.stream_index = 0
        self.stage = "downloading"
        self.processing = False
        self.percent: float | None = None
        self.downloaded = 0
        self.total: int | None = None
        self.speed: float | None = None
        self.eta: int | None = None

    def feed(self, line: str) -> bool:
        """Returns True if the line changed the progress state."""
        if line.startswith(PROGRESS_TAG):
            return self._progress_line(line[len(PROGRESS_TAG):])
        if _PROCESSING.match(line):
            self.processing = True
            self.stage = "processing"
            return True
        return False

    @staticmethod
    def _num(value: str) -> float | None:
        try:
            return float(value)
        except ValueError:
            return None

    def _progress_line(self, payload: str) -> bool:
        parts = payload.split("|", 6)
        if len(parts) < 7:
            return False
        status, downloaded, total, estimate, speed, eta, filename = parts
        downloaded_n = self._num(downloaded)
        if downloaded_n is None:
            return False
        done = int(downloaded_n)
        stream_total = self._num(total) or self._num(estimate)

        if self.current_file is not None and self.current_file != filename:
            self.finished_bytes += self.current_bytes
            self.stream_index += 1
        self.current_file = filename
        self.current_bytes = done

        if status == "finished":
            self.finished_bytes += done
            self.current_file = None
            self.current_bytes = 0
            self.stream_index += 1
            self.downloaded = self.finished_bytes
            self.speed = None
            self.eta = None
            return True

        self.stage = "downloading"
        self.downloaded = self.finished_bytes + done
        self.speed = self._num(speed)
        eta_n = self._num(eta)
        self.eta = int(eta_n) if eta_n is not None else None
        if self.expected_total > 0:
            self.total = max(self.expected_total, self.downloaded)
            self.percent = min(99.9, self.downloaded / self.expected_total * 100)
        elif stream_total and stream_total > 0 and self.stream_index == 0:
            self.total = int(stream_total)
            self.percent = min(99.9, done / stream_total * 100)
        else:
            self.total = None
            self.percent = None
        return True

    def snapshot(self) -> Progress:
        if self.processing:
            return Progress(stage="processing", percent=100.0, downloaded_bytes=self.downloaded, total_bytes=self.total, indeterminate=True)
        return Progress(
            stage=self.stage, percent=self.percent, downloaded_bytes=self.downloaded, total_bytes=self.total,
            speed_bps=self.speed, eta_seconds=self.eta, indeterminate=self.percent is None,
        )


def normalize_info(info: dict, formats: list) -> VideoInfo:
    live = bool(info.get("is_live")) or info.get("live_status") in ("is_live", "is_upcoming")
    duration = info.get("duration")
    duration = int(round(float(duration))) if isinstance(duration, (int, float)) else None

    buckets: set[int] = set()
    has_video = has_audio = False
    max_abr = 0.0
    for f in formats:
        if not isinstance(f, dict):
            continue
        vcodec, acodec = f.get("vcodec"), f.get("acodec")
        height = f.get("height") if isinstance(f.get("height"), (int, float)) else 0
        if vcodec not in (None, "none") and height > 0:
            has_video = True
            buckets.add(next((s for s in _STANDARD_HEIGHTS if height <= s), 4320))
        if acodec not in (None, "none"):
            has_audio = True
            if vcodec in (None, "none") and isinstance(f.get("abr"), (int, float)):
                max_abr = max(max_abr, float(f["abr"]))
    if not formats:
        has_video = has_audio = True
    qualities = ["best"] + [f"{h}p" for h in sorted(buckets, reverse=True)]
    size = info.get("filesize") or info.get("filesize_approx")
    vid = str(info["id"])
    return VideoInfo(
        video_id=vid,
        title=str(info.get("title") or vid),
        uploader=info.get("uploader") or info.get("channel"),
        duration=duration,
        thumbnail=safe_thumbnail(info.get("thumbnail")),
        webpage_url=f"https://www.youtube.com/watch?v={vid}",
        is_live=live,
        has_video=has_video,
        has_audio=has_audio,
        qualities=qualities,
        source_audio_bitrate=int(round(max_abr)) if max_abr > 0 else None,
        filesize=int(size) if isinstance(size, (int, float)) else None,
    )


def classify_error(stderr: str) -> AppError:
    """Map yt-dlp output to a friendly error; the raw text is kept in `detail` for the log only."""
    s = stderr.lower()

    def has(*needles: str) -> bool:
        return any(n in s for n in needles)

    if has("postprocessing", "ffmpeg", "ffprobe", "conversion failed", "error while decoding"):
        return ProcessingFailedError(MSG_PROCESSING, detail=stderr)
    if has("unable to download webpage", "getaddrinfo", "name resolution", "network is unreachable", "timed out",
           "connection reset", "connection refused", "urlopen error", "no route to host", "ssl:", "winerror 100", "nodename nor servname"):
        return NetworkError(MSG_NETWORK, detail=stderr)
    if has("http error 429", "too many requests"):
        return DownloadFailedError("YouTube is temporarily limiting requests. Please try again later.", detail=stderr)
    if has("video unavailable", "private video", "this video is not available", "is unavailable", "has been removed", "members-only",
           "sign in", "confirm your age", "copyright", "not available in your country", "requested format is not available", "age-restricted", "drm"):
        return VideoUnavailableError(MSG_UNAVAILABLE, detail=stderr)
    return DownloadFailedError(MSG_UNAVAILABLE, detail=stderr)


def _run_capture(cmd: list[str]) -> str:
    kwargs = {"creationflags": 0x08000000} if paths.IS_WINDOWS else {}
    result = subprocess.run(cmd, capture_output=True, timeout=20, check=False, shell=False, **kwargs)  # noqa: S603
    return result.stdout.decode("utf-8", "replace")
