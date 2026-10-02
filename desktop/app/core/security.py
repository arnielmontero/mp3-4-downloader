"""URL validation / normalisation (mirrors the web backend's SecurityService).

Only an explicit allow-list of YouTube hosts is accepted, so localhost, private addresses, internal
hostnames and non-http(s) schemes can never reach yt-dlp.
"""
from __future__ import annotations

import re
from urllib.parse import parse_qs, urlsplit

from .errors import MSG_INVALID_URL, InvalidUrlError, UnsupportedDomainError

ALLOWED_HOSTS = frozenset({
    "youtube.com", "www.youtube.com", "m.youtube.com", "music.youtube.com",
    "youtu.be", "www.youtu.be", "youtube-nocookie.com", "www.youtube-nocookie.com",
})
FORMATS = ("mp4", "mp3")
QUALITIES = ("best", "144p", "240p", "360p", "480p", "720p", "1080p", "1440p", "2160p", "4320p")
BITRATES = (128, 192, 256, 320)
DEFAULT_BITRATE = 192

_MAX_URL_LENGTH = 2048
_VIDEO_ID = re.compile(r"^[A-Za-z0-9_-]{11}$")
_FORBIDDEN_CHARS = re.compile(r"[\x00-\x20\x7f\"'`<>\\^{}|;$]")
_SCHEME_NO_SLASHES = re.compile(r"^[a-z][a-z0-9+.-]*:", re.I)
_HOST_PORT = re.compile(r"^[^/?#]+:\d+(/|$)")
_PATH_ID = re.compile(r"^/(?:shorts|live|embed|v)/([^/?#]+)/?$")


def extract_video_id(raw: object) -> str:
    """Validate a user-supplied URL and return the 11 character video id."""
    if not isinstance(raw, str) or not raw.strip():
        raise InvalidUrlError(MSG_INVALID_URL)
    url = raw.strip()
    if len(url) > _MAX_URL_LENGTH or _FORBIDDEN_CHARS.search(url):
        raise InvalidUrlError(MSG_INVALID_URL)

    if "://" not in url:
        if url.startswith("//"):
            url = "https:" + url
        elif _SCHEME_NO_SLASHES.match(url) and not _HOST_PORT.match(url):
            raise InvalidUrlError(MSG_INVALID_URL)  # file:, data:, javascript: ...
        else:
            url = "https://" + url

    try:
        parts = urlsplit(url)
        host = parts.hostname
        port = parts.port
    except ValueError:
        raise InvalidUrlError(MSG_INVALID_URL) from None
    if parts.scheme.lower() not in ("http", "https") or not host:
        raise InvalidUrlError(MSG_INVALID_URL)
    if parts.username is not None or parts.password is not None:
        raise InvalidUrlError(MSG_INVALID_URL)
    if port not in (None, 80, 443):
        raise UnsupportedDomainError(MSG_INVALID_URL)

    host = host.lower().rstrip(".")
    if host not in ALLOWED_HOSTS:
        raise UnsupportedDomainError(MSG_INVALID_URL)

    path = parts.path or ""
    video_id: str | None = None
    if host in ("youtu.be", "www.youtu.be"):
        video_id = path.lstrip("/").split("/")[0]
    elif path in ("/watch", "/watch/"):
        values = parse_qs(parts.query).get("v", [])
        video_id = values[0] if values else None
    else:
        match = _PATH_ID.match(path)
        if match:
            video_id = match.group(1)
        elif path.startswith(("/playlist", "/@", "/channel/", "/c/", "/user/")):
            raise InvalidUrlError("Only single video URLs are supported (no playlists or channels).")

    if not video_id or not _VIDEO_ID.match(video_id):
        raise InvalidUrlError(MSG_INVALID_URL)
    return video_id


def normalize_url(raw: object) -> str:
    return f"https://www.youtube.com/watch?v={extract_video_id(raw)}"


def validate_search_query(value: object) -> str:
    """1-100 characters, control characters become spaces, whitespace collapsed."""
    if not isinstance(value, str):
        raise InvalidUrlError("Please type something to search for.", code="INVALID_QUERY")
    text = re.sub(r"[\x00-\x1f\x7f\u200b-\u200f\u202a-\u202e\u2060-\u2064\ufeff]", " ", value)
    text = re.sub(r"\s+", " ", text).strip()
    if not text:
        raise InvalidUrlError("Please type something to search for.", code="INVALID_QUERY")
    if len(text) > 100:
        raise InvalidUrlError("The search text is too long (100 characters at most).", code="INVALID_QUERY")
    return text


def looks_like_url(value: str) -> bool:
    """True for input that is meant as a link (so it is analysed, not searched)."""
    v = value.strip().lower()
    return "://" in v or v.startswith(("www.", "youtube.com", "youtu.be", "m.youtube.com", "music.youtube.com", "//"))


def validate_format(value: object) -> str:
    value = value.strip().lower() if isinstance(value, str) else ""
    if value not in FORMATS:
        raise InvalidUrlError("Format must be MP4 or MP3.", code="INVALID_FORMAT")
    return value


def validate_quality(value: object) -> str:
    if value in (None, ""):
        return "best"
    value = value.strip().lower() if isinstance(value, str) else ""
    if value not in QUALITIES:
        raise InvalidUrlError("The selected quality is not valid.", code="INVALID_QUALITY")
    return value


def validate_bitrate(value: object) -> int:
    if value in (None, ""):
        return DEFAULT_BITRATE
    if isinstance(value, str) and value.isdigit():
        value = int(value)
    if isinstance(value, bool) or not isinstance(value, int) or value not in BITRATES:
        raise InvalidUrlError("Audio bitrate must be one of 128, 192, 256 or 320 kbps.", code="INVALID_BITRATE")
    return value


def safe_thumbnail(url: object) -> str | None:
    """Only thumbnails served by YouTube's image hosts are ever fetched/displayed."""
    if not isinstance(url, str):
        return None
    try:
        parts = urlsplit(url)
    except ValueError:
        return None
    host = (parts.hostname or "").lower()
    if parts.scheme != "https" or parts.username or not host:
        return None
    if host == "i.ytimg.com" or host.endswith(".ytimg.com") or host.endswith(".ggpht.com"):
        return url
    return None
