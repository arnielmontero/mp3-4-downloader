"""Shared fixtures for the desktop test-suite (run: desktop\\.venv\\Scripts\\python -m pytest tests\\desktop)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "desktop"))
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")  # no display needed

FAKE = ROOT / "tests" / "fixtures" / "fake_ytdlp.py"


@pytest.fixture()
def data_dir(tmp_path, monkeypatch) -> Path:
    d = tmp_path / "appdata"
    monkeypatch.setenv("YTD_DATA_DIR", str(d))
    return d


@pytest.fixture()
def call_log(tmp_path, monkeypatch) -> Path:
    path = tmp_path / "calls.log"
    monkeypatch.setenv("FAKE_YTDLP_LOG", str(path))
    return path


@pytest.fixture()
def engine_parts(tmp_path, data_dir):
    from app.core.media_downloader import YtDlpDownloader
    from app.core.process_manager import ProcessManager

    pm = ProcessManager()
    engine = YtDlpDownloader(pm, ytdlp_cmd=[sys.executable, str(FAKE)], cache_dir=tmp_path / "cache")
    yield engine, pm
    pm.kill_all()


@pytest.fixture()
def service(engine_parts, tmp_path):
    from app.core.youtube_service import YouTubeService

    engine, _pm = engine_parts
    return YouTubeService(engine, temp_root=tmp_path / "scratch")


@pytest.fixture(scope="session")
def qapp():
    from PySide6.QtWidgets import QApplication

    app = QApplication.instance() or QApplication([])
    yield app


def wait_until(predicate, qapp, timeout=30.0, message="condition"):
    """Spin the Qt event loop until predicate() is true (worker signals need it)."""
    import time

    deadline = time.time() + timeout
    while time.time() < deadline:
        qapp.processEvents()
        if predicate():
            return
        time.sleep(0.02)
    raise AssertionError(f"timed out waiting for {message}")


@pytest.fixture()
def wait(qapp):
    return lambda predicate, timeout=30.0, message="condition": wait_until(predicate, qapp, timeout, message)


@pytest.fixture()
def window(qapp, service, tmp_path, data_dir):
    from app.core.settings_service import SettingsService
    from app.ui.main_window import MainWindow

    settings = SettingsService(tmp_path / "config.json")
    settings.update(output_dir=str(tmp_path / "out"))
    w = MainWindow(service, settings)
    w.show()
    yield w
    for item in w.active_items():  # never leave a modal "cancel and exit?" prompt (or a download) behind
        item.cancel()
    for item in list(w.items):
        item.wait(20000)
    qapp.processEvents()
    w.close()
    qapp.processEvents()


def process_alive_with(marker: str) -> list[str]:
    """Command lines of live processes containing `marker` (Windows: wmic/CIM; POSIX: /proc)."""
    import subprocess

    if sys.platform == "win32":
        out = subprocess.run(
            ["powershell", "-NoProfile", "-Command", f"Get-CimInstance Win32_Process | Where-Object {{ $_.CommandLine -like '*{marker}*' }} | ForEach-Object {{ $_.CommandLine }}"],
            capture_output=True, text=True,
        ).stdout
        return [line for line in out.splitlines() if line.strip() and "Get-CimInstance" not in line]
    out = subprocess.run(["ps", "-eo", "args"], capture_output=True, text=True).stdout
    return [line for line in out.splitlines() if marker in line]
