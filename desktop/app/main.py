"""Application entry point (also the PyInstaller entry script).

Normal start:   YouTubeDownloader.exe
Diagnostics:    YouTubeDownloader.exe --self-test
                YouTubeDownloader.exe --self-test --download URL --format mp3 --output C:\\folder
Used by the build script to verify a packaged EXE: it checks that the bundled yt-dlp/FFmpeg are found
relative to the executable, that the GUI can be created, and optionally performs a real download.
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
import tempfile
from pathlib import Path

from . import APP_NAME, __version__
from .core import logging_service, paths
from .core.media_downloader import YtDlpDownloader
from .core.process_manager import ProcessManager
from .core.settings_service import SettingsService
from .core.youtube_service import YouTubeService


def build_service(processes: ProcessManager | None = None) -> tuple[YouTubeService, ProcessManager]:
    processes = processes or ProcessManager()
    engine = YtDlpDownloader(processes)
    return YouTubeService(engine), processes


def _parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="YouTubeDownloader", add_help=True)
    parser.add_argument("--self-test", action="store_true", help="verify the installation and exit")
    parser.add_argument("--download", metavar="URL", help="(with --self-test) really download this URL")
    parser.add_argument("--format", default="mp4", choices=["mp4", "mp3"])
    parser.add_argument("--quality", default="best")
    parser.add_argument("--bitrate", type=int, default=192)
    parser.add_argument("--output", default=None, help="output folder for --download")
    args, _unknown = parser.parse_known_args(argv[1:])
    return args


def self_test(args: argparse.Namespace) -> int:
    """Headless installation check. Prints one JSON document and returns 0 only if everything works."""
    report: dict = {"app": APP_NAME, "version": __version__, "frozen": paths.is_frozen(), "app_dir": str(paths.app_dir())}
    ok = True
    for name in ("yt-dlp", "ffmpeg", "ffprobe"):
        found = paths.find_binary(name)
        report[name] = str(found) if found else None
        ok = ok and found is not None
    report["deno"] = str(paths.find_binary("deno")) if paths.find_binary("deno") else None

    service, processes = build_service()
    try:
        report["versions"] = service.engine.versions()
        ok = ok and all(report["versions"].values())

        # the real GUI must be constructible (Qt plugins, resources, styles) - uses a throw-away settings file
        from PySide6.QtWidgets import QApplication

        from .ui.main_window import MainWindow, create_application

        app = create_application(sys.argv)
        with tempfile.TemporaryDirectory() as tmp:
            window = MainWindow(service, SettingsService(Path(tmp) / "config.json"))
            window.show()
            app.processEvents()
            report["window_title"] = window.windowTitle()
            window.close()
        ok = ok and report["window_title"] == APP_NAME

        if args.download:
            from .core import security

            out = Path(args.output) if args.output else Path(tempfile.mkdtemp(prefix="ytd-selftest-"))
            info = service.analyze(args.download)
            report["title"] = info.title
            path = service.download(info, args.format, args.quality, security.validate_bitrate(args.bitrate), out)
            report["downloaded"] = str(path)
            report["size"] = path.stat().st_size
            ok = ok and path.is_file() and path.stat().st_size > 0
    except Exception as exc:  # noqa: BLE001
        report["error"] = getattr(exc, "message", None) or str(exc)
        report["error_code"] = getattr(exc, "code", "ERROR")
        ok = False
    finally:
        processes.kill_all()
    report["ok"] = ok
    print(json.dumps(report, indent=2))
    return 0 if ok else 1


def main(argv: list[str] | None = None) -> int:
    argv = list(sys.argv if argv is None else argv)
    args = _parse_args(argv)
    logfile = logging_service.setup_logging()
    log = logging.getLogger("ytd.main")
    log.info("starting %s %s (frozen=%s, yt-dlp=%s)", APP_NAME, __version__, paths.is_frozen(), paths.find_binary("yt-dlp"))

    if args.self_test:
        return self_test(args)

    from PySide6.QtWidgets import QMessageBox

    from .ui.main_window import MainWindow, create_application

    app = create_application(argv)
    try:
        service, processes = build_service()
        service.cleanup_stale_temp()
        settings = SettingsService()
        window = MainWindow(service, settings)
    except Exception:  # noqa: BLE001 - never show a stack trace to the user
        log.exception("startup failed")
        QMessageBox.critical(None, APP_NAME, f"The application could not start. Details were written to:\n{logfile}")
        return 1
    app.aboutToQuit.connect(processes.kill_all)
    window.show()
    return app.exec()


if __name__ == "__main__":
    raise SystemExit(main())
