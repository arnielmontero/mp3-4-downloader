PROJECT: YouTube Downloader — Web + Windows EXE

ROLE

You are the senior software engineer responsible for building the complete system described in this document.

IMPORTANT EXECUTION RULES

1. Build the system completely. Do not stop at a prototype.
2. Do not ask unnecessary questions.
3. Do not wait for confirmation between phases.
4. Make reasonable implementation decisions when details are not explicitly specified.
5. Keep the implementation production-ready and maintainable.
6. Run tests after implementation.
7. Fix errors instead of reporting them as unresolved when they can be fixed.
8. Do not claim a feature is complete until it has been implemented and tested.
9. At the end, provide a concise completion report containing:
   - What was implemented
   - Tests performed
   - Build results
   - Docker status
   - Windows EXE build status
   - Known limitations, if any
10. Do not implement DRM bypassing, authentication bypasses, or circumvention of access controls.
11. The application is intended for downloading content that the user is authorized to download or that is otherwise permitted to be downloaded.

============================================================

1. # PROJECT OBJECTIVE

Build a YouTube URL downloader system with TWO interfaces:

A. Web Application
B. Windows Desktop EXE

Both interfaces must use the same core download functionality.

The user should be able to:

1. Paste a supported YouTube URL.
2. Analyze the URL.
3. Display media information.
4. Choose MP4 or MP3.
5. Choose available quality where applicable.
6. Start the download.
7. View download progress.
8. Cancel a download.
9. Save the resulting file.
10. Open the downloaded file/folder.

The application must use:

- yt-dlp as the media download engine
- FFmpeg for audio extraction and media processing
- Docker for the web/server deployment
- Windows EXE for the desktop application

============================================================ 2. HIGH-LEVEL ARCHITECTURE
============================================================

The system should have this architecture:

WEB:

Browser
|
v
Web UI
|
v
Backend REST API
|
v
Download Manager
|
+---- yt-dlp
|
+---- FFmpeg
|
v
Temporary Storage
|
v
Final Download

DESKTOP:

Windows EXE
|
+---- Desktop UI
|
+---- Download Manager
|
+---- bundled yt-dlp
|
+---- bundled FFmpeg
|
v
User-selected Output Folder

The desktop application should NOT require Docker.

The desktop EXE should package the required runtime/dependencies so a normal Windows user can install/run it without manually installing Python, yt-dlp, or FFmpeg.

============================================================ 3. TECHNOLOGY REQUIREMENTS
============================================================

WEB BACKEND:

Preferred:

- PHP
- CodeIgniter 3
- REST API
- PHP 8.2+
- Docker

FRONTEND:

- HTML5
- CSS3
- Bootstrap 5
- JavaScript
- AJAX/fetch API

MEDIA:

- yt-dlp
- FFmpeg

DESKTOP:

Preferred implementation:

- Python-based desktop application
- PySide6 or another stable native Windows GUI framework
- PyInstaller for EXE packaging

The exact desktop GUI framework may be changed only if there is a strong technical reason.

DATABASE:

A database is NOT required for the basic application.

Use filesystem-based job metadata for the initial version.

If persistent job history is implemented, SQLite may be used.

Do not introduce MySQL/PostgreSQL unless genuinely necessary.

============================================================ 4. PROJECT STRUCTURE
============================================================

Create a clean repository structure similar to:

youtube-downloader/
|
+-- backend/
| +-- application/
| +-- system/
| +-- public/
| +-- Dockerfile
| +-- composer.json
|
+-- frontend/
| +-- index.html
| +-- assets/
| +-- css/
| +-- js/
|
+-- desktop/
| +-- app/
| +-- assets/
| +-- requirements.txt
| +-- build/
| +-- installer/
|
+-- media/
| +-- bin/
| +-- yt-dlp/
| +-- ffmpeg/
|
+-- storage/
| +-- downloads/
| +-- temp/
| +-- jobs/
| +-- logs/
|
+-- docker/
|
+-- tests/
|
+-- docker-compose.yml
|
+-- .env.example
|
+-- README.md
|
+-- BUILD-WINDOWS.md
|
+-- SECURITY.md
|
+-- LICENSE
|
+-- .gitignore

Keep generated downloads, temporary files, logs, and secrets out of Git.

============================================================ 5. WEB APPLICATION
============================================================

Create a modern simple downloader interface.

Main screen:

---

## YouTube Downloader

Paste YouTube URL

[________________________________________]

[ Analyze ]

After analysis:

Thumbnail

Title:
Example Video

Duration:
10:32

Uploader:
Example Channel

Available format:

( ) MP4
( ) MP3

Video quality:

[ Best available v ]

[ Download ]

Progress:

[████████████████------] 78%

Status:
Downloading...

[ Cancel ]

---

After completion:

Download complete.

[ Download File ]
[ Open Folder ]

============================================================ 6. SUPPORTED URLS
============================================================

Support normal YouTube URLs where yt-dlp supports them.

Examples:

https://www.youtube.com/watch?v=VIDEO_ID

https://youtu.be/VIDEO_ID

Also allow other YouTube URL forms supported by yt-dlp.

Validate and normalize URLs before processing.

Do not blindly execute arbitrary user-provided strings as shell commands.

The URL must be passed to the process using safe argument handling.

============================================================ 7. URL ANALYSIS
============================================================

When the user clicks Analyze:

Backend should:

1. Validate URL.
2. Verify that it is an allowed YouTube URL.
3. Run yt-dlp metadata extraction without downloading the media.
4. Return:

- title
- uploader/channel
- duration
- thumbnail
- webpage URL
- available formats
- available resolutions
- filesize if available
- media type information

Do not download the full media during analysis.

Example API response:

{
"success": true,
"data": {
"title": "Example Video",
"uploader": "Example Channel",
"duration": 632,
"duration_formatted": "10:32",
"thumbnail": "...",
"formats": {
"mp4": true,
"mp3": true
},
"qualities": [
"best",
"1080p",
"720p",
"480p",
"360p"
]
}
}

============================================================ 8. MP4 DOWNLOAD
============================================================

When MP4 is selected:

Use yt-dlp to download an appropriate video/audio combination.

Prefer:

- best available MP4-compatible video
- best available audio

If separate streams are required, use FFmpeg to merge them.

The resulting file must be:

.mp4

Do not blindly force an incompatible format.

Use yt-dlp format selection appropriately.

Support quality selection.

Example choices:

Best
1080p
720p
480p
360p

Only show qualities actually available where practical.

============================================================ 9. MP3 DOWNLOAD
============================================================

When MP3 is selected:

Use yt-dlp + FFmpeg.

Extract audio.

Convert to:

.mp3

Default audio quality:

192 kbps

Allow configuration for:

128 kbps
192 kbps
256 kbps
320 kbps

Do not claim that a source has higher audio quality than it actually provides.

If the source audio quality is lower, conversion cannot improve the original source quality.

============================================================ 10. FILENAME HANDLING
============================================================

Downloaded filenames must be safe.

Sanitize:

- slash
- backslash
- colon
- asterisk
- question mark
- quotation marks
- angle brackets
- pipe
- reserved Windows names
- control characters

Prevent path traversal.

Example:

Original:

My Video: Episode 1 / Test?

Safe:

My Video - Episode 1 - Test.mp4

Prevent overwriting existing files unless explicitly configured.

Possible behavior:

video.mp4
video (1).mp4
video (2).mp4

============================================================ 11. DOWNLOAD JOB SYSTEM
============================================================

Do not make long-running downloads block normal HTTP requests.

Implement a job system.

Example:

POST /api/download

Response:

{
"success": true,
"job_id": "abc123"
}

Then:

GET /api/download/abc123

Returns:

{
"job_id": "abc123",
"status": "downloading",
"progress": 47.5,
"speed": "3.2MiB/s",
"eta": "00:42"
}

Possible states:

queued
analyzing
downloading
processing
completed
failed
cancelled

============================================================ 12. DOWNLOAD PROGRESS
============================================================

The frontend must show:

- percentage
- downloaded size
- total size when available
- download speed
- ETA
- current status

Use yt-dlp progress information.

Do not fake progress.

If yt-dlp does not provide exact information, display an appropriate indeterminate state.

============================================================ 13. CANCEL DOWNLOAD
============================================================

Provide:

POST /api/download/{job_id}/cancel

Cancellation must:

1. Stop yt-dlp.
2. Stop FFmpeg if running.
3. Mark job cancelled.
4. Remove incomplete temporary files.
5. Preserve completed files.
6. Release resources.

The application must not leave orphaned processes.

============================================================ 14. TEMPORARY FILE MANAGEMENT
============================================================

Use a dedicated temporary directory.

Example:

storage/temp/{job_id}/

Final:

storage/downloads/{job_id}/

Never allow users to access arbitrary filesystem paths through the API.

After successful completion:

1. Move final file to final storage.
2. Remove temporary files.

Failed/cancelled jobs:

Delete temporary media.

Implement scheduled cleanup for abandoned jobs.

Configurable cleanup age:

Default:

24 hours

============================================================ 15. WEB API
============================================================

Implement:

POST /api/video/info

POST /api/download

GET /api/download/{job_id}

POST /api/download/{job_id}/cancel

GET /api/download/{job_id}/file

GET /api/health

Optional:

GET /api/download/history

DELETE /api/download/history/{id}

============================================================ 16. API VALIDATION
============================================================

Validate:

URL
format
quality
audio bitrate
job ID

Reject:

empty URL
invalid URL
unsupported domain
unsupported format
invalid quality
path traversal
shell metacharacter abuse

Never construct shell commands by concatenating raw user input.

Use process argument arrays or equivalent safe process execution.

============================================================ 17. RATE LIMITING
============================================================

Implement basic protection against abuse.

For example:

Analyze:

maximum 20 requests/minute/IP

Downloads:

maximum 5 new jobs/minute/IP

These values must be configurable through environment variables.

============================================================ 18. CONCURRENT DOWNLOAD LIMIT
============================================================

Prevent unlimited simultaneous downloads.

Default:

3 concurrent downloads.

Configurable:

MAX_CONCURRENT_DOWNLOADS=3

Additional jobs should remain queued.

============================================================ 19. FILE SIZE LIMIT
============================================================

Implement configurable maximum output size.

Example:

MAX_DOWNLOAD_SIZE_GB=10

If the expected size is known and exceeds the configured limit, reject the job.

If size is unknown, monitor actual downloaded size and stop when the limit is reached.

============================================================ 20. DURATION LIMIT
============================================================

Implement configurable maximum media duration.

Example:

MAX_VIDEO_DURATION_MINUTES=240

Reject media exceeding the limit.

This prevents accidental extremely large downloads.

============================================================ 21. SECURITY
============================================================

Security requirements:

1. Never execute arbitrary shell input.
2. Validate URLs.
3. Restrict accepted domains.
4. Sanitize filenames.
5. Prevent path traversal.
6. Do not expose internal filesystem paths.
7. Do not expose environment variables.
8. Do not expose yt-dlp command lines containing secrets.
9. Do not expose server filesystem structure.
10. Use randomized job IDs.
11. Validate every API parameter.
12. Limit request body size.
13. Limit concurrent jobs.
14. Limit download duration/size.
15. Clean temporary files.
16. Kill orphaned processes.
17. Use HTTPS in production.
18. Add security headers.
19. Do not allow arbitrary command execution through API parameters.

============================================================ 22. SSRF PROTECTION
============================================================

The application must not become a generic URL downloader.

Only allow supported YouTube domains.

Reject:

localhost
127.0.0.1
0.0.0.0
private IP addresses
internal hostnames
file://
ftp://
data://

Do not accept arbitrary URL schemes.

============================================================ 23. FRONTEND
============================================================

Use Bootstrap 5.

Keep the interface simple.

Responsive design.

Pages:

1. Downloader
2. Download Status
3. Settings/help if needed

Main controls:

URL input
Analyze
Format
Quality
Audio bitrate
Download
Cancel

Use AJAX/fetch.

Do not reload the entire page during download.

============================================================ 24. DESKTOP APPLICATION
============================================================

Create a native Windows desktop application.

Recommended:

Python
PySide6
PyInstaller

The EXE should provide:

- URL input
- Analyze button
- Thumbnail
- Title
- Duration
- Format selection
- Quality selection
- MP3 bitrate selection
- Output folder selection
- Download button
- Cancel button
- Progress bar
- Speed
- ETA
- Status
- Open file
- Open output folder

============================================================ 25. DESKTOP APPLICATION MODES
============================================================

The desktop application should work independently.

It must NOT require:

- Docker
- PHP
- CodeIgniter
- MySQL
- PostgreSQL
- Node.js
- Python installation

The final packaged application should include its required runtime.

============================================================ 26. DESKTOP BUNDLED BINARIES
============================================================

Bundle:

yt-dlp executable

FFmpeg executable

Do not require the user to manually install them.

Expected internal structure:

app/
YoutubeDownloader.exe
bin/
yt-dlp.exe
ffmpeg.exe
ffprobe.exe

The application must locate its bundled binaries reliably when running as a PyInstaller executable.

Do not rely on the current working directory.

Use the executable's runtime directory.

============================================================ 27. WINDOWS OUTPUT DIRECTORY
============================================================

Default:

User's Downloads folder.

Allow user to change it.

Store user settings locally.

Suggested:

%APPDATA%\YouTubeDownloader\

Settings:

config.json

Do not store secrets in plain text unless absolutely necessary.

============================================================ 28. DESKTOP DOWNLOAD FLOW
============================================================

1. User opens EXE.
2. User pastes URL.
3. User clicks Analyze.
4. Application gets metadata.
5. Application displays information.
6. User chooses MP3 or MP4.
7. User chooses quality.
8. User chooses output directory.
9. User clicks Download.
10. Application starts background worker.
11. UI remains responsive.
12. Progress updates continuously.
13. User can cancel.
14. Completed file is displayed.
15. User can open file/folder.

============================================================ 29. DESKTOP THREADING
============================================================

Do not perform downloads on the UI thread.

Use:

QThread
QRunnable
or equivalent background-worker mechanism.

UI must remain responsive during:

- metadata extraction
- downloading
- FFmpeg processing

============================================================ 30. DESKTOP ERROR HANDLING
============================================================

Display friendly errors.

Examples:

Invalid URL

"Please enter a valid YouTube URL."

Video unavailable

"The requested video could not be downloaded."

Network error

"Unable to connect. Please check your internet connection."

FFmpeg error

"Media processing failed."

File permission error

"The selected output folder is not writable."

Do not expose raw stack traces to normal users.

Log detailed errors separately.

============================================================ 31. LOGGING
============================================================

Backend:

storage/logs/

Desktop:

%APPDATA%\YouTubeDownloader\logs\

Log:

- timestamp
- job ID
- operation
- status
- duration
- error code
- sanitized error message

Do not log:

- cookies
- authentication tokens
- environment secrets
- unnecessary personal data

============================================================ 32. CONFIGURATION
============================================================

Use environment variables for server configuration.

Example:

APP_ENV=production
APP_URL=http://localhost
MAX_CONCURRENT_DOWNLOADS=3
MAX_DOWNLOAD_SIZE_GB=10
MAX_VIDEO_DURATION_MINUTES=240
DOWNLOAD_RETENTION_HOURS=24
RATE_LIMIT_ANALYZE=20
RATE_LIMIT_DOWNLOAD=5

Never commit .env.

Create:

.env.example

============================================================ 33. DOCKER
============================================================

The web application must run with Docker.

Provide:

docker-compose.yml

Services may include:

app
web
worker

Depending on the architecture.

Do not require Docker for Windows EXE.

Example:

docker compose up -d --build

The web application should then be accessible through the configured port.

============================================================ 34. DOCKER VOLUMES
============================================================

Persist:

storage/downloads
storage/jobs
storage/logs

Example:

volumes:

youtube_downloads:
youtube_jobs:
youtube_logs:

Do not store downloaded media inside the application image layer.

============================================================ 35. DOCKER HEALTH CHECK
============================================================

Create:

GET /api/health

Response:

{
"status": "ok",
"yt_dlp": true,
"ffmpeg": true
}

Docker healthcheck should use this endpoint or an equivalent internal check.

============================================================ 36. WEB SERVER
============================================================

Use a production-capable web server.

For example:

Nginx

- PHP-FPM

or another appropriate Docker architecture.

Do not use PHP's development server for production.

============================================================ 37. DOWNLOAD WORKER
============================================================

Worker responsibilities:

1. Retrieve queued jobs.
2. Validate job.
3. Run yt-dlp.
4. Parse progress.
5. Run FFmpeg when required.
6. Update job status.
7. Move final file.
8. Clean temporary files.
9. Record errors.
10. Exit cleanly.

Worker must handle:

SIGTERM
SIGINT

and clean child processes.

============================================================ 38. PROCESS MANAGEMENT
============================================================

Every yt-dlp process must be tracked by job ID.

Every FFmpeg process must be tracked by job ID.

Cancellation must kill the correct process only.

Never terminate all yt-dlp or FFmpeg processes globally.

============================================================ 39. JOB STORAGE
============================================================

For the basic version, use JSON files.

Example:

storage/jobs/abc123.json

Example:

{
"id": "abc123",
"url": "https://www.youtube.com/watch?v=...",
"format": "mp4",
"quality": "720p",
"status": "downloading",
"progress": 48.2,
"created_at": "...",
"updated_at": "..."
}

If concurrency or reliability becomes difficult with filesystem job storage, use SQLite.

Do not add a large database system unnecessarily.

============================================================ 40. FILE DOWNLOAD ENDPOINT
============================================================

The completed file must be downloadable through a controlled endpoint.

Do not expose:

/storage/

directly to the public web server.

Instead:

GET /api/download/{job_id}/file

must:

1. Verify job exists.
2. Verify status = completed.
3. Verify file exists.
4. Verify file belongs to that job.
5. Return the file.
6. Set correct Content-Type.
7. Set safe Content-Disposition.

============================================================ 41. CONTENT TYPES
============================================================

MP4:

video/mp4

MP3:

audio/mpeg

Use safe download headers.

============================================================ 42. THUMBNAILS
============================================================

Display the thumbnail returned by metadata extraction.

Do not permanently store thumbnails unless needed.

If downloaded/stored, apply cleanup rules.

============================================================ 43. QUALITY HANDLING
============================================================

Do not assume every video supports:

1080p
720p
480p
360p

Analyze available formats.

Display only valid choices where possible.

"Best" should select the best compatible available format.

============================================================ 44. MP4 COMPATIBILITY
============================================================

Prefer MP4-compatible formats.

When video and audio streams are separate:

yt-dlp downloads them.

FFmpeg merges them.

Ensure final extension is .mp4.

============================================================ 45. MP3 METADATA
============================================================

Where available, preserve useful metadata:

Title
Artist/Uploader
Album if available

Do not invent metadata.

Use FFmpeg metadata handling.

============================================================ 46. DESKTOP SETTINGS
============================================================

Desktop settings should include:

Output folder
Default format
Default video quality
Default MP3 bitrate
Maximum simultaneous downloads if supported

Save locally.

Provide:

Reset Settings

============================================================ 47. DESKTOP AUTO UPDATE
============================================================

Do not make automatic application updates mandatory for version 1.

Design the application so an update mechanism can be added later.

yt-dlp itself should be updateable.

Provide a documented method for updating bundled yt-dlp/FFmpeg.

Do not download arbitrary executables from untrusted sources.

============================================================ 48. VERSION INFORMATION
============================================================

Display:

Application version
yt-dlp version
FFmpeg version

Possible About screen:

YouTube Downloader
Version 1.0.0

Media engine:
yt-dlp x.x.x

FFmpeg:
x.x.x

============================================================ 49. TESTING
============================================================

Create automated tests for:

URL validation
URL normalization
filename sanitization
format validation
quality validation
job creation
job status
job cancellation
file authorization
path traversal protection
rate limiting
duration limit
file-size limit
cleanup
API responses

============================================================ 50. INTEGRATION TESTS
============================================================

Test:

1. Analyze valid URL.
2. Analyze invalid URL.
3. MP4 download.
4. MP3 download.
5. Quality selection.
6. Cancel download.
7. Failed download.
8. Cleanup.
9. File download.
10. Concurrent jobs.
11. Queue behavior.

Use test fixtures/mocks where external YouTube access would make tests unreliable.

Do not make the entire automated test suite dependent on YouTube being online.

============================================================ 51. DESKTOP TESTING
============================================================

Test:

- Application startup
- URL validation
- Analyze
- Format selection
- Quality selection
- Output folder
- Download
- Progress
- Cancel
- Completion
- Open file
- Settings persistence
- Invalid URL
- Network failure

============================================================ 52. WINDOWS BUILD
============================================================

Create a repeatable build process.

Example:

build_windows.bat

It should:

1. Create/activate build environment.
2. Install requirements.
3. Verify yt-dlp binary.
4. Verify FFmpeg binaries.
5. Build PyInstaller application.
6. Copy required binaries.
7. Produce final EXE.
8. Verify executable exists.

Output:

dist/
YouTubeDownloader.exe

============================================================ 53. WINDOWS INSTALLER
============================================================

Create an optional installer.

Preferred:

Inno Setup

Installer should:

- install EXE
- install bundled media binaries
- create Start Menu shortcut
- optionally create Desktop shortcut
- create uninstaller
- preserve user settings during uninstall where appropriate

Output:

installer/
YouTubeDownloader-Setup.exe

============================================================ 54. CODE QUALITY
============================================================

Use:

- clear class separation
- dependency injection where appropriate
- reusable services
- meaningful names
- comments for non-obvious logic
- no unnecessary duplicated code

Do not create one giant controller/class containing the entire system.

Recommended backend separation:

VideoInfoService
DownloadService
JobService
ProcessService
FileService
SecurityService
RateLimitService

Desktop:

MainWindow
DownloadWorker
YouTubeService
SettingsService
FileService
ProcessManager

============================================================ 55. API RESPONSE STANDARD
============================================================

Successful:

{
"success": true,
"data": {}
}

Failure:

{
"success": false,
"error": {
"code": "INVALID_URL",
"message": "The supplied URL is not supported."
}
}

Use stable error codes.

Examples:

INVALID_URL
UNSUPPORTED_DOMAIN
VIDEO_UNAVAILABLE
DOWNLOAD_FAILED
PROCESSING_FAILED
JOB_NOT_FOUND
JOB_CANCELLED
FILE_NOT_FOUND
FILE_TOO_LARGE
VIDEO_TOO_LONG
RATE_LIMITED
SERVER_ERROR

============================================================ 56. USER EXPERIENCE
============================================================

The application should be simple enough for a non-technical user.

Avoid exposing:

yt-dlp commands
FFmpeg commands
Docker
Python
PHP
technical logs

Normal users should see simple messages.

Detailed logs should be available for troubleshooting.

============================================================ 57. ACCESSIBILITY
============================================================

Use:

- proper labels
- keyboard navigation
- readable contrast
- visible focus states
- accessible buttons
- progress status announcements where practical

============================================================ 58. RESPONSIVE WEB DESIGN
============================================================

Web application must work on:

Desktop
Laptop
Tablet
Mobile browser

The primary use case is desktop, but mobile layout must remain usable.

============================================================ 59. NO ACCOUNT REQUIREMENT
============================================================

Version 1 does not require:

- user registration
- login
- subscription
- payment
- cloud account

The system is intended as a local/self-hosted downloader.

============================================================ 60. DOWNLOAD HISTORY
============================================================

Optional but recommended.

Show:

Filename
Title
Format
Date
Status
Size

Allow:

Download again
Open file
Delete history

History must not expose arbitrary filesystem locations.

============================================================ 61. CLEANUP
============================================================

Implement cleanup for:

temporary files
failed downloads
cancelled downloads
expired job metadata
expired history

Default retention:

24 hours for temporary data.

Completed files should remain until the user deletes them unless a configurable retention policy is enabled.

============================================================ 62. PRIVACY
============================================================

Do not send URLs or media metadata to third-party analytics.

Do not add advertising trackers.

Do not collect unnecessary personal information.

Downloaded content should remain on the local/server storage.

============================================================ 63. README
============================================================

Create a complete README.md.

Include:

Project overview
Features
Architecture
Requirements
Docker installation
Development setup
Production setup
Windows EXE build
Windows installer build
Configuration
API documentation
Testing
Troubleshooting
Security
Updating yt-dlp
Updating FFmpeg
Known limitations

============================================================ 64. WINDOWS BUILD DOCUMENTATION
============================================================

Create:

BUILD-WINDOWS.md

Include exact steps:

1. Install supported Python.
2. Create virtual environment.
3. Install dependencies.
4. Obtain verified yt-dlp binary.
5. Obtain verified FFmpeg binaries.
6. Build with PyInstaller.
7. Verify EXE.
8. Build installer.
9. Test clean Windows machine.

============================================================ 65. API DOCUMENTATION
============================================================

Document all endpoints.

For each endpoint include:

Method
Path
Request
Response
Errors
Example

============================================================ 66. SECURITY DOCUMENTATION
============================================================

Create:

SECURITY.md

Document:

- URL validation
- SSRF prevention
- command execution protection
- filename sanitization
- path traversal prevention
- rate limiting
- file size limits
- duration limits
- process isolation
- cleanup
- logging
- Docker security

============================================================ 67. LICENSE / THIRD-PARTY COMPONENTS
============================================================

Document third-party components.

Include:

yt-dlp
FFmpeg
CodeIgniter
Bootstrap
PySide6
PyInstaller
and other dependencies actually used.

Verify and comply with their respective licenses.

Do not claim ownership of third-party software.

============================================================ 68. ERROR RECOVERY
============================================================

If yt-dlp crashes:

Mark job failed.

If FFmpeg crashes:

Mark job failed.

If server restarts:

Queued/in-progress jobs must not remain permanently stuck.

On startup:

Detect stale jobs.

Mark them failed or requeue them safely.

Clean orphaned temporary files.

============================================================ 69. GRACEFUL SHUTDOWN
============================================================

Docker shutdown:

Worker receives SIGTERM.

Stop accepting new jobs.

Allow current process to terminate gracefully where possible.

Kill remaining child processes after timeout.

Clean temporary resources.

Desktop shutdown:

If a download is running:

Ask user whether to cancel and exit.

Do not silently corrupt active downloads.

============================================================ 70. OBSERVABILITY
============================================================

Provide:

Health endpoint
Structured logs where practical
Job status
Worker status

Avoid excessive logging.

============================================================ 71. DEVELOPMENT ENVIRONMENT
============================================================

Provide:

.env.example

Provide:

docker-compose.yml

Provide development instructions.

Example:

docker compose up -d --build

Then:

docker compose ps

Then:

open application in browser.

============================================================ 72. PRODUCTION CONSIDERATIONS
============================================================

Production deployment should use:

HTTPS
reverse proxy
secure environment variables
restricted filesystem permissions
non-root containers where practical
resource limits
rate limiting
log rotation
download storage monitoring

Do not expose worker management endpoints publicly.

============================================================ 73. RESOURCE LIMITS
============================================================

Docker should have reasonable resource controls.

Do not allow a single download to consume unlimited CPU/RAM/disk.

Configure:

CPU
memory
temporary storage
maximum downloads
maximum duration
maximum file size

============================================================ 74. DESKTOP RESOURCE MANAGEMENT
============================================================

Desktop should:

- avoid excessive RAM usage
- stream process output rather than loading huge output into memory
- clean temporary files
- limit concurrent downloads
- prevent UI freezes

============================================================ 75. MEDIA ENGINE ABSTRACTION
============================================================

Do not hard-code the entire application around one direct shell command.

Create an abstraction such as:

MediaDownloader

Methods:

get_info()
download_mp4()
download_mp3()
cancel()
get_progress()

This allows future replacement/updating of the media engine.

============================================================ 76. FUTURE EXTENSIBILITY
============================================================

Design so future versions could support:

- additional permitted media sources
- playlists
- download queue
- subtitles
- audio formats
- video formats
- scheduling
- browser integration

Do NOT implement those features unless they are required for version 1.

Do not over-engineer version 1.

============================================================ 77. MVP FEATURE SET
============================================================

Version 1 MUST contain:

WEB:

[YES]
YouTube URL input
URL validation
Metadata analysis
Thumbnail
Title
Duration
MP4
MP3
Quality selection
MP3 bitrate
Download progress
Cancel
Download file
Cleanup
Docker
REST API
Security validation
Rate limiting

DESKTOP:

[YES]
Windows EXE
URL input
Metadata analysis
MP4
MP3
Quality selection
MP3 bitrate
Progress
Cancel
Output directory
Open file
Open folder
Bundled yt-dlp
Bundled FFmpeg
PyInstaller build
Installer

============================================================ 78. DO NOT IMPLEMENT
============================================================

Do NOT implement:

- DRM bypass
- authentication bypass
- access-control circumvention
- arbitrary website downloader
- arbitrary command execution
- hidden background downloading
- cryptomining
- malware
- credential collection
- browser cookie theft
- unauthorized account access
- stealth persistence
- advertising injection

============================================================ 79. IMPLEMENTATION ORDER
============================================================

Build in this order:

PHASE 1
Repository structure.

PHASE 2
Backend foundation.

PHASE 3
URL validation.

PHASE 4
yt-dlp metadata service.

PHASE 5
MP4 download service.

PHASE 6
MP3 + FFmpeg service.

PHASE 7
Job manager.

PHASE 8
Progress tracking.

PHASE 9
Cancellation.

PHASE 10
File storage and cleanup.

PHASE 11
REST API.

PHASE 12
Bootstrap web UI.

PHASE 13
Docker.

PHASE 14
Backend automated tests.

PHASE 15
Desktop application.

PHASE 16
Desktop background workers.

PHASE 17
Desktop packaging.

PHASE 18
Windows installer.

PHASE 19
Integration tests.

PHASE 20
Security testing.

PHASE 21
Documentation.

PHASE 22
Final build verification.

============================================================ 80. ACCEPTANCE TESTS
============================================================

The project is complete only when these conditions pass.

TEST 1:

Start Docker.

Expected:

All containers start successfully.

TEST 2:

Open web application.

Expected:

Downloader UI loads.

TEST 3:

Enter valid YouTube URL.

Expected:

Metadata is returned.

TEST 4:

Select MP4.

Expected:

Video downloads and final file is playable.

TEST 5:

Select MP3.

Expected:

Audio downloads and final MP3 is playable.

TEST 6:

Cancel active download.

Expected:

Download stops and temporary data is cleaned.

TEST 7:

Invalid URL.

Expected:

Friendly validation error.

TEST 8:

Path traversal attempt.

Expected:

Request rejected.

TEST 9:

Unsupported domain.

Expected:

Request rejected.

TEST 10:

Excessive duration.

Expected:

Request rejected according to configured limit.

TEST 11:

Excessive file size.

Expected:

Request rejected or stopped according to configured limit.

TEST 12:

Run Windows EXE.

Expected:

Application starts on a clean supported Windows installation.

TEST 13:

Analyze from EXE.

Expected:

Metadata appears.

TEST 14:

Download MP4 from EXE.

Expected:

File appears in selected folder.

TEST 15:

Download MP3 from EXE.

Expected:

MP3 appears in selected folder.

TEST 16:

Cancel EXE download.

Expected:

Process stops cleanly.

TEST 17:

Close/reopen EXE.

Expected:

Settings remain saved.

TEST 18:

Build installer.

Expected:

Installer successfully installs application.

TEST 19:

Uninstall.

Expected:

Application is removed cleanly.

TEST 20:

Run backend automated tests.

Expected:

All tests pass.

============================================================ 81. FINAL VERIFICATION
============================================================

Before declaring completion:

Run:

- backend tests
- frontend validation/build
- desktop tests
- Docker build
- Docker startup
- API health check
- EXE build
- installer build
- security tests

Verify:

1. No missing dependencies.
2. No broken imports.
3. No syntax errors.
4. No hardcoded local paths.
5. No secrets committed.
6. No generated downloads committed.
7. Docker starts.
8. API responds.
9. Web UI loads.
10. EXE builds.
11. EXE launches.
12. MP4 workflow works.
13. MP3 workflow works.
14. Cancellation works.
15. Cleanup works.

============================================================ 82. FINAL DELIVERABLES
============================================================

The repository must contain:

Source code
Docker configuration
Web frontend
Backend API
Desktop application
Tests
Documentation
Build scripts
Environment example
Security documentation

Build artifacts:

dist/YouTubeDownloader.exe

and, if installer is implemented:

installer/YouTubeDownloader-Setup.exe

============================================================ 83. FINAL REPORT
============================================================

When all work is finished, report only the important results.

Use this format:

## PROJECT STATUS

Status: COMPLETE / INCOMPLETE

## WEB

Implemented:

- ...

Docker:

- ...

API:

- ...

## DESKTOP

EXE:

- ...

Installer:

- ...

## MEDIA

MP4:

- PASS/FAIL

MP3:

- PASS/FAIL

## TESTING

Backend:

- ...

Frontend:

- ...

Desktop:

- ...

Security:

- ...

## BUILD

Docker:

- ...

Windows EXE:

- ...

Installer:

- ...

## KNOWN LIMITATIONS

- ...

## FILES CREATED

- ...

If something genuinely cannot be completed, clearly identify it.

Do not mark the project COMPLETE if a required feature is missing.

============================================================ 84. FINAL INSTRUCTION
============================================================

Build the complete YouTube Downloader system according to this specification.

Do not stop after creating a skeleton.

Implement the actual working system.

Use clean architecture.

Test everything.

Fix implementation errors.

Build the Docker application.

Build the Windows EXE.

Build the Windows installer where supported.

Verify MP3 and MP4 workflows.

Verify cancellation and cleanup.

Verify security protections.

Update documentation to match the actual implementation.

Do not invent test results.

Do not claim success without verification.

At the end, provide the final completion report only after the implementation and verification work has been completed.
