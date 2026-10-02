"""One entry of the downloads sidebar: owns its worker, progress, cancel and open-file/folder actions.

Several of these run at the same time; each has its own job id, so cancelling one only stops that job's
yt-dlp/FFmpeg process tree.
"""
from __future__ import annotations

from pathlib import Path

from PySide6.QtCore import Signal
from PySide6.QtWidgets import QFrame, QHBoxLayout, QLabel, QProgressBar, QPushButton, QVBoxLayout, QWidget

from ..core import file_service
from ..core.media_downloader import Progress, VideoInfo, format_bytes, format_duration
from ..core.youtube_service import YouTubeService
from .workers import DownloadWorker


def _escape(text: str) -> str:
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


class DownloadItem(QFrame):
    #: emitted when the job reached a final state (done / failed / cancelled)
    finished_job = Signal(object)
    dismissed = Signal(object)

    QUEUED, RUNNING, DONE, FAILED, CANCELLED = "queued", "running", "done", "failed", "cancelled"

    def __init__(self, service: YouTubeService, info: VideoInfo, fmt: str, quality: str, bitrate: int, output_dir: Path, parent: QWidget | None = None) -> None:
        super().__init__(parent)
        self.setObjectName("downloadItem")
        self.setFrameShape(QFrame.Shape.StyledPanel)
        self.service = service
        self.info = info
        self.fmt = fmt
        self.state = self.QUEUED
        self.last_file: Path | None = None
        self.worker: DownloadWorker | None = None
        self._args = (info, fmt, quality, bitrate, output_dir)
        self._cancelling = False

        label = f"MP3 {bitrate} kbps" if fmt == "mp3" else f"MP4 {quality}"
        self.title_label = QLabel(f"<b>{_escape(info.title)}</b> ({label})")
        self.title_label.setObjectName("jobTitle")
        self.title_label.setWordWrap(True)
        self.progress_bar = QProgressBar()
        self.progress_bar.setObjectName("jobProgress")
        self.progress_bar.setRange(0, 0)  # busy while waiting for the first real number
        self.progress_bar.setAccessibleName(f"Progress of {info.title}")
        self.stats_label = QLabel("")
        self.stats_label.setObjectName("jobStats")
        self.status_label = QLabel("Waiting...")
        self.status_label.setObjectName("jobStatus")
        self.status_label.setWordWrap(True)

        self.cancel_button = QPushButton("Cancel")
        self.cancel_button.setObjectName("jobCancel")
        self.cancel_button.setAccessibleName(f"Cancel {info.title}")
        self.open_file_button = QPushButton("Open")
        self.open_file_button.setObjectName("jobOpenFile")
        self.open_file_button.setToolTip("Open the downloaded file")
        self.open_folder_button = QPushButton("Folder")
        self.open_folder_button.setObjectName("jobOpenFolder")
        self.open_folder_button.setToolTip("Show the file in its folder")
        self.dismiss_button = QPushButton("Dismiss")
        self.dismiss_button.setObjectName("jobDismiss")
        row = QHBoxLayout()
        for b in (self.cancel_button, self.open_file_button, self.open_folder_button, self.dismiss_button):
            row.addWidget(b)
        row.addStretch(1)

        layout = QVBoxLayout(self)
        for w in (self.title_label, self.progress_bar, self.stats_label, self.status_label):
            layout.addWidget(w)
        layout.addLayout(row)

        self.cancel_button.clicked.connect(self.cancel)
        self.open_file_button.clicked.connect(self.open_file)
        self.open_folder_button.clicked.connect(self.open_folder)
        self.dismiss_button.clicked.connect(lambda: self.dismissed.emit(self))
        self._refresh_buttons()

    # ------------------------------------------------------------------ lifecycle

    def start(self) -> None:
        if self.state != self.QUEUED:
            return
        self.state = self.RUNNING
        self.status_label.setText("Starting...")
        info, fmt, quality, bitrate, output_dir = self._args
        worker = DownloadWorker(self.service, info, fmt, quality, bitrate, output_dir, self)
        worker.progress.connect(self._on_progress)
        worker.succeeded.connect(self._on_done)
        worker.failed.connect(self._on_failed)
        worker.cancelled.connect(self._on_cancelled)
        worker.finished.connect(self._on_thread_finished)
        self.worker = worker
        self._refresh_buttons()
        worker.start()

    def is_active(self) -> bool:
        return self.state in (self.QUEUED, self.RUNNING)

    def cancel(self) -> None:
        if self._cancelling:
            return
        if self.state == self.QUEUED:  # never started: nothing to stop
            self._on_cancelled()
            return
        if self.worker is None:
            return
        self._cancelling = True
        self.cancel_button.setEnabled(False)
        self.status_label.setText("Cancelling...")
        self.worker.request_cancel()

    def wait(self, ms: int = 15000) -> None:
        if self.worker is not None and self.worker.isRunning():
            self.worker.wait(ms)

    # ------------------------------------------------------------------ worker signals

    def _on_progress(self, p: Progress) -> None:
        if self._cancelling or self.state != self.RUNNING:
            return
        self.status_label.setText({"analyzing": "Checking the video...", "processing": "Processing media...", "verifying": "Verifying the file plays correctly...", "finished": "Finishing..."}.get(p.stage, "Downloading..."))
        if p.indeterminate or p.percent is None:
            self.progress_bar.setRange(0, 0)  # honest busy indicator, never a fake percentage
        else:
            self.progress_bar.setRange(0, 100)
            self.progress_bar.setValue(int(p.percent))
        parts = []
        if p.downloaded_bytes:
            parts.append(f"{format_bytes(p.downloaded_bytes)} of {format_bytes(p.total_bytes)}" if p.total_bytes else format_bytes(p.downloaded_bytes))
        if p.speed_bps:
            parts.append(f"{format_bytes(p.speed_bps)}/s")
        if p.eta_seconds is not None:
            parts.append(f"ETA {format_duration(p.eta_seconds)}")
        self.stats_label.setText("  |  ".join(parts))

    def _on_done(self, path: str) -> None:
        self.last_file = Path(path)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(100)
        self.status_label.setText(f"Download complete: {self.last_file.name}")
        self._final(self.DONE)

    def _on_failed(self, message: str, _code: str) -> None:
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.status_label.setText(message)
        self._final(self.FAILED)

    def _on_cancelled(self) -> None:
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self.status_label.setText("Download cancelled.")
        self._final(self.CANCELLED)

    def _on_thread_finished(self) -> None:
        worker, self.worker = self.worker, None
        if worker is not None:
            worker.deleteLater()

    def _final(self, state: str) -> None:
        self.state = state
        self._cancelling = False
        self.stats_label.setText("")
        self._refresh_buttons()
        self.finished_job.emit(self)

    def _refresh_buttons(self) -> None:
        self.cancel_button.setVisible(self.is_active())
        self.cancel_button.setEnabled(self.is_active() and not self._cancelling)
        self.open_file_button.setVisible(self.state == self.DONE)
        self.open_folder_button.setVisible(self.state == self.DONE)
        self.dismiss_button.setVisible(not self.is_active())

    # ------------------------------------------------------------------ actions

    def open_file(self) -> None:
        if self.last_file and self.last_file.exists():
            file_service.open_file(self.last_file)

    def open_folder(self) -> None:
        if self.last_file and self.last_file.exists():
            file_service.reveal_file(self.last_file)
