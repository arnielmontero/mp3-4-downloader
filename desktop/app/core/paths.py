"""Locating bundled binaries and per-user data. Never depends on the current working directory.

Layout of an installed / PyInstaller (onedir) build:

    YouTubeDownloader.exe
    bin/yt-dlp.exe, ffmpeg.exe, ffprobe.exe [, deno.exe]
    _internal/...            (PyInstaller runtime)

In development the binaries are looked up in ``media/bin`` of the repository (fetched by
``desktop/build/fetch_media.ps1``) and finally on PATH.
"""
from __future__ import annotations

import ctypes
import os
import shutil
import sys
from pathlib import Path

from .. import APP_DIR_NAME

IS_WINDOWS = sys.platform == "win32"


def is_frozen() -> bool:
    return bool(getattr(sys, "frozen", False))


def app_dir() -> Path:
    """Directory of the running executable (frozen) or of the desktop/ source folder (dev)."""
    if is_frozen():
        return Path(sys.executable).resolve().parent
    return Path(__file__).resolve().parents[2]


def _candidate_bin_dirs() -> list[Path]:
    dirs: list[Path] = []
    override = os.environ.get("YTD_BIN_DIR")
    if override:
        dirs.append(Path(override))
    dirs.append(app_dir() / "bin")
    if is_frozen():
        meipass = getattr(sys, "_MEIPASS", None)
        if meipass:
            dirs.append(Path(meipass) / "bin")
    else:
        dirs.append(app_dir().parent / "media" / "bin")
    return dirs


def exe_name(name: str) -> str:
    return f"{name}.exe" if IS_WINDOWS and not name.endswith(".exe") else name


def find_binary(name: str) -> Path | None:
    """Bundled binary first; PATH is only consulted when running from source."""
    for directory in _candidate_bin_dirs():
        candidate = directory / exe_name(name)
        if candidate.is_file():
            return candidate
    if not is_frozen():
        found = shutil.which(name)
        if found:
            return Path(found)
    return None


def data_dir() -> Path:
    """Per-user data folder: %APPDATA%\\YouTubeDownloader (config.json, logs, temp, cache)."""
    override = os.environ.get("YTD_DATA_DIR")
    if override:
        return Path(override)
    if IS_WINDOWS:
        base = os.environ.get("APPDATA") or str(Path.home() / "AppData" / "Roaming")
    else:
        base = os.environ.get("XDG_DATA_HOME") or str(Path.home() / ".local" / "share")
    return Path(base) / APP_DIR_NAME


def downloads_dir() -> Path:
    """The user's Downloads folder (Windows known folder API, falling back to ~/Downloads)."""
    if IS_WINDOWS:
        try:
            from ctypes import wintypes

            class GUID(ctypes.Structure):
                _fields_ = [("a", wintypes.DWORD), ("b", wintypes.WORD), ("c", wintypes.WORD), ("d", ctypes.c_ubyte * 8)]

            folder_id = GUID(0x374DE290, 0x123F, 0x4565, (ctypes.c_ubyte * 8)(0x91, 0x64, 0x39, 0xC4, 0x92, 0x5E, 0x46, 0x7B))
            path_ptr = ctypes.c_wchar_p()
            if ctypes.windll.shell32.SHGetKnownFolderPath(ctypes.byref(folder_id), 0, None, ctypes.byref(path_ptr)) == 0:
                path = Path(path_ptr.value)
                ctypes.windll.ole32.CoTaskMemFree(path_ptr)
                return path
        except Exception:  # noqa: BLE001 - any failure falls back to the default below
            pass
    return Path.home() / "Downloads"
