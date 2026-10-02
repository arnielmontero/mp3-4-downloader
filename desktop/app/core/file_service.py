"""Filename sanitising, unique names, folder checks, open-in-explorer helpers."""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import sys
import uuid
from pathlib import Path

from .errors import MSG_OUTPUT, OutputFolderError

_RESERVED = {"CON", "PRN", "AUX", "NUL", *(f"COM{i}" for i in range(1, 10)), *(f"LPT{i}" for i in range(1, 10))}
_MAX_BYTES = 150


def sanitize_filename(name: str, fallback: str = "download") -> str:
    """Make a title safe as a file name (without extension). Same rules as the web backend."""
    name = re.sub(r"[\x00-\x1f\x7f​-‏‪-‮⁠-⁤﻿]", "", name or "")
    # separators/drive colons become " - "; segments consisting only of dots are traversal remnants
    segments = [s.strip() for s in re.split(r"[/\\:]+", name) if s.strip(" .\t")]
    name = " - ".join(segments)
    name = re.sub(r'[*?"<>|]', "", name)
    name = re.sub(r"\s+", " ", name)
    name = re.sub(r"^(\s*-\s+)+|(\s+-\s*)+$", "", name)
    name = name.strip(" .\t")
    if not name or re.fullmatch(r"\.+", name):
        name = fallback
    if name.split(".")[0].rstrip().upper() in _RESERVED:
        name = "_" + name
    while len(name.encode("utf-8")) > _MAX_BYTES:
        name = name[:-1]
    name = name.rstrip(" .")
    return name or fallback


def unique_path(directory: Path, base: str, ext: str) -> Path:
    """`base.ext`, `base (1).ext`, `base (2).ext` ... whichever does not exist yet (never overwrites)."""
    candidate = directory / f"{base}.{ext}"
    i = 1
    while candidate.exists():
        candidate = directory / f"{base} ({i}).{ext}"
        i += 1
    return candidate


def ensure_writable_dir(path: Path) -> Path:
    """Create the folder if needed and verify we can really write into it."""
    try:
        path.mkdir(parents=True, exist_ok=True)
        probe = path / f".write-test-{uuid.uuid4().hex}"
        probe.write_bytes(b"x")
        probe.unlink()
    except OSError as exc:
        raise OutputFolderError(MSG_OUTPUT, detail=str(exc)) from exc
    return path


def move_file(src: Path, dest: Path) -> None:
    try:
        shutil.move(str(src), str(dest))
    except OSError as exc:
        raise OutputFolderError(MSG_OUTPUT, detail=str(exc)) from exc


def remove_tree(path: Path) -> None:
    shutil.rmtree(path, ignore_errors=True)


def find_output(directory: Path, ext: str) -> Path | None:
    """Newest finished file with the given extension (ignores .part files)."""
    best: Path | None = None
    for item in directory.glob(f"*.{ext}"):
        if item.is_file() and not item.name.endswith(".part"):
            if best is None or item.stat().st_mtime >= best.stat().st_mtime:
                best = item
    return best


def dir_size(path: Path) -> int:
    total = 0
    for root, _dirs, files in os.walk(path):
        for f in files:
            try:
                total += os.path.getsize(os.path.join(root, f))
            except OSError:
                pass
    return total


def reveal_file(path: Path) -> None:
    """Open the containing folder with the file selected (Windows Explorer), best effort elsewhere."""
    if sys.platform == "win32":
        subprocess.Popen(["explorer", f"/select,{path}"])  # noqa: S603,S607 - fixed program, no shell
    elif sys.platform == "darwin":
        subprocess.Popen(["open", "-R", str(path)])  # noqa: S603,S607
    else:
        subprocess.Popen(["xdg-open", str(path.parent)])  # noqa: S603,S607


def open_folder(path: Path) -> None:
    if sys.platform == "win32":
        os.startfile(str(path))  # type: ignore[attr-defined]  # noqa: S606
    elif sys.platform == "darwin":
        subprocess.Popen(["open", str(path)])  # noqa: S603,S607
    else:
        subprocess.Popen(["xdg-open", str(path)])  # noqa: S603,S607


def open_file(path: Path) -> None:
    if sys.platform == "win32":
        os.startfile(str(path))  # type: ignore[attr-defined]  # noqa: S606
    elif sys.platform == "darwin":
        subprocess.Popen(["open", str(path)])  # noqa: S603,S607
    else:
        subprocess.Popen(["xdg-open", str(path)])  # noqa: S603,S607
