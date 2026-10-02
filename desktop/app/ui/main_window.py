"""Main window: search on the main display, downloads in the right-hand sidebar (any number at once)."""
from __future__ import annotations

import logging
from pathlib import Path

from PySide6.QtCore import Qt
from PySide6.QtGui import QAction, QCloseEvent
from PySide6.QtWidgets import (
    QApplication,
    QFileDialog,
    QFrame,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QMainWindow,
    QMessageBox,
    QPushButton,
    QScrollArea,
    QVBoxLayout,
    QWidget,
)

from .. import APP_NAME, AUTHOR
from ..core import file_service, logging_service, paths
from ..core.media_downloader import SearchResult
from ..core.settings_service import SettingsService
from ..core.youtube_service import YouTubeService
from .dialogs import AboutDialog, SettingsDialog
from .download_item import DownloadItem
from .result_row import ResultRow
from .workers import SearchWorker

log = logging.getLogger("ytd.ui")

SIDEBAR_WIDTH = 380


class MainWindow(QMainWindow):
    #: how many downloads run at once; further ones wait in the sidebar and start automatically
    MAX_PARALLEL = 3

    def __init__(self, service: YouTubeService, settings: SettingsService) -> None:
        super().__init__()
        self.service = service
        self.settings = settings
        self.items: list[DownloadItem] = []
        self.rows: list[ResultRow] = []
        self._search_worker: SearchWorker | None = None

        self.setWindowTitle(APP_NAME)
        self.setMinimumSize(980, 600)
        self._build_ui()
        self._build_menu()
        self._apply_settings()
        self._update_sidebar()

    # ------------------------------------------------------------------ construction

    def _build_ui(self) -> None:
        central = QWidget()
        self.setCentralWidget(central)
        outer = QHBoxLayout(central)
        outer.setSpacing(12)
        outer.addWidget(self._build_main_display(), 1)
        outer.addWidget(self._build_sidebar())

        self.search_button.clicked.connect(self.on_search)
        self.search_input.returnPressed.connect(self.on_search)
        self.browse_button.clicked.connect(self.on_browse)
        self.setTabOrder(self.search_input, self.search_button)
        self.setTabOrder(self.search_button, self.browse_button)

    def _build_main_display(self) -> QWidget:
        page = QWidget()
        root = QVBoxLayout(page)
        root.setContentsMargins(0, 0, 0, 0)
        root.addWidget(QLabel("<h2>Find a song or video</h2>"))

        caption = QLabel("Search YouTube (or paste a link)")
        self.search_input = QLineEdit()
        self.search_input.setObjectName("searchInput")
        self.search_input.setPlaceholderText("Artist, song or https://www.youtube.com/watch?v=...")
        self.search_input.setClearButtonEnabled(True)
        self.search_input.setAccessibleName("Search YouTube")
        caption.setBuddy(self.search_input)
        self.search_button = QPushButton("Search")
        self.search_button.setObjectName("searchButton")
        self.search_button.setDefault(True)
        row = QHBoxLayout()
        row.addWidget(self.search_input, 1)
        row.addWidget(self.search_button)
        root.addWidget(caption)
        root.addLayout(row)

        self.search_error = QLabel("")
        self.search_error.setObjectName("searchError")
        self.search_error.setStyleSheet("color: #b00020;")
        self.search_error.setWordWrap(True)
        self.results_info = QLabel("")
        self.results_info.setObjectName("resultsInfo")
        root.addWidget(self.search_error)
        root.addWidget(self.results_info)

        self.results_container = QWidget()
        self.results_layout = QVBoxLayout(self.results_container)
        self.results_layout.setContentsMargins(0, 0, 0, 0)
        self.results_layout.addStretch(1)
        scroll = QScrollArea()
        scroll.setObjectName("resultsArea")
        scroll.setWidgetResizable(True)
        scroll.setWidget(self.results_container)
        root.addWidget(scroll, 1)

        self.status_label = QLabel("Type what you are looking for and press Search.")
        self.status_label.setObjectName("statusLabel")
        self.status_label.setWordWrap(True)
        root.addWidget(self.status_label)

        # output folder: always visible
        self.folder_input = QLineEdit()
        self.folder_input.setObjectName("folderInput")
        self.folder_input.setReadOnly(True)
        self.folder_input.setAccessibleName("Output folder")
        self.browse_button = QPushButton("Browse...")
        self.browse_button.setObjectName("browseButton")
        folder_caption = QLabel("Save to:")
        folder_caption.setBuddy(self.browse_button)
        folder = QHBoxLayout()
        folder.addWidget(folder_caption)
        folder.addWidget(self.folder_input, 1)
        folder.addWidget(self.browse_button)
        root.addLayout(folder)
        credit = QLabel(f"{APP_NAME} · Developed by {AUTHOR}")
        credit.setObjectName("creditLabel")
        credit.setStyleSheet("color: palette(mid); font-size: 10px;")
        root.addWidget(credit)
        return page

    def _build_sidebar(self) -> QWidget:
        side = QFrame()
        side.setObjectName("sidebar")
        side.setFrameShape(QFrame.Shape.StyledPanel)
        side.setFixedWidth(SIDEBAR_WIDTH)
        layout = QVBoxLayout(side)
        self.jobs_title = QLabel("<h3>Downloads</h3>")
        self.jobs_title.setObjectName("jobsTitle")
        self.jobs_empty = QLabel("Nothing downloading.\nPress Download on a search result.")
        self.jobs_empty.setObjectName("jobsEmpty")
        self.jobs_empty.setStyleSheet("color: palette(mid);")
        self.jobs_container = QWidget()
        self.jobs_layout = QVBoxLayout(self.jobs_container)
        self.jobs_layout.setContentsMargins(0, 0, 0, 0)
        self.jobs_layout.addStretch(1)
        scroll = QScrollArea()
        scroll.setObjectName("jobsArea")
        scroll.setWidgetResizable(True)
        scroll.setFrameShape(QFrame.Shape.NoFrame)
        scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAlwaysOff)
        scroll.setWidget(self.jobs_container)
        layout.addWidget(self.jobs_title)
        layout.addWidget(self.jobs_empty)
        layout.addWidget(scroll, 1)
        return side

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
        self.folder_input.setText(str(self.settings.settings.output_path()))

    # ------------------------------------------------------------------ search

    def on_search(self) -> None:
        if self._search_worker is not None:
            return
        text = self.search_input.text().strip()
        self.search_error.setText("")
        if not text:
            self.search_error.setText("Please type something to search for.")
            return
        # cheap local validation first: invalid input never starts a process
        from ..core import security

        try:
            if security.looks_like_url(text):
                security.normalize_url(text)
            else:
                security.validate_search_query(text)
        except Exception as exc:  # AppError subclasses carry a friendly message
            self.search_error.setText(getattr(exc, "message", "Please enter a valid YouTube URL."))
            return

        self._clear_results()
        self.results_info.setText("")
        self.status_label.setText("Searching...")
        self.search_button.setEnabled(False)
        self.search_input.setEnabled(False)
        worker = SearchWorker(self.service, text, self)
        worker.succeeded.connect(self._on_search_ok)
        worker.failed.connect(self._on_search_failed)
        worker.finished.connect(self._on_search_thread_finished)
        self._search_worker = worker
        worker.start()

    def _on_search_thread_finished(self) -> None:
        worker, self._search_worker = self._search_worker, None
        self.search_button.setEnabled(True)
        self.search_input.setEnabled(True)
        if worker is not None:
            worker.deleteLater()

    def _clear_results(self) -> None:
        for row in self.rows:
            row.setParent(None)
            row.deleteLater()
        self.rows = []

    def _on_search_ok(self, results: list[SearchResult], thumbnails: dict) -> None:
        self._clear_results()
        for r in results:
            row = ResultRow(r, thumbnails.get(r.video_id), self.results_container)
            row.download_requested.connect(self.on_download_requested)
            self.rows.append(row)
            self.results_layout.insertWidget(self.results_layout.count() - 1, row)
        self.results_info.setText(f"{len(results)} result{'s' if len(results) != 1 else ''}" if results else "No results found.")
        self.status_label.setText("Press Download on a result to add it to the downloads list." if results else "Try different words.")

    def _on_search_failed(self, message: str, _code: str) -> None:
        self.search_error.setText(message)
        self.status_label.setText("Ready.")

    # ------------------------------------------------------------------ downloads

    def on_browse(self) -> None:
        chosen = QFileDialog.getExistingDirectory(self, "Choose output folder", self.folder_input.text())
        if chosen:
            self.folder_input.setText(chosen)
            self.settings.update(output_dir=chosen)

    def on_download_requested(self, result: SearchResult, fmt: str) -> None:
        s = self.settings.settings
        output_dir = Path(self.folder_input.text())
        try:
            file_service.ensure_writable_dir(output_dir)
        except Exception as exc:
            self.status_label.setText(getattr(exc, "message", "The selected output folder is not writable."))
            return
        item = DownloadItem(self.service, result.to_video_info(), fmt, s.default_quality, s.default_bitrate, output_dir, self.jobs_container)
        item.finished_job.connect(self._on_item_finished)
        item.dismissed.connect(self._on_item_dismissed)
        self.items.append(item)
        self.jobs_layout.insertWidget(0, item)
        self.status_label.setText(f"Added to downloads: {result.title}")
        self._update_sidebar()
        self._pump()

    def _pump(self) -> None:
        """Start waiting items (oldest first) while fewer than MAX_PARALLEL are running."""
        running = sum(1 for i in self.items if i.state == DownloadItem.RUNNING)
        for item in self.items:
            if running >= self.MAX_PARALLEL:
                break
            if item.state == DownloadItem.QUEUED:
                item.start()
                running += 1

    def _on_item_finished(self, _item: DownloadItem) -> None:
        self._pump()
        self._update_sidebar()

    def _on_item_dismissed(self, item: DownloadItem) -> None:
        if item in self.items and not item.is_active():
            self.items.remove(item)
            item.setParent(None)
            item.deleteLater()
            self._update_sidebar()

    def _update_sidebar(self) -> None:
        active = len(self.active_items())
        self.jobs_empty.setVisible(not self.items)
        self.jobs_title.setText(f"<h3>Downloads{f' ({active} active)' if active else ''}</h3>")

    def active_items(self) -> list[DownloadItem]:
        return [i for i in self.items if i.is_active()]

    def is_downloading(self) -> bool:
        return bool(self.active_items())

    # ------------------------------------------------------------------ dialogs

    def on_settings(self) -> None:
        if SettingsDialog(self.settings, self).exec():
            self._apply_settings()

    def on_about(self) -> None:
        AboutDialog(self.service, self).exec()

    # ------------------------------------------------------------------ shutdown

    def closeEvent(self, event: QCloseEvent) -> None:  # noqa: N802 - Qt API
        active = self.active_items()
        if active:
            answer = QMessageBox.question(
                self, APP_NAME, f"{len(active)} download(s) in progress. Cancel them and exit?",
                QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No, QMessageBox.StandardButton.No,
            )
            if answer != QMessageBox.StandardButton.Yes:
                event.ignore()
                return
            for item in active:
                item.cancel()
            for item in active:
                item.wait(15000)
            QApplication.processEvents()  # deliver the workers' final "cancelled" signals
        if self._search_worker is not None and self._search_worker.isRunning():
            self._search_worker.wait(15000)
        logging_service.logging.getLogger("ytd.ui").info("window closed")
        event.accept()


def create_application(argv: list[str]) -> QApplication:
    app = QApplication.instance() or QApplication(argv)
    app.setApplicationName(APP_NAME)
    app.setStyle("Fusion")
    return app
