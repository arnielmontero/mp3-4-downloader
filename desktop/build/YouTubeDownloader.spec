# -*- mode: python ; coding: utf-8 -*-
# PyInstaller spec: a single windowed EXE (YouTubeDownloader.exe). yt-dlp / FFmpeg / ffprobe / Deno are NOT
# packed inside it: build_windows.bat copies them to a "bin" folder next to the EXE, where the app finds them
# via the executable's own directory (never the working directory).
from pathlib import Path

ROOT = Path(SPECPATH).resolve().parents[1]          # repository root
DESKTOP = ROOT / "desktop"

a = Analysis(
    [str(DESKTOP / "run_app.py")],
    pathex=[str(DESKTOP)],
    binaries=[],
    datas=[(str(DESKTOP / "assets" / "icon.png"), "assets")],
    hiddenimports=[],
    hookspath=[],
    runtime_hooks=[],
    excludes=[
        "tkinter", "unittest", "pydoc", "test",
        "PySide6.QtWebEngineCore", "PySide6.QtWebEngineWidgets", "PySide6.QtQml", "PySide6.QtQuick",
        "PySide6.Qt3DCore", "PySide6.QtMultimedia", "PySide6.QtCharts", "PySide6.QtDataVisualization",
        "PySide6.QtBluetooth", "PySide6.QtSql", "PySide6.QtTest", "PySide6.QtSvg", "PySide6.QtPdf",
    ],
    noarchive=False,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    a.binaries,
    a.datas,
    [],
    name="YouTubeDownloader",
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=False,
    console=False,              # GUI application: no console window
    disable_windowed_traceback=True,
    icon=str(DESKTOP / "assets" / "icon.ico"),
    version=str(DESKTOP / "build" / "version_info.txt"),
)
