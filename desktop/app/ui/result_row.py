"""One search result: thumbnail, title, uploader/duration, MP3/MP4 choice and a Download button."""
from __future__ import annotations

from PySide6.QtCore import Qt, Signal
from PySide6.QtGui import QPixmap
from PySide6.QtWidgets import QButtonGroup, QFrame, QHBoxLayout, QLabel, QPushButton, QRadioButton, QVBoxLayout, QWidget

from ..core.media_downloader import SearchResult


class ResultRow(QFrame):
    #: (SearchResult, "mp3" | "mp4")
    download_requested = Signal(object, str)

    def __init__(self, result: SearchResult, thumbnail: bytes | None = None, parent: QWidget | None = None) -> None:
        super().__init__(parent)
        self.result = result
        self.setObjectName("resultRow")
        self.setFrameShape(QFrame.Shape.StyledPanel)

        self.thumb = QLabel()
        self.thumb.setObjectName("resultThumb")
        self.thumb.setFixedSize(128, 72)
        self.thumb.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.thumb.setAccessibleName("Thumbnail")
        self.set_thumbnail(thumbnail)

        self.title = QLabel(result.title)
        self.title.setObjectName("resultTitle")
        self.title.setWordWrap(True)
        self.title.setStyleSheet("font-weight: 600;")
        self.title.setTextInteractionFlags(Qt.TextInteractionFlag.TextSelectableByMouse)
        meta = " · ".join(x for x in (result.uploader, result.duration_formatted) if x)
        self.meta = QLabel(meta)
        self.meta.setObjectName("resultMeta")
        self.meta.setStyleSheet("color: palette(mid);")
        text = QVBoxLayout()
        text.addWidget(self.title)
        text.addWidget(self.meta)
        text.addStretch(1)

        # format choice: two radio buttons (keyboard + screen-reader friendly), MP3 is the default for songs
        self.mp3_radio = QRadioButton("MP3 (audio)")
        self.mp3_radio.setObjectName("resultMp3")
        self.mp3_radio.setAccessibleName(f"MP3 for {result.title}")
        self.mp3_radio.setChecked(True)
        self.mp4_radio = QRadioButton("MP4 (video)")
        self.mp4_radio.setObjectName("resultMp4")
        self.mp4_radio.setAccessibleName(f"MP4 for {result.title}")
        self.format_group = QButtonGroup(self)
        self.format_group.addButton(self.mp3_radio)
        self.format_group.addButton(self.mp4_radio)
        self.download_button = QPushButton("Download")
        self.download_button.setObjectName("resultDownload")
        self.download_button.setAccessibleName(f"Download {result.title}")
        actions = QVBoxLayout()
        actions.addWidget(self.mp3_radio)
        actions.addWidget(self.mp4_radio)
        actions.addWidget(self.download_button)
        actions.addStretch(1)

        layout = QHBoxLayout(self)
        layout.addWidget(self.thumb)
        layout.addLayout(text, 1)
        layout.addLayout(actions)

        self.download_button.clicked.connect(self._emit)

    def set_thumbnail(self, data: bytes | None) -> None:
        pixmap = QPixmap()
        if data and pixmap.loadFromData(data):
            self.thumb.setPixmap(pixmap.scaled(self.thumb.size(), Qt.AspectRatioMode.KeepAspectRatio, Qt.TransformationMode.SmoothTransformation))
        else:
            self.thumb.clear()

    def current_format(self) -> str:
        return "mp4" if self.mp4_radio.isChecked() else "mp3"

    def _emit(self) -> None:
        self.download_requested.emit(self.result, self.current_format())
        # brief feedback; the button stays usable (the same song may be added again, e.g. as MP4 after MP3)
        self.download_button.setText("Added")
        self.download_button.setEnabled(False)
        from PySide6.QtCore import QTimer

        QTimer.singleShot(1200, self._reset_button)

    def _reset_button(self) -> None:
        try:
            self.download_button.setText("Download")
            self.download_button.setEnabled(True)
        except RuntimeError:  # row was deleted meanwhile
            pass
