"""Unit tests of the GUI-independent desktop core."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import pytest

from app.core import file_service, paths, security
from app.core.errors import (
    AppError,
    DownloadFailedError,
    InvalidUrlError,
    NetworkError,
    OutputFolderError,
    ProcessingFailedError,
    UnsupportedDomainError,
    VideoUnavailableError,
)
from app.core.logging_service import sanitize, setup_logging
from app.core.media_downloader import Progress, YtDlpDownloader, _ProgressState, classify_error, normalize_info
from app.core.process_manager import ProcessManager
from app.core.settings_service import SettingsService

URL = "https://www.youtube.com/watch?v=dQw4w9WgXcQ"
DUMMY = ProcessManager()


def engine(**kw) -> YtDlpDownloader:
    return YtDlpDownloader(DUMMY, ytdlp_cmd=kw.pop("cmd", ["yt-dlp"]), ffmpeg_path=kw.pop("ffmpeg", None), deno_path=kw.pop("deno", None), **kw)


# ------------------------------------------------------------------ URL validation / normalisation
VALID = [
    "https://www.youtube.com/watch?v=dQw4w9WgXcQ", "https://youtu.be/dQw4w9WgXcQ", "https://youtu.be/dQw4w9WgXcQ?si=abc",
    "https://m.youtube.com/watch?v=dQw4w9WgXcQ", "https://music.youtube.com/watch?v=dQw4w9WgXcQ", "youtube.com/watch?v=dQw4w9WgXcQ",
    "www.youtube.com/watch?v=dQw4w9WgXcQ", "https://www.youtube.com/shorts/dQw4w9WgXcQ", "https://www.youtube.com/embed/dQw4w9WgXcQ",
    "https://www.youtube.com/live/dQw4w9WgXcQ", "https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ",
    "https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42s", "https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123456789",
    "HTTPS://WWW.YOUTUBE.COM/watch?v=dQw4w9WgXcQ", "  https://youtu.be/dQw4w9WgXcQ \n", "http://www.youtube.com/watch?v=dQw4w9WgXcQ",
]


@pytest.mark.parametrize("raw", VALID)
def test_valid_urls_normalise(raw):
    assert security.normalize_url(raw) == URL
    assert security.extract_video_id(raw) == "dQw4w9WgXcQ"


REJECTED = [
    ("", InvalidUrlError), ("   ", InvalidUrlError), (None, InvalidUrlError), (123, InvalidUrlError), ("not a url", InvalidUrlError),
    ("https://www.youtube.com/watch?v=short", InvalidUrlError), ("https://www.youtube.com/watch", InvalidUrlError),
    ("https://www.youtube.com/playlist?list=PL123456789", InvalidUrlError), ("https://www.youtube.com/@channel", InvalidUrlError),
    ("https://www.youtube.com/", InvalidUrlError),
    ("http://localhost/", UnsupportedDomainError), ("http://127.0.0.1/", UnsupportedDomainError), ("http://0.0.0.0/", UnsupportedDomainError),
    ("http://10.0.0.1/", UnsupportedDomainError), ("http://192.168.1.1/", UnsupportedDomainError), ("http://169.254.169.254/", UnsupportedDomainError),
    ("http://[::1]/", UnsupportedDomainError), ("http://2130706433/", UnsupportedDomainError), ("http://intranet.local/", UnsupportedDomainError),
    ("https://vimeo.com/1", UnsupportedDomainError), ("https://www.youtube.com.evil.com/watch?v=dQw4w9WgXcQ", UnsupportedDomainError),
    ("https://evil.youtube.com/watch?v=dQw4w9WgXcQ", UnsupportedDomainError), ("https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ", UnsupportedDomainError),
    ("file:///etc/passwd", InvalidUrlError), ("ftp://www.youtube.com/x", InvalidUrlError), ("data:text/html,x", InvalidUrlError),
    ("javascript:alert(1)", InvalidUrlError), ("https://www.youtube.com@evil.com/watch?v=dQw4w9WgXcQ", InvalidUrlError),
    ("https://user:pw@www.youtube.com/watch?v=dQw4w9WgXcQ", InvalidUrlError),
    ("https://youtu.be/dQw4w9WgXcQ;id", InvalidUrlError), ("https://youtu.be/dQw4w9WgXcQ|id", InvalidUrlError), ("https://youtu.be/$(id)", InvalidUrlError),
    ("https://youtu.be/`id`", InvalidUrlError), ("https://youtu.be/dQw4w9WgXcQ\nhttps://evil.com", InvalidUrlError),
    ("https://youtu.be/dQw4w9WgXcQ\x00", InvalidUrlError), ("--exec=id", UnsupportedDomainError),
]


@pytest.mark.parametrize("raw,exc", REJECTED)
def test_rejected_urls(raw, exc):
    with pytest.raises(exc) as info:
        security.normalize_url(raw)
    assert info.value.message  # friendly message present


def test_invalid_url_message_is_the_friendly_one():
    with pytest.raises(AppError) as info:
        security.normalize_url("nonsense")
    assert info.value.message == "Please enter a valid YouTube URL."


def test_format_quality_bitrate_validation():
    assert security.validate_format(" MP3 ") == "mp3"
    for bad in ("", "avi", None, "mp4;ls", 5):
        with pytest.raises(AppError):
            security.validate_format(bad)
    assert security.validate_quality(None) == "best"
    assert security.validate_quality("720p") == "720p"
    for bad in ("720", "9999p", "-S", 720, "720p; ls"):
        with pytest.raises(AppError):
            security.validate_quality(bad)
    assert security.validate_bitrate(None) == 192
    assert security.validate_bitrate("256") == 256
    for ok in (128, 192, 256, 320):
        assert security.validate_bitrate(ok) == ok
    for bad in (0, 64, 321, "192k", True, 192.5, [192]):
        with pytest.raises(AppError):
            security.validate_bitrate(bad)


def test_thumbnail_host_allowlist():
    assert security.safe_thumbnail("https://i.ytimg.com/vi/x/hq.jpg")
    assert not security.safe_thumbnail("http://i.ytimg.com/vi/x/hq.jpg")
    assert not security.safe_thumbnail("https://evil.com/x.jpg")
    assert not security.safe_thumbnail("https://i.ytimg.com.evil.com/x.jpg")
    assert not security.safe_thumbnail(None)


# ------------------------------------------------------------------ filenames
@pytest.mark.parametrize("raw,expected", [
    ("My Video: Episode 1 / Test?", "My Video - Episode 1 - Test"),
    ("a\\b\\c", "a - b - c"), ('what*is<this>"quoted"|pipe?', "whatisthisquotedpipe"),
    ("Tab\there\x00and\x1fmore\r\nlines", "Tabhereandmorelines"), ("../../etc/passwd", "etc - passwd"),
    ("..\\..\\Windows\\System32", "Windows - System32"), ("....", "download"), ("name. . ", "name"), (".hidden", "hidden"),
    ("a    b \t c", "a b c"), ("", "download"), ("???***", "download"), ("Música ñandú 日本語", "Música ñandú 日本語"),
    ("CON", "_CON"), ("nul", "_nul"), ("com1.txt", "_com1.txt"), ("LPT9", "_LPT9"), ("CONSOLE", "CONSOLE"),
])
def test_sanitize_filename(raw, expected):
    assert file_service.sanitize_filename(raw) == expected


def test_sanitized_names_are_always_safe():
    for raw in ["a/b", "..", "../..", "x\0y", "CON", "a:b", "é" * 400, '<>:"/\\|?*', " . ", "\u202eevil", "~$tmp"]:
        safe = file_service.sanitize_filename(raw)
        assert safe and safe == Path(safe).name
        assert not any(c in safe for c in '/\\:*?"<>|\0')
        assert len(safe.encode()) <= 150
        safe.encode("utf-8")  # still valid after truncation
        assert not safe.endswith((" ", "."))


def test_unique_path_never_overwrites(tmp_path):
    assert file_service.unique_path(tmp_path, "video", "mp4").name == "video.mp4"
    (tmp_path / "video.mp4").touch()
    assert file_service.unique_path(tmp_path, "video", "mp4").name == "video (1).mp4"
    (tmp_path / "video (1).mp4").touch()
    (tmp_path / "video (2).mp4").touch()
    assert file_service.unique_path(tmp_path, "video", "mp4").name == "video (3).mp4"
    assert file_service.unique_path(tmp_path, "video", "mp3").name == "video.mp3"


def test_ensure_writable_dir(tmp_path):
    target = tmp_path / "new" / "deep"
    assert file_service.ensure_writable_dir(target) == target and target.is_dir()
    blocker = tmp_path / "afile"
    blocker.write_text("x")
    with pytest.raises(OutputFolderError) as info:
        file_service.ensure_writable_dir(blocker / "sub")
    assert info.value.message == "The selected output folder is not writable."


# ------------------------------------------------------------------ settings
def test_settings_defaults_to_downloads_folder(tmp_path):
    s = SettingsService(tmp_path / "config.json").settings
    assert Path(s.output_dir) == paths.downloads_dir()
    assert (s.default_format, s.default_quality, s.default_bitrate) == ("mp4", "best", 192)


def test_settings_persist_across_instances(tmp_path):
    cfg = tmp_path / "AppData" / "config.json"
    svc = SettingsService(cfg)
    svc.update(output_dir=str(tmp_path / "music"), default_format="mp3", default_quality="720p", default_bitrate=320)
    again = SettingsService(cfg).settings
    assert (again.output_dir, again.default_format, again.default_quality, again.default_bitrate) == (str(tmp_path / "music"), "mp3", "720p", 320)
    data = json.loads(cfg.read_text(encoding="utf-8"))
    assert set(data) >= {"output_dir", "default_format", "default_quality", "default_bitrate"}


def test_settings_invalid_values_fall_back(tmp_path):
    cfg = tmp_path / "config.json"
    cfg.write_text(json.dumps({"output_dir": 5, "default_format": "avi", "default_quality": "huge", "default_bitrate": 999}))
    s = SettingsService(cfg).settings
    assert (s.default_format, s.default_quality, s.default_bitrate) == ("mp4", "best", 192)
    cfg.write_text("{broken json")
    assert SettingsService(cfg).settings.default_format == "mp4"
    cfg.write_text("[1,2,3]")
    assert SettingsService(cfg).settings.default_bitrate == 192


def test_settings_file_with_utf8_bom_is_read(tmp_path):
    cfg = tmp_path / "config.json"
    cfg.write_bytes("﻿".encode("utf-8") + json.dumps({"default_format": "mp3", "default_bitrate": 320}).encode())
    s = SettingsService(cfg).settings
    assert (s.default_format, s.default_bitrate) == ("mp3", 320)


def test_settings_reset(tmp_path):
    cfg = tmp_path / "config.json"
    svc = SettingsService(cfg)
    svc.update(default_format="mp3", default_bitrate=128)
    assert cfg.exists()
    s = svc.reset()
    assert (s.default_format, s.default_bitrate) == ("mp4", 192)
    assert not cfg.exists()


def test_settings_file_contains_no_secrets(tmp_path):
    cfg = tmp_path / "config.json"
    SettingsService(cfg).update(default_format="mp3")
    text = cfg.read_text().lower()
    for word in ("password", "token", "cookie", "secret"):
        assert word not in text


# ------------------------------------------------------------------ paths
def test_binary_lookup_does_not_depend_on_cwd(tmp_path, monkeypatch):
    bindir = tmp_path / "bin"
    bindir.mkdir()
    name = paths.exe_name("yt-dlp")
    (bindir / name).write_text("x")
    monkeypatch.setenv("YTD_BIN_DIR", str(bindir))
    elsewhere = tmp_path / "elsewhere"
    elsewhere.mkdir()
    monkeypatch.chdir(elsewhere)
    assert paths.find_binary("yt-dlp") == bindir / name


def test_frozen_layout_uses_the_executable_directory(tmp_path, monkeypatch):
    exe_dir = tmp_path / "install"
    (exe_dir / "bin").mkdir(parents=True)
    (exe_dir / "bin" / paths.exe_name("ffmpeg")).write_text("x")
    monkeypatch.delenv("YTD_BIN_DIR", raising=False)
    monkeypatch.setattr(sys, "frozen", True, raising=False)
    monkeypatch.setattr(sys, "executable", str(exe_dir / "YouTubeDownloader.exe"))
    monkeypatch.chdir(tmp_path)  # a different working directory must not matter
    assert paths.app_dir() == exe_dir.resolve()
    assert paths.find_binary("ffmpeg") == exe_dir.resolve() / "bin" / paths.exe_name("ffmpeg")
    assert paths.find_binary("yt-dlp") is None  # frozen builds never fall back to PATH


def test_data_dir_is_under_appdata(monkeypatch):
    monkeypatch.delenv("YTD_DATA_DIR", raising=False)
    if sys.platform == "win32":
        monkeypatch.setenv("APPDATA", r"C:\Users\x\AppData\Roaming")
        assert str(paths.data_dir()).endswith(r"AppData\Roaming\YouTubeDownloader")


# ------------------------------------------------------------------ command construction
def test_commands_are_argument_lists_with_url_after_double_dash(tmp_path):
    e = engine(cmd=["C:/Program Files/app/bin/yt-dlp.exe"])
    cmd = e.build_download_command(URL, "mp4", "best", 192, tmp_path)
    assert isinstance(cmd, list) and all(isinstance(c, str) for c in cmd)
    assert cmd[0] == "C:/Program Files/app/bin/yt-dlp.exe"
    assert cmd[-2:] == ["--", URL]
    assert "--ignore-config" in cmd and "--no-playlist" in cmd


def test_hostile_url_stays_one_argument(tmp_path):
    evil = "https://x/; rm -rf / && `id` $(id) --exec id"
    cmd = engine().build_download_command(evil, "mp3", "best", 192, tmp_path)
    assert cmd[-1] == evil and cmd.count(evil) == 1 and cmd[-2] == "--"


def test_mp4_quality_selection():
    best = YtDlpDownloader.mp4_format_args("best")
    assert best[best.index("--merge-output-format") + 1] == "mp4" and best[best.index("--remux-video") + 1] == "mp4"
    assert best[best.index("-S") + 1].startswith("vcodec:avc1,res")
    assert "bv+ba" in best[best.index("-f") + 1]
    assert YtDlpDownloader.mp4_format_args("720p")[3].startswith("res:720,")
    assert YtDlpDownloader.mp4_format_args("1080p")[3].startswith("res:1080,")


def test_mp3_bitrates():
    for kbps in (128, 192, 256, 320):
        args = YtDlpDownloader.mp3_format_args(kbps)
        assert "-x" in args and args[args.index("--audio-format") + 1] == "mp3"
        assert args[args.index("--audio-quality") + 1] == f"{kbps}K" and "--embed-metadata" in args


def test_bundled_ffmpeg_and_deno_are_passed_explicitly(tmp_path):
    cmd = engine(ffmpeg=tmp_path / "bin" / "ffmpeg.exe", deno=tmp_path / "bin" / "deno.exe").build_info_command(URL, "mp4", "720p")
    assert cmd[cmd.index("--ffmpeg-location") + 1] == str(tmp_path / "bin" / "ffmpeg.exe")
    assert cmd[cmd.index("--js-runtimes") + 1] == f"deno:{tmp_path / 'bin' / 'deno.exe'}"
    assert "-o" not in cmd and "-x" not in cmd  # analysis never downloads


# ------------------------------------------------------------------ progress parsing
def test_progress_overall_across_two_streams():
    st = _ProgressState(expected_total=1000)
    assert st.feed("YTDP|downloading|250|400|NA|2097152|5|v.f137.mp4")
    assert st.percent == pytest.approx(25.0) and st.speed == 2097152.0 and st.eta == 5
    st.feed("YTDP|finished|400|400|NA|NA|NA|v.f137.mp4")
    st.feed("YTDP|downloading|100|600|NA|1000|3|v.f140.m4a")
    assert st.percent == pytest.approx(50.0) and st.downloaded == 500


def test_progress_never_complete_before_processing():
    st = _ProgressState(expected_total=100)
    st.feed("YTDP|downloading|500|500|NA|1|0|a.mp4")
    assert st.percent == 99.9


def test_progress_unknown_size_is_indeterminate_not_faked():
    st = _ProgressState()
    st.feed("YTDP|downloading|500|NA|NA|NA|NA|a.mp4")
    snap = st.snapshot()
    assert snap.percent is None and snap.indeterminate


def test_progress_single_stream_and_estimate():
    st = _ProgressState()
    st.feed("YTDP|downloading|500|2000|NA|100|15|a.m4a")
    assert st.percent == pytest.approx(25.0) and st.total == 2000
    st2 = _ProgressState()
    st2.feed("YTDP|downloading|500|NA|1000|100|5|a.mp4")
    assert st2.percent == pytest.approx(50.0)


def test_processing_stage_is_indeterminate():
    st = _ProgressState()
    st.feed("YTDP|downloading|100|100|NA|1|0|a.mp4")
    for line in ('[Merger] Merging formats into "x.mp4"', "[ExtractAudio] Destination: x.mp3", "[VideoConvertor] Converting"):
        s = _ProgressState()
        assert s.feed(line)
        snap = s.snapshot()
        assert snap.stage == "processing" and snap.indeterminate
    assert not st.feed("[download] Destination: something")


def test_garbage_progress_lines_ignored():
    st = _ProgressState()
    assert not st.feed("YTDP|incomplete")
    assert not st.feed("YTDP|downloading|abc|def|NA|NA|NA|x")
    assert st.downloaded == 0


# ------------------------------------------------------------------ metadata + errors
def test_normalize_info_qualities_from_real_formats():
    info = normalize_info(
        {"id": "dQw4w9WgXcQ", "title": "T", "uploader": "U", "duration": 632, "thumbnail": "https://i.ytimg.com/vi/x/hq.jpg", "filesize": 1000},
        [
            {"vcodec": "none", "acodec": "mp4a", "abr": 129.7}, {"vcodec": "none", "acodec": "opus", "abr": 50.0},
            {"vcodec": "avc1", "acodec": "none", "height": 1080}, {"vcodec": "vp9", "acodec": "none", "height": 720},
            {"vcodec": "avc1", "acodec": "none", "height": 360}, {"vcodec": "avc1", "acodec": "mp4a", "height": 144},
        ],
    )
    assert info.qualities == ["best", "1080p", "720p", "360p", "144p"]
    assert info.duration_formatted == "10:32" and info.has_video and info.has_audio
    assert info.source_audio_bitrate == 130 and info.webpage_url == "https://www.youtube.com/watch?v=dQw4w9WgXcQ"


def test_normalize_info_audio_only_live_and_hostile_thumbnail():
    audio = normalize_info({"id": "dQw4w9WgXcQ", "duration": 3725.4}, [{"vcodec": "none", "acodec": "opus", "abr": 100}])
    assert not audio.has_video and audio.has_audio and audio.qualities == ["best"] and audio.duration_formatted == "1:02:05"
    assert audio.title == "dQw4w9WgXcQ"  # never invents a title
    assert normalize_info({"id": "dQw4w9WgXcQ", "is_live": True}, []).is_live
    assert normalize_info({"id": "dQw4w9WgXcQ", "thumbnail": "https://evil.example/x.png"}, []).thumbnail is None


@pytest.mark.parametrize("stderr,cls,message", [
    ("ERROR: [youtube] abc: Video unavailable", VideoUnavailableError, "The requested video could not be downloaded."),
    ("ERROR: Private video. Sign in if you have been granted access", VideoUnavailableError, None),
    ("ERROR: Postprocessing: ffmpeg exited with code 1", ProcessingFailedError, "Media processing failed."),
    ("ERROR: Unable to download webpage: <urlopen error [Errno -3] Temporary failure in name resolution>", NetworkError, "Unable to connect. Please check your internet connection."),
    ("ERROR: unable to download video data: HTTP Error 403: Forbidden", DownloadFailedError, "The requested video could not be downloaded."),
    ("completely unexpected", DownloadFailedError, None),
    ("", DownloadFailedError, None),
])
def test_error_classification(stderr, cls, message):
    err = classify_error(stderr)
    assert isinstance(err, cls)
    if message:
        assert err.message == message
    assert err.detail == stderr  # raw text only in `detail` (log), never in `message`


def test_error_messages_never_leak_raw_output():
    err = classify_error("ERROR at C:\\Users\\x\\secret cookie=SECRET123 token=xyz")
    assert "SECRET" not in err.message and "C:\\" not in err.message


# ------------------------------------------------------------------ logging
def test_log_sanitizer_redacts_secrets():
    dirty = "Cookie: SID=abc123 token=SECRETTOKEN Authorization: Bearer abcdef https://h/v?key=APIKEY&signature=SIG password=hunter2\r\nnext\x00"
    clean = sanitize(dirty)
    for secret in ("abc123", "SECRETTOKEN", "abcdef", "APIKEY", "SIG", "hunter2"):
        assert secret not in clean
    assert "\n" not in clean and "\x00" not in clean
    assert len(sanitize("a" * 5000)) <= 504


def test_log_file_is_structured_json(tmp_path):
    import logging

    logfile = setup_logging(tmp_path / "logs")
    logging.getLogger("ytd.test").info("hello token=SECRET", extra={"job_id": "j1", "op": "download", "status": "ok", "duration_ms": 5, "error_code": None})
    for h in logging.getLogger("ytd").handlers:
        h.flush()
    entry = json.loads(logfile.read_text(encoding="utf-8").splitlines()[-1])
    assert entry["job_id"] == "j1" and entry["op"] == "download" and "SECRET" not in entry["message"]
    assert set(entry) >= {"ts", "level", "job_id", "op", "status", "duration_ms", "error_code", "message"}
    for h in list(logging.getLogger("ytd").handlers):
        logging.getLogger("ytd").removeHandler(h)
        h.close()


# ------------------------------------------------------------------ search
def test_search_query_validation_and_link_detection():
    assert security.validate_search_query("  rick\t astley \n never   gonna ") == "rick astley never gonna"
    assert security.validate_search_query("a\x00b") == "a b"
    for bad in ("", "   ", None, 5, "x" * 101):
        with pytest.raises(AppError) as info:
            security.validate_search_query(bad)
        assert info.value.code == "INVALID_QUERY"
    assert security.looks_like_url("https://youtu.be/x") and security.looks_like_url("www.youtube.com/watch?v=1")
    assert not security.looks_like_url("rick astley") and not security.looks_like_url("never gonna give you up")


def test_search_command_cannot_be_hijacked_by_the_query():
    for evil in ("--exec id", "; rm -rf /", "$(id) `id` | cat", "--output C:/x"):
        cmd = engine().build_search_command(evil, 10)
        assert cmd[-1] == f"ytsearch10:{evil}" and cmd[-2] == "--" and cmd.count(cmd[-1]) == 1
        assert "--flat-playlist" in cmd and "-o" not in cmd
