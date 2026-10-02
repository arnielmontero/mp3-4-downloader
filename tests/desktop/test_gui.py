"""GUI tests (Qt offscreen): search main display + downloads sidebar, driven against the fake engine."""
from __future__ import annotations

import shutil
import time

import pytest
from PySide6.QtWidgets import QFrame, QMessageBox

from app.core import file_service
from app.core.settings_service import SettingsService
from app.ui.dialogs import SettingsDialog
from app.ui.download_item import DownloadItem
from app.ui.main_window import MainWindow
from conftest import process_alive_with

pytestmark = pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg required")


@pytest.fixture(autouse=True)
def no_network_thumbnails(service, monkeypatch):
    monkeypatch.setattr(service, "fetch_thumbnail", lambda url: None)


def search(window, wait, text="never gonna"):
    window.search_input.setText(text)
    window.search_button.click()
    wait(lambda: window._search_worker is None, message="search")


def add(window, row_index=0, fmt="mp3"):
    """Same effect as pressing the row's Download button (that button is briefly disabled after a press)."""
    window.on_download_requested(window.rows[row_index].result, fmt)
    return window.items[-1]


def test_layout_has_search_main_display_and_right_sidebar(window):
    assert window.windowTitle() == "YouTube Downloader"
    sidebar = window.findChild(QFrame, "sidebar")
    assert sidebar is not None and sidebar.isVisible()
    # the sidebar sits to the right of the main display
    assert sidebar.mapTo(window, sidebar.rect().topLeft()).x() > window.search_input.mapTo(window, window.search_input.rect().topLeft()).x()
    assert window.search_button.isEnabled() and window.search_input.isEnabled()
    assert window.jobs_empty.isVisible() and window.items == [] and window.rows == []
    assert window.folder_input.text().endswith("out")


def test_search_shows_results_with_a_download_button_each(window, wait):
    search(window, wait)
    assert len(window.rows) == 4, "5 hits from the engine, the live stream is filtered out"
    titles = [r.title.text() for r in window.rows]
    assert titles[0] == "Result 1 for never gonna"
    assert window.rows[0].meta.text() == "Fake Channel · 0:30"
    assert window.results_info.text() == f"{len(window.rows)} results"
    for row in window.rows:
        assert row.download_button.text() == "Download" and row.download_button.isEnabled()
        assert row.mp3_radio.isChecked() and not row.mp4_radio.isChecked() and row.current_format() == "mp3"


def test_empty_invalid_and_failed_searches_are_friendly(window, wait, call_log):
    window.search_button.click()
    assert window.search_error.text() == "Please type something to search for."
    for bad in ("x" * 101, "http://localhost/x", "file:///etc/passwd"):
        window.search_input.setText(bad)
        window.search_button.click()
        assert window.search_error.text(), bad
        assert window._search_worker is None
    assert not call_log.exists(), "yt-dlp was never started for invalid input"
    search(window, wait, "nothingfound")
    assert window.results_info.text() == "No results found." and window.rows == []
    search(window, wait, "failsearch")
    assert window.search_error.text() == "Unable to connect. Please check your internet connection."


def test_pasted_link_becomes_a_single_result(window, wait):
    search(window, wait, "https://youtu.be/okvideo0001")
    assert len(window.rows) == 1
    assert window.rows[0].title.text() == "Fake video okvideo0001"


def test_download_button_adds_to_sidebar_and_completes(window, wait, tmp_path, monkeypatch):
    search(window, wait)
    row = window.rows[0]
    row.mp3_radio.setChecked(True)
    row.download_button.click()  # the real button
    assert row.download_button.text() == "Added" and not row.download_button.isEnabled()
    item = window.items[-1]
    assert item in window.items and not window.jobs_empty.isVisible()
    assert item.title_label.text().startswith("<b>Result 1 for never gonna</b>")
    wait(lambda: item.state == DownloadItem.DONE, message="download")
    assert item.status_label.text() == "Download complete: Result 1 for never gonna.mp3"
    assert item.progress_bar.value() == 100
    assert (tmp_path / "out" / "Result 1 for never gonna.mp3").is_file()
    assert item.open_file_button.isVisible() and item.open_folder_button.isVisible()
    opened = []
    monkeypatch.setattr(file_service, "open_file", lambda p: opened.append(("file", p)))
    monkeypatch.setattr(file_service, "reveal_file", lambda p: opened.append(("folder", p)))
    item.open_file_button.click()
    item.open_folder_button.click()
    assert opened == [("file", item.last_file), ("folder", item.last_file)]
    assert window.search_input.isEnabled(), "searching stays possible while downloads run/finish"


def test_mp4_download_with_progress_speed_and_eta(window, wait, tmp_path):
    search(window, wait)
    item = add(window, 1, "mp4")
    seen = []
    wait(lambda: (seen.append((item.status_label.text(), item.progress_bar.value(), item.stats_label.text())) or item.state == DownloadItem.DONE), message="mp4")
    assert (tmp_path / "out" / "Result 2 for never gonna.mp4").is_file()
    values = [v for _, v, _ in seen if v > 0]
    assert values == sorted(values)
    assert any("Downloading" in s for s, _, _ in seen)
    assert any("/s" in st for _, _, st in seen) and any("ETA" in st for _, _, st in seen)


def test_multiple_downloads_run_at_the_same_time(window, wait, tmp_path):
    search(window, wait)
    slow_index = next(i for i, r in enumerate(window.rows) if r.result.video_id == "slowvideo01")
    a = add(window, slow_index, "mp4")
    b = add(window, 0, "mp3")
    c = add(window, 1, "mp3")
    assert len(window.items) == 3
    wait(lambda: a.progress_bar.maximum() == 100 and a.progress_bar.value() > 0, timeout=40, message="slow one running")
    wait(lambda: b.state == DownloadItem.DONE and c.state == DownloadItem.DONE, message="two others finish meanwhile")
    assert a.state == DownloadItem.RUNNING, "the long one is still going while the others completed"
    assert (tmp_path / "out" / "Result 1 for never gonna.mp3").is_file() and (tmp_path / "out" / "Result 2 for never gonna.mp3").is_file()
    a.cancel_button.click()
    wait(lambda: a.state == DownloadItem.CANCELLED, timeout=20, message="cancelled")
    time.sleep(0.5)
    assert not process_alive_with("sleep(120)")
    assert list((tmp_path / "scratch").glob("*")) == []


def test_downloads_beyond_the_parallel_limit_wait_and_start_automatically(window, wait):
    window.MAX_PARALLEL = 2
    search(window, wait)
    slow = next(i for i, r in enumerate(window.rows) if r.result.video_id == "slowvideo01")
    items = [add(window, slow, "mp4") for _ in range(3)]
    wait(lambda: items[0].state == items[1].state == DownloadItem.RUNNING, message="two running")
    assert items[2].state == DownloadItem.QUEUED and items[2].status_label.text() == "Waiting..."
    items[0].cancel_button.click()
    wait(lambda: items[0].state == DownloadItem.CANCELLED and items[2].state == DownloadItem.RUNNING, timeout=30, message="queued one starts")
    for i in items[1:]:
        i.cancel_button.click()
    wait(lambda: not window.active_items(), timeout=30, message="all stopped")


def test_cancel_one_leaves_the_other_running(window, wait, tmp_path):
    search(window, wait)
    slow = next(i for i, r in enumerate(window.rows) if r.result.video_id == "slowvideo01")
    a, b = add(window, slow, "mp4"), add(window, slow, "mp4")
    wait(lambda: all(i.progress_bar.maximum() == 100 and i.progress_bar.value() > 0 for i in (a, b)), timeout=40, message="both running")
    assert len(process_alive_with("sleep(120)")) >= 2
    a.cancel_button.click()
    assert a.status_label.text() == "Cancelling..."
    wait(lambda: a.state == DownloadItem.CANCELLED, timeout=20, message="a cancelled")
    assert a.status_label.text() == "Download cancelled."
    assert b.state == DownloadItem.RUNNING and len(process_alive_with("sleep(120)")) >= 1
    b.cancel_button.click()
    wait(lambda: b.state == DownloadItem.CANCELLED, timeout=20, message="b cancelled")
    time.sleep(0.5)
    assert not process_alive_with("sleep(120)")
    assert not list((tmp_path / "out").glob("*"))


def test_dismiss_removes_finished_item(window, wait):
    search(window, wait)
    item = add(window, 0, "mp3")
    wait(lambda: item.state == DownloadItem.DONE, message="done")
    item.dismiss_button.click()
    assert window.items == [] and window.jobs_empty.isVisible()


def test_failed_download_shows_friendly_message(window, wait, monkeypatch):
    search(window, wait)
    # a result whose download fails in the engine
    from app.core.media_downloader import SearchResult

    window.on_download_requested(SearchResult("failvideo01", "Broken", "X", 10, "https://i.ytimg.com/vi/failvideo01/mqdefault.jpg", "https://www.youtube.com/watch?v=failvideo01"), "mp3")
    item = window.items[-1]
    wait(lambda: item.state == DownloadItem.FAILED, message="failure")
    assert item.status_label.text() == "The requested video could not be downloaded."
    assert "403" not in item.status_label.text()


def test_output_folder_selection_persists_and_is_used(window, wait, tmp_path, monkeypatch):
    chosen = tmp_path / "Music"
    chosen.mkdir()
    from app.ui import main_window as mw

    monkeypatch.setattr(mw, "QFileDialog", type("FakeFileDialog", (), {"getExistingDirectory": staticmethod(lambda *a, **k: str(chosen))}))
    window.browse_button.click()
    assert window.folder_input.text() == str(chosen)
    assert SettingsService(window.settings.config_path).settings.output_dir == str(chosen)
    search(window, wait)
    item = add(window, 0, "mp3")
    wait(lambda: item.state == DownloadItem.DONE, message="download")
    assert (chosen / "Result 1 for never gonna.mp3").is_file()


def test_unwritable_output_folder_message(window, wait, tmp_path):
    search(window, wait)
    blocker = tmp_path / "blocker"
    blocker.write_text("x")
    window.folder_input.setText(str(blocker / "sub"))
    window.rows[0].download_button.click()
    assert window.status_label.text() == "The selected output folder is not writable."
    assert window.items == []


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
    assert second.settings.settings.default_bitrate == 320
    second.close()


def test_closing_during_downloads_asks_and_cancels_all(window, wait, tmp_path, monkeypatch):
    search(window, wait)
    slow = next(i for i, r in enumerate(window.rows) if r.result.video_id == "slowvideo01")
    items = [add(window, slow, "mp4"), add(window, slow, "mp4")]
    wait(lambda: all(i.progress_bar.maximum() == 100 and i.progress_bar.value() > 0 for i in items), timeout=40, message="progress")
    asked = []
    monkeypatch.setattr(QMessageBox, "question", lambda *a, **k: (asked.append(a[2]) or QMessageBox.StandardButton.No))
    assert window.close() is False
    assert asked and "2 download(s) in progress" in asked[0]
    assert window.is_downloading()
    monkeypatch.setattr(QMessageBox, "question", lambda *a, **k: QMessageBox.StandardButton.Yes)
    assert window.close() is True
    time.sleep(0.5)
    assert not window.is_downloading()
    assert not process_alive_with("sleep(120)")
    assert list((tmp_path / "scratch").glob("*")) == []


def test_ui_is_keyboard_accessible(window, wait):
    assert window.search_input.accessibleName()
    assert not window.status_label.accessibleName() and not window.search_error.accessibleName()
    assert window.search_button.isDefault()
    search(window, wait)
    assert window.rows[0].download_button.accessibleName().startswith("Download ")
    assert window.rows[0].mp4_radio.accessibleName().startswith("MP4 for ")
