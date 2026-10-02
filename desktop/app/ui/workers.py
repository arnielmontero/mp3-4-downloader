"""Background workers. Nothing slow (yt-dlp, FFmpeg, network) ever runs on the UI thread."""
from __future__ import annotations

import logging
import uuid
from pathlib import Path

from PySide6.QtCore import QThread, Signal

from ..core.errors import MSG_UNAVAILABLE, AppError, CancelledError
from ..core.media_downloader import Progress, VideoInfo
from ..core.youtube_service import YouTubeService

log = logging.getLogger("ytd.worker")


class AnalyzeWorker(QThread):
    succeeded = Signal(object, object)  # VideoInfo, thumbnail bytes | None
    failed = Signal(str, str)  # friendly message, error code

    def __init__(self, service: YouTubeService, url: str, parent=None) -> None:
        super().__init__(parent)
        self._service = service
        self._url = url

    def run(self) -> None:
        try:
            info = self._service.analyze(self._url)
            thumbnail = self._service.fetch_thumbnail(info.thumbnail)
        except AppError as exc:
            log.warning("analyze failed: %s", exc.detail or exc.message, extra={"op": "analyze", "status": "failed", "error_code": exc.code})
            self.failed.emit(exc.message, exc.code)
            return
        except Exception:  # noqa: BLE001 - last resort: log details, show a friendly message
            log.exception("unexpected analyze error", extra={"op": "analyze", "status": "error"})
            self.failed.emit(MSG_UNAVAILABLE, "ERROR")
            return
        self.succeeded.emit(info, thumbnail)


class DownloadWorker(QThread):
    progress = Signal(object)  # Progress
    succeeded = Signal(str)  # final file path
    failed = Signal(str, str)  # friendly message, error code
    cancelled = Signal()

    def __init__(self, service: YouTubeService, info: VideoInfo, fmt: str, quality: str, bitrate: int, output_dir: Path, parent=None) -> None:
        super().__init__(parent)
        self.job_id = uuid.uuid4().hex
        self._service = service
        self._args = (info, fmt, quality, bitrate, output_dir)

    def request_cancel(self) -> None:
        self._service.cancel(self.job_id)

    def run(self) -> None:
        info, fmt, quality, bitrate, output_dir = self._args
        try:
            path = self._service.download(info, fmt, quality, bitrate, output_dir, self.job_id, self._emit_progress)
        except CancelledError:
            log.info("download cancelled", extra={"job_id": self.job_id, "op": "download", "status": "cancelled"})
            self.cancelled.emit()
            return
        except AppError as exc:
            log.warning("download failed: %s", exc.detail or exc.message, extra={"job_id": self.job_id, "op": "download", "status": "failed", "error_code": exc.code})
            self.failed.emit(exc.message, exc.code)
            return
        except Exception:  # noqa: BLE001
            log.exception("unexpected download error", extra={"job_id": self.job_id, "op": "download", "status": "error"})
            self.failed.emit(MSG_UNAVAILABLE, "ERROR")
            return
        self.succeeded.emit(str(path))

    def _emit_progress(self, progress: Progress) -> None:
        self.progress.emit(progress)


class VersionWorker(QThread):
    done = Signal(dict)

    def __init__(self, service: YouTubeService, parent=None) -> None:
        super().__init__(parent)
        self._service = service

    def run(self) -> None:
        try:
            self.done.emit(self._service.engine.versions())
        except Exception:  # noqa: BLE001
            self.done.emit({"yt_dlp": None, "ffmpeg": None})
