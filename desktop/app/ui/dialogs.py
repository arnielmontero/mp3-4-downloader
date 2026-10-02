"""Settings and About dialogs."""
from __future__ import annotations

from PySide6.QtWidgets import (
    QComboBox,
    QDialog,
    QDialogButtonBox,
    QFileDialog,
    QFormLayout,
    QHBoxLayout,
    QLabel,
    QLineEdit,
    QMessageBox,
    QPushButton,
    QVBoxLayout,
    QWidget,
)

from .. import APP_NAME, __version__
from ..core.security import BITRATES, QUALITIES
from ..core.settings_service import SettingsService
from ..core.youtube_service import YouTubeService
from .workers import VersionWorker


class SettingsDialog(QDialog):
    def __init__(self, settings: SettingsService, parent: QWidget | None = None) -> None:
        super().__init__(parent)
        self.setWindowTitle("Settings")
        self.setMinimumWidth(480)
        self._settings = settings
        s = settings.settings

        self.folder = QLineEdit(s.output_dir)
        self.folder.setObjectName("settingsFolder")
        self.folder.setAccessibleName("Default output folder")
        browse = QPushButton("Browse...")
        browse.clicked.connect(self._browse)
        row = QHBoxLayout()
        row.addWidget(self.folder, 1)
        row.addWidget(browse)

        self.format = QComboBox()
        self.format.setObjectName("settingsFormat")
        self.format.addItems(["MP4", "MP3"])
        self.format.setCurrentText(s.default_format.upper())
        self.quality = QComboBox()
        self.quality.setObjectName("settingsQuality")
        self.quality.addItems(QUALITIES)
        self.quality.setCurrentText(s.default_quality)
        self.bitrate = QComboBox()
        self.bitrate.setObjectName("settingsBitrate")
        self.bitrate.addItems([str(b) for b in BITRATES])
        self.bitrate.setCurrentText(str(s.default_bitrate))

        form = QFormLayout()
        form.addRow("Output folder:", row)
        form.addRow("Default format:", self.format)
        form.addRow("Default video quality:", self.quality)
        form.addRow("Default MP3 bitrate (kbps):", self.bitrate)

        reset = QPushButton("Reset Settings")
        reset.setObjectName("resetSettingsButton")
        reset.clicked.connect(self._reset)

        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Ok | QDialogButtonBox.StandardButton.Cancel)
        buttons.accepted.connect(self._accept)
        buttons.rejected.connect(self.reject)

        footer = QHBoxLayout()
        footer.addWidget(reset)
        footer.addStretch(1)
        footer.addWidget(buttons)

        layout = QVBoxLayout(self)
        layout.addLayout(form)
        layout.addLayout(footer)

    def _browse(self) -> None:
        chosen = QFileDialog.getExistingDirectory(self, "Choose output folder", self.folder.text())
        if chosen:
            self.folder.setText(chosen)

    def _accept(self) -> None:
        self._settings.update(
            output_dir=self.folder.text().strip(),
            default_format=self.format.currentText().lower(),
            default_quality=self.quality.currentText(),
            default_bitrate=int(self.bitrate.currentText()),
        )
        self.accept()

    def _reset(self) -> None:
        answer = QMessageBox.question(self, "Reset Settings", "Restore all settings to their defaults?")
        if answer == QMessageBox.StandardButton.Yes:
            s = self._settings.reset()
            self.folder.setText(s.output_dir)
            self.format.setCurrentText(s.default_format.upper())
            self.quality.setCurrentText(s.default_quality)
            self.bitrate.setCurrentText(str(s.default_bitrate))


class AboutDialog(QDialog):
    def __init__(self, service: YouTubeService, parent: QWidget | None = None) -> None:
        super().__init__(parent)
        self.setWindowTitle(f"About {APP_NAME}")
        self.setMinimumWidth(360)
        self.title = QLabel(f"<h3>{APP_NAME}</h3>Version {__version__}")
        self.engine = QLabel("Media engine:\nyt-dlp ...")
        self.engine.setObjectName("aboutEngine")
        self.ffmpeg = QLabel("FFmpeg:\n...")
        self.ffmpeg.setObjectName("aboutFfmpeg")
        note = QLabel("Uses yt-dlp (Unlicense) and FFmpeg (GPL/LGPL). Only download content you are authorized to download.")
        note.setWordWrap(True)
        buttons = QDialogButtonBox(QDialogButtonBox.StandardButton.Close)
        buttons.rejected.connect(self.reject)
        buttons.accepted.connect(self.accept)
        buttons.button(QDialogButtonBox.StandardButton.Close).clicked.connect(self.accept)

        layout = QVBoxLayout(self)
        for widget in (self.title, self.engine, self.ffmpeg, note, buttons):
            layout.addWidget(widget)

        self._worker = VersionWorker(service, self)
        self._worker.done.connect(self._on_versions)
        self._worker.start()

    def _on_versions(self, versions: dict) -> None:
        self.engine.setText(f"Media engine:\nyt-dlp {versions.get('yt_dlp') or 'not found'}")
        self.ffmpeg.setText(f"FFmpeg:\n{versions.get('ffmpeg') or 'not found'}")

    def done(self, result: int) -> None:  # make sure the helper thread is finished before the dialog dies
        self._worker.wait(5000)
        super().done(result)
