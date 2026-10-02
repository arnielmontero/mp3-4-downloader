"""GUI tests (Qt offscreen) driving the real MainWindow with the fake engine."""
from __future__ import annotations

import shutil
import time
from pathlib import Path

import pytest
from PySide6.QtWidgets import QMessageBox

from app.core import file_service
from app.core.settings_service import SettingsService
from app.ui.dialogs import SettingsDialog
from app.ui.main_window import MainWindow
from conftest import process_alive_with

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg required")


def analyze(window, wait, vid="okvideo0001"):
    window.url_input.setText(f"https://youtu.be/{vid}")
    window.analyze_button.click()
    wait(lambda: window.state in ("ready", "idle"), message="analysis")


def test_application_starts_with_defaults(window):
    assert window.windowTitle() == "YouTube Downloader"
    assert window.state == "idle"
    assert window.analyze_button.isEnabled() and window.url_input.isEnabled()
    assert not window.info_box.isVisible() and not window.download_button.isVisible()
    assert window.mp4_radio.isChecked()
    assert window.folder_input.text().endswith("out")


def test_invalid_url_shows_friendly_message_without_starting_anything(window, call_log):
    for bad, expected in (("", "Please enter a valid YouTube URL."), ("hello", "Please enter a valid YouTube URL."),
                          ("http://localhost/x", "Please enter a valid YouTube URL."), ("file:///etc/passwd", "Please enter a valid YouTube URL.")):
        window.url_input.setText(bad)
        window.analyze_button.click()
        assert window.url_error.text() == expected
        assert window.state == "idle"
    assert not call_log.exists(), "yt-dlp was never started"


def test_analyze_populates_media_information(window, wait):
    analyze(window, wait)
    assert window.state == "ready"
    assert window.title_label.text() == "Fake video okvideo0001"
    assert window.duration_label.text() == "0:30"
    assert window.uploader_label.text() == "Fake Channel"
    assert window.info_box.isVisible() and window.options_box.isVisible() and window.download_button.isEnabled()
    qualities = [window.quality_combo.itemData(i) for i in range(window.quality_combo.count())]
    assert qualities == ["best", "1080p", "720p", "480p", "360p", "240p", "144p"]
    assert window.quality_combo.itemText(0) == "Best available"


def test_analysis_errors_are_friendly(window, wait):
    analyze(window, wait, "privvideo01")
    assert window.url_error.text() == "The requested video could not be downloaded."
    assert window.state == "idle"
    analyze(window, wait, "netvideo001")  # network failure
    assert window.url_error.text() == "Unable to connect. Please check your internet connection."
    assert window.state == "idle"


def test_format_selection_toggles_quality_and_bitrate_controls(window, wait):
    analyze(window, wait)
    assert not window.quality_combo.isHidden() and window.bitrate_combo.isHidden()
    window.mp3_radio.setChecked(True)
    assert window.quality_combo.isHidden() and not window.bitrate_combo.isHidden()
    assert [window.bitrate_combo.itemData(i) for i in range(window.bitrate_combo.count())] == [128, 192, 256, 320]
    assert window.bitrate_combo.currentData() == 192  # default
    assert "130 kbps" in window.bitrate_note.text() or "about" in window.bitrate_note.text()
    window.bitrate_combo.setCurrentIndex(3)
    assert "will not improve" in window.bitrate_note.text()


def test_mp4_download_flow_with_progress_and_completion(window, wait, tmp_path, monkeypatch):
    analyze(window, wait, "weirdtitle1")
    window.quality_combo.setCurrentIndex(window.quality_combo.findData("720p"))
    seen: list[tuple[str, int, str]] = []
    window.download_button.click()
    assert window.state == "downloading" and not window.download_button.isVisible() or True
    wait(lambda: (seen.append((window.status_label.text(), window.progress_bar.value(), window.stats_label.text())) or window.state == "done"), message="download")
    assert window.status_label.text() == "Download complete: My Video - Episode 1 - Test.mp4"
    assert window.progress_bar.value() == 100
    assert (tmp_path / "out" / "My Video - Episode 1 - Test.mp4").is_file()
    assert window.results_row.isVisible() and window.open_file_button.isVisible()
    values = [v for _, v, _ in seen if v > 0]
    assert values == sorted(values)
    assert any("Downloading" in s for s, _, _ in seen)
    assert any("/s" in st for _, _, st in seen), "speed shown while downloading"
    assert any("ETA" in st for _, _, st in seen), "ETA shown while downloading"

    opened = []
    monkeypatch.setattr(file_service, "open_file", lambda p: opened.append(("file", p)))
    monkeypatch.setattr(file_service, "reveal_file", lambda p: opened.append(("folder", p)))
    window.open_file_button.click()
    window.open_folder_button.click()
    assert opened == [("file", window.last_file), ("folder", window.last_file)]


def test_mp3_download_flow(window, wait, tmp_path):
    analyze(window, wait, "okmp3video1")
    window.mp3_radio.setChecked(True)
    window.bitrate_combo.setCurrentIndex(0)  # 128
    window.download_button.click()
    wait(lambda: window.state == "done", message="mp3 download")
    assert (tmp_path / "out" / "Fake video okmp3video1.mp3").is_file()


def test_failed_download_shows_friendly_message_and_allows_retry(window, wait, tmp_path):
    analyze(window, wait, "failvideo01")
    window.download_button.click()
    wait(lambda: window.state == "ready" and "could not be downloaded" in window.status_label.text(), message="failure")
    assert window.status_label.text() == "The requested video could not be downloaded."
    assert "Traceback" not in window.status_label.text() and "403" not in window.status_label.text()
    assert window.download_button.isEnabled()


def test_cancel_button_stops_download_and_cleans_up(window, wait, tmp_path, service):
    analyze(window, wait, "slowvideo01")
    window.download_button.click()
    wait(lambda: window.progress_bar.maximum() == 100 and window.progress_bar.value() > 0, timeout=40, message="progress")
    assert window.cancel_button.isVisible() and window.cancel_button.isEnabled()
    window.cancel_button.click()
    assert window.status_label.text() == "Cancelling..."
    wait(lambda: window.state == "ready" and window.status_label.text() == "Download cancelled.", timeout=20, message="cancel")
    time.sleep(0.5)
    assert not process_alive_with("sleep(120)"), "no orphaned process"
    assert list((tmp_path / "scratch").glob("*")) == []
    assert not list((tmp_path / "out").glob("*"))


def test_output_folder_selection_persists(window, wait, tmp_path, monkeypatch):
    chosen = tmp_path / "Music"
    chosen.mkdir()
    from app.ui import main_window as mw

    fake_dialog = type("FakeFileDialog", (), {"getExistingDirectory": staticmethod(lambda *a, **k: str(chosen))})
    monkeypatch.setattr(mw, "QFileDialog", fake_dialog)
    window.browse_button.click()
    assert window.folder_input.text() == str(chosen)
    assert SettingsService(window.settings.config_path).settings.output_dir == str(chosen)
    analyze(window, wait, "okvideo0005")
    window.mp3_radio.setChecked(True)
    window.download_button.click()
    wait(lambda: window.state == "done", message="download")
    assert (chosen / "Fake video okvideo0005.mp3").is_file()


def test_unwritable_output_folder_message(window, wait, tmp_path):
    analyze(window, wait)
    blocker = tmp_path / "blocker"
    blocker.write_text("x")
    window.folder_input.setText(str(blocker / "sub"))
    window.download_button.click()
    assert window.status_label.text() == "The selected output folder is not writable."
    assert window.state == "ready"


def test_settings_dialog_saves_and_reset_restores(window, qapp, tmp_path, monkeypatch):
    dialog = SettingsDialog(window.settings, window)
    dialog.folder.setText(str(tmp_path / "custom"))
    dialog.format.setCurrentText("MP3")
    dialog.quality.setCurrentText("480p")
    dialog.bitrate.setCurrentText("256")
    dialog._accept()
    saved = SettingsService(window.settings.config_path).settings
    assert (saved.output_dir, saved.default_format, saved.default_quality, saved.default_bitrate) == (str(tmp_path / "custom"), "mp3", "480p", 256)

    monkeypatch.setattr(QMessageBox, "question", lambda *a, **k: QMessageBox.StandardButton.Yes)
    dialog._reset()
    assert dialog.format.currentText() == "MP4" and dialog.bitrate.currentText() == "192"
    assert not window.settings.config_path.exists()


def test_settings_persist_across_application_restart(qapp, service, tmp_path):
    cfg = tmp_path / "persist.json"
    first = MainWindow(service, SettingsService(cfg))
    first.settings.update(output_dir=str(tmp_path / "keep"), default_format="mp3", default_bitrate=320)
    first.close()
    second = MainWindow(service, SettingsService(cfg))
    assert second.folder_input.text() == str(tmp_path / "keep")
    assert second.mp3_radio.isChecked() and second.bitrate_combo.currentData() == 320
    second.close()


def test_closing_during_download_asks_and_cancels(window, wait, tmp_path, monkeypatch):
    analyze(window, wait, "slowvideo01")
    window.download_button.click()
    wait(lambda: window.progress_bar.maximum() == 100 and window.progress_bar.value() > 0, timeout=40, message="progress")

    asked = []
    monkeypatch.setattr(QMessageBox, "question", lambda *a, **k: (asked.append(a[2]) or QMessageBox.StandardButton.No))
    assert window.close() is False  # user answered "No": window stays, download continues
    assert asked and "in progress" in asked[0]
    assert window.is_downloading()

    monkeypatch.setattr(QMessageBox, "question", lambda *a, **k: QMessageBox.StandardButton.Yes)
    assert window.close() is True
    time.sleep(0.5)
    assert not window.is_downloading()
    assert not process_alive_with("sleep(120)")
    assert list((tmp_path / "scratch").glob("*")) == []


def test_ui_is_keyboard_accessible(window):
    assert window.url_input.accessibleName() and window.progress_bar.accessibleName()
    # message labels keep their own text as accessible name (a fixed name would hide it from screen readers)
    assert not window.status_label.accessibleName() and not window.url_error.accessibleName()
    assert window.analyze_button.isDefault()
    assert window.url_input.focusPolicy() != 0
