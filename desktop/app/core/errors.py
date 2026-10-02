"""Application errors carrying a stable code and a friendly, user-facing message."""
from __future__ import annotations


class AppError(Exception):
    """Base class: `message` is safe to show to a normal user, `code` is stable for tests/logs."""

    code = "ERROR"

    def __init__(self, message: str, code: str | None = None, detail: str = ""):
        super().__init__(message)
        self.message = message
        if code:
            self.code = code
        #: technical detail for the log file only - never shown to the user
        self.detail = detail


class InvalidUrlError(AppError):
    code = "INVALID_URL"


class UnsupportedDomainError(AppError):
    code = "UNSUPPORTED_DOMAIN"


class VideoUnavailableError(AppError):
    code = "VIDEO_UNAVAILABLE"


class NetworkError(AppError):
    code = "NETWORK_ERROR"


class DownloadFailedError(AppError):
    code = "DOWNLOAD_FAILED"


class ProcessingFailedError(AppError):
    code = "PROCESSING_FAILED"


class OutputFolderError(AppError):
    code = "OUTPUT_NOT_WRITABLE"


class EngineMissingError(AppError):
    code = "ENGINE_MISSING"


class CancelledError(AppError):
    code = "CANCELLED"


# Friendly texts (task.md section 30)
MSG_INVALID_URL = "Please enter a valid YouTube URL."
MSG_UNAVAILABLE = "The requested video could not be downloaded."
MSG_NETWORK = "Unable to connect. Please check your internet connection."
MSG_PROCESSING = "Media processing failed."
MSG_OUTPUT = "The selected output folder is not writable."
MSG_ENGINE = "The download engine (yt-dlp/FFmpeg) was not found. Please reinstall the application."
