"""High-level operations used by the GUI: analyse a URL, download into the user's folder."""
from __future__ import annotations

import logging
import urllib.request
import uuid
from pathlib import Path

from . import file_service, paths, security
from .errors import MSG_OUTPUT, AppError, OutputFolderError
from .media_downloader import MediaDownloader, ProgressCallback, SearchResult, VideoInfo

log = logging.getLogger("ytd.service")

_MAX_THUMBNAIL_BYTES = 2_000_000


class YouTubeService:
    def __init__(self, engine: MediaDownloader, temp_root: Path | None = None) -> None:
        self.engine = engine
        self.temp_root = temp_root or (paths.data_dir() / "temp")

    # ------------------------------------------------------------------ analysis

    def analyze(self, raw_url: str) -> VideoInfo:
        """Validate + normalise the URL, then fetch metadata (nothing is downloaded)."""
        url = security.normalize_url(raw_url)
        info = self.engine.get_info(url)
        if info.is_live:
            raise AppError("Live streams cannot be downloaded.", code="VIDEO_UNAVAILABLE")
        return info

    def search(self, text: str) -> list[SearchResult]:
        """Text -> YouTube search results. A pasted YouTube link yields exactly one result (that video)."""
        if security.looks_like_url(text):
            info = self.analyze(text)
            return [SearchResult(info.video_id, info.title, info.uploader, info.duration, info.thumbnail or f"https://i.ytimg.com/vi/{info.video_id}/mqdefault.jpg", info.webpage_url)]
        return self.engine.search(security.validate_search_query(text))

    def fetch_thumbnail(self, url: str | None) -> bytes | None:
        """Download the thumbnail image (YouTube image hosts only, size limited). None on any problem."""
        if not security.safe_thumbnail(url):
            return None
        try:
            request = urllib.request.Request(url, headers={"User-Agent": "YouTubeDownloader/1.0"})  # noqa: S310 - https + host checked above
            with urllib.request.urlopen(request, timeout=10) as response:  # noqa: S310
                data = response.read(_MAX_THUMBNAIL_BYTES + 1)
            return data if len(data) <= _MAX_THUMBNAIL_BYTES else None
        except Exception:  # noqa: BLE001 - thumbnails are decoration; never fail the flow because of them
            return None

    # ------------------------------------------------------------------ download

    def download(
        self,
        info: VideoInfo,
        fmt: str,
        quality: str,
        bitrate: int,
        output_dir: Path,
        job_id: str | None = None,
        on_progress: ProgressCallback | None = None,
    ) -> Path:
        """Download into a private scratch folder, then move the result to `output_dir` under a safe,
        unique name. Scratch data is always removed (success, failure and cancellation)."""
        fmt = security.validate_format(fmt)
        quality = security.validate_quality(quality) if fmt == "mp4" else "best"
        bitrate = security.validate_bitrate(bitrate) if fmt == "mp3" else security.DEFAULT_BITRATE
        job_id = job_id or uuid.uuid4().hex
        url = security.normalize_url(info.webpage_url)

        file_service.ensure_writable_dir(output_dir)  # fail early, before downloading anything
        scratch = self.temp_root / job_id
        scratch.mkdir(parents=True, exist_ok=True)
        try:
            if fmt == "mp3":
                produced = self.engine.download_mp3(url, bitrate, scratch, job_id, on_progress)
            else:
                produced = self.engine.download_mp4(url, quality, scratch, job_id, on_progress)
            # never hand out a file that is not fully playable (container, streams, length, full decode)
            self.engine.verify(produced, fmt, info.duration, job_id, on_progress)
            base = file_service.sanitize_filename(info.title, info.video_id)
            target = file_service.unique_path(output_dir, base, fmt)
            try:
                file_service.move_file(produced, target)
            except OutputFolderError:
                raise
            except OSError as exc:  # pragma: no cover - move_file already maps OSError
                raise OutputFolderError(MSG_OUTPUT, detail=str(exc)) from exc
            log.info("saved %s", target.name, extra={"job_id": job_id, "op": "save", "status": "ok"})
            return target
        finally:
            file_service.remove_tree(scratch)

    def cancel(self, job_id: str) -> None:
        self.engine.cancel(job_id)

    def cleanup_stale_temp(self) -> None:
        """Remove scratch folders left behind by a crash (called at start-up, nothing is running yet)."""
        if self.temp_root.is_dir():
            for child in self.temp_root.iterdir():
                if child.is_dir():
                    file_service.remove_tree(child)
