"""User settings stored as JSON in %APPDATA%\\YouTubeDownloader\\config.json (no secrets are stored)."""
from __future__ import annotations

import json
import logging
import os
import tempfile
from dataclasses import asdict, dataclass, field
from pathlib import Path

from . import paths
from .security import BITRATES, DEFAULT_BITRATE, FORMATS, QUALITIES

log = logging.getLogger("ytd.settings")


@dataclass
class Settings:
    output_dir: str = ""
    default_format: str = "mp4"
    default_quality: str = "best"
    default_bitrate: int = DEFAULT_BITRATE
    #: schema version for future migrations
    version: int = field(default=1)

    def output_path(self) -> Path:
        return Path(self.output_dir) if self.output_dir else paths.downloads_dir()


class SettingsService:
    def __init__(self, config_path: Path | None = None):
        self.config_path = config_path or (paths.data_dir() / "config.json")
        self.settings = self.load()

    # ------------------------------------------------------------------
    def load(self) -> Settings:
        """Read config.json; unreadable or invalid values silently fall back to the defaults."""
        defaults = Settings(output_dir=str(paths.downloads_dir()))
        try:
            # utf-8-sig: tolerate the BOM that Windows editors / PowerShell add
            raw = json.loads(self.config_path.read_text(encoding="utf-8-sig"))
        except (OSError, ValueError):
            return defaults
        if not isinstance(raw, dict):
            return defaults
        return self._validated(raw, defaults)

    @staticmethod
    def _validated(raw: dict, defaults: Settings) -> Settings:
        out = Settings(**asdict(defaults))
        folder = raw.get("output_dir")
        if isinstance(folder, str) and folder.strip() and "\x00" not in folder:
            out.output_dir = folder
        fmt = raw.get("default_format")
        if fmt in FORMATS:
            out.default_format = fmt
        quality = raw.get("default_quality")
        if quality in QUALITIES:
            out.default_quality = quality
        bitrate = raw.get("default_bitrate")
        if isinstance(bitrate, int) and not isinstance(bitrate, bool) and bitrate in BITRATES:
            out.default_bitrate = bitrate
        return out

    def save(self) -> None:
        """Atomic write (temp file + replace) so a crash can never leave a half-written config."""
        self.config_path.parent.mkdir(parents=True, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=self.config_path.parent, prefix="config-", suffix=".tmp")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as handle:
                json.dump(asdict(self.settings), handle, indent=2)
            os.replace(tmp, self.config_path)
        except OSError:
            log.exception("could not save settings")
            try:
                os.unlink(tmp)
            except OSError:
                pass
            raise

    def update(self, **changes: object) -> Settings:
        merged = {**asdict(self.settings), **changes}
        self.settings = self._validated(merged, Settings(output_dir=str(paths.downloads_dir())))
        self.save()
        return self.settings

    def reset(self) -> Settings:
        """Back to factory defaults (and remove config.json)."""
        self.settings = Settings(output_dir=str(paths.downloads_dir()))
        try:
            self.config_path.unlink()
        except FileNotFoundError:
            pass
        return self.settings
