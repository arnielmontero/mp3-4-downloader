"""Main window: URL -> Analyze -> options -> Download with live progress, cancel, open file/folder."""
from __future__ import annotations

import logging
from pathlib import Path

from PySide6.QtCore import Qt
from PySide6.QtGui import QAction, QCloseEvent, QDesktopServices, QPixmap
from PySide6.QtCore import QUrl
from PySide6.QtWidgets import (
    QApplication,
    QButtonGroup,
    QComboBox,
    QFileDialog,
    QFrame,
    QGridLayout,
    QGroupBox,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QMainWindow,
    QMessageBox,
    QProgressBar,
    QPushButton,
    QRadioButton,
    QVBoxLayout,
    QWidget,
)

from .. import APP_NAME
from ..core import file_service, logging_service, paths
from ..core.errors import MSG_INVALID_URL
from ..core.media_downloader import Progress, VideoInfo, format_bytes, format_duration
from ..core.security import BITRATES
from ..core.settings_service import SettingsService
from ..core.youtube_service import YouTubeService
from .dialogs import AboutDialog, SettingsDialog
from .workers import AnalyzeWorker, DownloadWorker

log = logging.getLogger("ytd.ui")


class MainWindow(QMainWindow):
    def __init__(self, service: YouTubeService, settings: SettingsService) -> None:
        super().__init__()
        self.service = service
        self.settings = settings
        self.info: VideoInfo | None = None
        self.last_file: Path | None = None
        self._analyze_worker: AnalyzeWorker | None = None
        self._download_worker: DownloadWorker | None = None
        self._cancelling = False

        self.setWindowTitle(APP_NAME)
        self.setMinimumWidth(640)
        self._build_ui()
        self._build_menu()
        self._apply_settings()
        self._set_state("idle")

    # ------------------------------------------------------------------ construction

    def _build_ui(self) -> None:
        central = QWidget()
        self.setCentralWidget(central)
        root = QVBoxLayout(central)
        root.setSpacing(12)

        heading = QLabel(f"<h2>{APP_NAME}</h2>")
        root.addWidget(heading)

        # URL row
        url_label = QLabel("Paste YouTube URL")
        self.url_input = QLineEdit()
        self.url_input.setObjectName("urlInput")
        self.url_input.setPlaceholderText("https://www.youtube.com/watch?v=...")
        self.url_input.setClearButtonEnabled(True)
        self.url_input.setAccessibleName("YouTube URL")
        url_label.setBuddy(self.url_input)
        self.analyze_button = QPushButton("Analyze")
        self.analyze_button.setObjectName("analyzeButton")
        self.analyze_button.setDefault(True)
        row = QHBoxLayout()
        row.addWidget(self.url_input, 1)
        row.addWidget(self.analyze_button)
        root.addWidget(url_label)
        root.addLayout(row)
        self.url_error = QLabel("")
        self.url_error.setObjectName("urlError")
        self.url_error.setStyleSheet("color: #b00020;")
        self.url_error.setWordWrap(True)
        root.addWidget(self.url_error)

        # media information
        self.info_box = QGroupBox("Media information")
        self.info_box.setObjectName("infoBox")
        grid = QGridLayout(self.info_box)
        self.thumb = QLabel()
        self.thumb.setObjectName("thumbnail")
        self.thumb.setFixedSize(256, 144)
        self.thumb.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.thumb.setFrameShape(QFrame.Shape.StyledPanel)
        self.thumb.setAccessibleName("Thumbnail")
        self.title_label = QLabel()
        self.title_label.setObjectName("titleLabel")
        self.title_label.setWordWrap(True)
        self.title_label.setTextInteractionFlags(Qt.TextInteractionFlag.TextSelectableByMouse)
        self.duration_label = QLabel()
        self.duration_label.setObjectName("durationLabel")
        self.uploader_label = QLabel()
        self.uploader_label.setObjectName("uploaderLabel")
        details = QVBoxLayout()
        details.addWidget(self._caption("Title"))
        details.addWidget(self.title_label)
        details.addWidget(self._caption("Duration"))
        details.addWidget(self.duration_label)
        details.addWidget(self._caption("Uploader"))
        details.addWidget(self.uploader_label)
        details.addStretch(1)
        grid.addWidget(self.thumb, 0, 0)
        grid.addLayout(details, 0, 1)
        grid.setColumnStretch(1, 1)
        root.addWidget(self.info_box)

        # options
        self.options_box = QGroupBox("Download options")
        self.options_box.setObjectName("optionsBox")
        og = QGridLayout(self.options_box)
        self.mp4_radio = QRadioButton("MP4 (video)")
        self.mp4_radio.setObjectName("mp4Radio")
        self.mp3_radio = QRadioButton("MP3 (audio)")
        self.mp3_radio.setObjectName("mp3Radio")
        self.format_group = QButtonGroup(self)
        self.format_group.addButton(self.mp4_radio)
        self.format_group.addButton(self.mp3_radio)
        self.quality_combo = QComboBox()
        self.quality_combo.setObjectName("qualityCombo")
        self.quality_combo.setAccessibleName("Video quality")
        self.bitrate_combo = QComboBox()
        self.bitrate_combo.setObjectName("bitrateCombo")
        self.bitrate_combo.setAccessibleName("MP3 bitrate")
        for b in BITRATES:
            self.bitrate_combo.addItem(f"{b} kbps", b)
        self.quality_caption = QLabel("Video quality:")
        self.quality_caption.setBuddy(self.quality_combo)
        self.bitrate_caption = QLabel("Audio quality:")
        self.bitrate_caption.setBuddy(self.bitrate_combo)
        self.bitrate_note = QLabel("")
        self.bitrate_note.setObjectName("bitrateNote")
        self.bitrate_note.setWordWrap(True)
        self.bitrate_note.setStyleSheet("color: palette(mid);")
        self.folder_input = QLineEdit()
        self.folder_input.setObjectName("folderInput")
        self.folder_input.setReadOnly(True)
        self.folder_input.setAccessibleName("Output folder")
        self.browse_button = QPushButton("Browse...")
        self.browse_button.setObjectName("browseButton")
        folder_caption = QLabel("Save to:")
        folder_caption.setBuddy(self.browse_button)
        og.addWidget(QLabel("Format:"), 0, 0)
        og.addWidget(self.mp4_radio, 0, 1)
        og.addWidget(self.mp3_radio, 0, 2)
        og.addWidget(self.quality_caption, 1, 0)
        og.addWidget(self.quality_combo, 1, 1, 1, 2)
        og.addWidget(self.bitrate_caption, 2, 0)
        og.addWidget(self.bitrate_combo, 2, 1, 1, 2)
        og.addWidget(self.bitrate_note, 3, 1, 1, 3)
        og.setColumnStretch(2, 1)
        root.addWidget(self.options_box)

        # output folder: always visible so it can be chosen before analysing too
        folder_row = QHBoxLayout()
        folder_row.addWidget(folder_caption)
        folder_row.addWidget(self.folder_input, 1)
        folder_row.addWidget(self.browse_button)
        root.addLayout(folder_row)

        # actions + progress
        self.download_button = QPushButton("Download")
        self.download_button.setObjectName("downloadButton")
        self.cancel_button = QPushButton("Cancel")
        self.cancel_button.setObjectName("cancelButton")
        actions = QHBoxLayout()
        actions.addWidget(self.download_button)
        actions.addWidget(self.cancel_button)
        actions.addStretch(1)
        root.addLayout(actions)

        self.progress_bar = QProgressBar()
        self.progress_bar.setObjectName("progressBar")
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setAccessibleName("Download progress")
        self.stats_label = QLabel("")
        self.stats_label.setObjectName("statsLabel")
        self.status_label = QLabel("Ready.")
        self.status_label.setObjectName("statusLabel")
        self.status_label.setWordWrap(True)
        root.addWidget(self.progress_bar)
        root.addWidget(self.stats_label)
        root.addWidget(self.status_label)

        self.open_file_button = QPushButton("Open File")
        self.open_file_button.setObjectName("openFileButton")
        self.open_folder_button = QPushButton("Open Output Folder")
        self.open_folder_button.setObjectName("openFolderButton")
        results = QHBoxLayout()
        results.addWidget(self.open_file_button)
        results.addWidget(self.open_folder_button)
        results.addStretch(1)
        self.results_row = QWidget()
        self.results_row.setLayout(results)
        results.setContentsMargins(0, 0, 0, 0)
        root.addWidget(self.results_row)
        root.addStretch(1)

        # wiring
        self.analyze_button.clicked.connect(self.on_analyze)
        self.url_input.returnPressed.connect(self.on_analyze)
        self.download_button.clicked.connect(self.on_download)
        self.cancel_button.clicked.connect(self.on_cancel)
        self.browse_button.clicked.connect(self.on_browse)
        self.open_file_button.clicked.connect(self.on_open_file)
        self.open_folder_button.clicked.connect(self.on_open_folder)
        self.mp4_radio.toggled.connect(self._sync_format)
        self.bitrate_combo.currentIndexChanged.connect(self._sync_format)

        self.setTabOrder(self.url_input, self.analyze_button)
        self.setTabOrder(self.analyze_button, self.mp4_radio)
        self.setTabOrder(self.mp4_radio, self.mp3_radio)
        self.setTabOrder(self.mp3_radio, self.quality_combo)
        self.setTabOrder(self.quality_combo, self.bitrate_combo)
        self.setTabOrder(self.bitrate_combo, self.browse_button)
        self.setTabOrder(self.browse_button, self.download_button)
        self.setTabOrder(self.download_button, self.cancel_button)

    @staticmethod
    def _caption(text: str) -> QLabel:
        label = QLabel(text.upper())
        label.setStyleSheet("color: palette(mid); font-size: 10px;")
        return label

    def _build_menu(self) -> None:
        file_menu = self.menuBar().addMenu("&File")
        settings_action = QAction("&Settings...", self)
        settings_action.setObjectName("settingsAction")
        settings_action.triggered.connect(self.on_settings)
        quit_action = QAction("E&xit", self)
        quit_action.triggered.connect(self.close)
        file_menu.addAction(settings_action)
        file_menu.addSeparator()
        file_menu.addAction(quit_action)
        help_menu = self.menuBar().addMenu("&Help")
        about_action = QAction("&About", self)
        about_action.setObjectName("aboutAction")
        about_action.triggered.connect(self.on_about)
        log_action = QAction("Open &log folder", self)
        log_action.triggered.connect(lambda: file_service.open_folder(paths.data_dir() / "logs"))
        help_menu.addAction(log_action)
        help_menu.addAction(about_action)

    def _apply_settings(self) -> None:
        s = self.settings.settings
        self.folder_input.setText(str(s.output_path()))
        (self.mp3_radio if s.default_format == "mp3" else self.mp4_radio).setChecked(True)
        index = self.bitrate_combo.findData(s.default_bitrate)
        self.bitrate_combo.setCurrentIndex(max(0, index))
        self._sync_format()

    # ------------------------------------------------------------------ state

    def _set_state(self, state: str) -> None:
        """idle | analyzing | ready | downloading | done"""
        self.state = state
        busy_analyzing = state == "analyzing"
        downloading = state == "downloading"
        have_info = self.info is not None and state in ("ready", "downloading", "done")

        self.url_input.setEnabled(not busy_analyzing and not downloading)
        self.analyze_button.setEnabled(not busy_analyzing and not downloading)
        self.info_box.setVisible(have_info)
        self.options_box.setVisible(have_info)
        self.options_box.setEnabled(have_info and not downloading)
        self.browse_button.setEnabled(not downloading)
        self.download_button.setVisible(have_info)
        self.download_button.setEnabled(state in ("ready", "done"))
        self.cancel_button.setVisible(downloading)
        self.cancel_button.setEnabled(downloading and not self._cancelling)
        self.progress_bar.setVisible(downloading or state == "done")
        self.stats_label.setVisible(downloading)
        self.results_row.setVisible(state == "done" and self.last_file is not None)
        if state in ("idle", "ready", "analyzing"):
            self.progress_bar.setRange(0, 100)
            self.progress_bar.setValue(0)

    def _sync_format(self, *_args) -> None:
        mp3 = self.mp3_radio.isChecked()
        self.quality_caption.setVisible(not mp3)
        self.quality_combo.setVisible(not mp3)
        self.bitrate_caption.setVisible(mp3)
        self.bitrate_combo.setVisible(mp3)
        self.bitrate_note.setVisible(mp3)
        note = ""
        if mp3 and self.info and self.info.source_audio_bitrate:
            source = self.info.source_audio_bitrate
            chosen = int(self.bitrate_combo.currentData() or 0)
            note = f"The source audio is about {source} kbps."
            if chosen > source + 8:
                note += f" Converting to {chosen} kbps will not improve its quality."
        self.bitrate_note.setText(note)

    # ------------------------------------------------------------------ analyze

    def on_analyze(self) -> None:
        if self.state in ("analyzing", "downloading"):
            return
        url = self.url_input.text().strip()
        self.url_error.setText("")
        self.last_file = None
        if not url:
            self.url_error.setText(MSG_INVALID_URL)
            return
        # cheap local validation first: invalid URLs never start a process
        from ..core import security

        try:
            security.normalize_url(url)
        except Exception as exc:  # AppError subclasses carry a friendly message
            self.url_error.setText(getattr(exc, "message", MSG_INVALID_URL))
            return
        self.info = None
        self.status_label.setText("Analyzing...")
        self._set_state("analyzing")
        worker = AnalyzeWorker(self.service, url, self)
        worker.succeeded.connect(self._on_analysis_ok)
        worker.failed.connect(self._on_analysis_failed)
        worker.finished.connect(self._on_analyze_worker_finished)
        self._analyze_worker = worker
        worker.start()

    def _on_analyze_worker_finished(self) -> None:
        worker, self._analyze_worker = self._analyze_worker, None
        if worker is not None:
            worker.deleteLater()

    def _on_analysis_ok(self, info: VideoInfo, thumbnail: bytes | None) -> None:
        self.info = info
        self.title_label.setText(info.title)
        self.duration_label.setText(info.duration_formatted)
        self.uploader_label.setText(info.uploader or "Unknown")
        pixmap = QPixmap()
        if thumbnail and pixmap.loadFromData(thumbnail):
            self.thumb.setPixmap(pixmap.scaled(self.thumb.size(), Qt.AspectRatioMode.KeepAspectRatio, Qt.TransformationMode.SmoothTransformation))
        else:
            self.thumb.clear()
            self.thumb.setText("No thumbnail")
        self.mp4_radio.setEnabled(info.has_video)
        self.mp3_radio.setEnabled(info.has_audio)
        if not info.has_video and info.has_audio:
            self.mp3_radio.setChecked(True)
        self.quality_combo.clear()
        for q in info.qualities:
            self.quality_combo.addItem("Best available" if q == "best" else q, q)
        default_q = self.quality_combo.findData(self.settings.settings.default_quality)
        self.quality_combo.setCurrentIndex(max(0, default_q))
        self.status_label.setText("Choose a format and press Download.")
        self._sync_format()
        self._set_state("ready")

    def _on_analysis_failed(self, message: str, _code: str) -> None:
        self.info = None
        self.url_error.setText(message)
        self.status_label.setText("Ready.")
        self._set_state("idle")

    # ------------------------------------------------------------------ download

    def on_browse(self) -> None:
        chosen = QFileDialog.getExistingDirectory(self, "Choose output folder", self.folder_input.text())
        if chosen:
            self.folder_input.setText(chosen)
            self.settings.update(output_dir=chosen)

    def on_download(self) -> None:
        if self.info is None or self.state not in ("ready", "done"):
            return
        fmt = "mp3" if self.mp3_radio.isChecked() else "mp4"
        quality = str(self.quality_combo.currentData() or "best")
        bitrate = int(self.bitrate_combo.currentData() or 192)
        output_dir = Path(self.folder_input.text())
        try:
            file_service.ensure_writable_dir(output_dir)
        except Exception as exc:
            self.status_label.setText(getattr(exc, "message", "The selected output folder is not writable."))
            return
        self.last_file = None
        self._cancelling = False
        self.progress_bar.setRange(0, 0)  # busy until the first real number arrives
        self.status_label.setText("Starting...")
        self.stats_label.setText("")
        self._set_state("downloading")
        worker = DownloadWorker(self.service, self.info, fmt, quality, bitrate, output_dir, self)
        worker.progress.connect(self._on_progress)
        worker.succeeded.connect(self._on_done)
        worker.failed.connect(self._on_failed)
        worker.cancelled.connect(self._on_cancelled)
        worker.finished.connect(self._on_worker_finished)
        self._download_worker = worker
        worker.start()

    def _on_progress(self, p: Progress) -> None:
        if self._cancelling:
            return
        if p.stage == "analyzing":
            self.status_label.setText("Checking the video...")
        elif p.stage == "processing":
            self.status_label.setText("Processing media...")
        elif p.stage == "finished":
            self.status_label.setText("Finishing...")
        else:
            self.status_label.setText("Downloading...")

        if p.indeterminate or p.percent is None:
            self.progress_bar.setRange(0, 0)  # honest busy indicator, no fake percentage
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
        self._set_state("done")

    def _on_failed(self, message: str, _code: str) -> None:
        self.status_label.setText(message)
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self._cancelling = False
        self._set_state("ready")

    def _on_cancelled(self) -> None:
        self.status_label.setText("Download cancelled.")
        self.progress_bar.setRange(0, 100)
        self.progress_bar.setValue(0)
        self._cancelling = False
        self._set_state("ready")

    def _on_worker_finished(self) -> None:
        worker, self._download_worker = self._download_worker, None
        if worker is not None:
            worker.deleteLater()

    def on_cancel(self) -> None:
        if self._download_worker is None or self._cancelling:
            return
        self._cancelling = True
        self.cancel_button.setEnabled(False)
        self.status_label.setText("Cancelling...")
        self._download_worker.request_cancel()

    # ------------------------------------------------------------------ misc actions

    def on_open_file(self) -> None:
        if self.last_file and self.last_file.exists():
            file_service.open_file(self.last_file)

    def on_open_folder(self) -> None:
        target = self.last_file.parent if self.last_file else Path(self.folder_input.text())
        if self.last_file and self.last_file.exists():
            file_service.reveal_file(self.last_file)
        elif target.exists():
            QDesktopServices.openUrl(QUrl.fromLocalFile(str(target)))

    def on_settings(self) -> None:
        if SettingsDialog(self.settings, self).exec():
            self._apply_settings()

    def on_about(self) -> None:
        AboutDialog(self.service, self).exec()

    # ------------------------------------------------------------------ shutdown

    def is_downloading(self) -> bool:
        return self._download_worker is not None and self._download_worker.isRunning()

    def closeEvent(self, event: QCloseEvent) -> None:  # noqa: N802 - Qt API
        if self.is_downloading():
            answer = QMessageBox.question(
                self, APP_NAME, "A download is in progress. Cancel it and exit?",
                QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No, QMessageBox.StandardButton.No,
            )
            if answer != QMessageBox.StandardButton.Yes:
                event.ignore()
                return
            self._cancelling = True
            self._download_worker.request_cancel()
            self._download_worker.wait(15000)
        if self._analyze_worker is not None and self._analyze_worker.isRunning():
            self._analyze_worker.wait(15000)
        logging_service.logging.getLogger("ytd.ui").info("window closed")
        event.accept()


def create_application(argv: list[str]) -> QApplication:
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName(APP_NAME)
    app.setStyle("Fusion")
    return app
