# Security

Scope: a self-hosted/local downloader without user accounts. Do not expose it to the open internet without a reverse
proxy providing TLS and access control. Report vulnerabilities privately to the maintainer.

Every item below is covered by automated tests (`backend` unit/integration, `tests/e2e`, `tests/desktop`) unless noted.

## URL validation and SSRF prevention
* Input must be a string ≤ 2048 characters without whitespace/control/shell metacharacters (`; | $ \` " ' < > \ ^ { }`).
* Parsed with `parse_url`/`urlsplit`; scheme must be `http`/`https`; user-info and ports other than 80/443 are rejected.
* The host must be **exactly** one of: `youtube.com`, `www.`, `m.`, `music.youtube.com`, `youtu.be`, `youtube-nocookie.com` (+`www.`).
  Everything else — `localhost`, `127.0.0.1`, `0.0.0.0`, private/link-local IPs (also decimal/hex/IPv6 forms), internal names such as
  `app`, look-alike domains (`youtube.com.evil.com`, `evil.youtube.com`, `user@host` tricks, Unicode homographs), `file:`, `ftp:`,
  `data:`, `javascript:` — is refused with `UNSUPPORTED_DOMAIN`/`INVALID_URL` **before any process starts**.
* Only single videos are accepted; the URL is rewritten to the canonical `https://www.youtube.com/watch?v=<11-char id>`,
  so only the validated id ever reaches yt-dlp.
* Thumbnails are only shown from `*.ytimg.com`/`*.ggpht.com` over https.

## Search input
`POST /api/search` accepts 1-100 characters of text (control/invisible characters stripped, whitespace collapsed, valid UTF-8). The text is
passed to yt-dlp as the single argument `ytsearchN:<text>` after `--`, so it can never be parsed as an option or shell syntax; only
11-character video ids from the result are used afterwards, thumbnails are derived from the id (`i.ytimg.com`). Searches are rate limited
like analyses. The desktop app validates the same way.

## Command-execution protection
* Processes are started with argument arrays (`proc_open([...])` with `bypass_shell`, Python `Popen(list, shell=False)`); no shell
  string is ever built. The URL is placed after `--` so it cannot be read as an option; format/quality/bitrate come from fixed allow-lists.
* No user-controlled option is forwarded to yt-dlp/FFmpeg (`--ignore-config` also blocks config files). The JS-runtime setting is
  operator configuration, validated against a strict character set.
* Children get a minimal environment (application secrets are not inherited).

## Filename sanitisation and path traversal
* Titles become file names only through `sanitizeFilename`: path separators/colons → ` - `, `.`/`..` segments dropped, `* ? " < > |`,
  control and invisible Unicode (bidi override) characters removed, Windows reserved names prefixed, length capped at 150 bytes
  (valid UTF-8 kept), never empty. Existing files are never overwritten (`name (1).ext`).
* yt-dlp writes to server-chosen names (`%(id)s.%(ext)s`) inside `storage/temp/{job}/`; the final file goes to
  `storage/downloads/{job}/`.
* Job ids are 128-bit random hex and validated (`^[a-f0-9]{32}$`) at every entry point. The file endpoint requires status
  `completed`, resolves `downloads/{id}/{filename}` with `realpath`, and refuses anything that is not a regular, non-symlink file
  directly inside that job's directory. Clients can never pass a path.
* nginx serves finished files only through an `internal` location reached via `X-Accel-Redirect`; `/storage`, `/backend`, `/vendor`,
  dotfiles are not reachable.
* API errors never include filesystem paths, stack traces, environment values or command lines (`SERVER_ERROR` is generic; details go to the log).

## Rate limiting
Per client IP, sliding window, file-backed: `RATE_LIMIT_ANALYZE` (default 20/min) and `RATE_LIMIT_DOWNLOAD` (5/min) → `429 RATE_LIMITED`
with `Retry-After`. nginx adds `limit_req`. `X-Forwarded-For` is ignored unless `TRUST_PROXY_HEADERS=true` (set it only behind your own proxy).

## Size, duration, concurrency, timeouts
* `MAX_VIDEO_DURATION_MINUTES` (analysis + worker), `MAX_DOWNLOAD_SIZE_GB`: known sizes are rejected up front, `--max-filesize` is passed
  to yt-dlp and the temp directory is monitored once per second — unknown sizes are stopped (`FILE_TOO_LARGE`) and wiped.
* `MAX_CONCURRENT_DOWNLOADS` (default 3) — extra jobs wait queued. `DOWNLOAD_TIMEOUT_MINUTES` stops runaway jobs.
* Request bodies: nginx 16 KiB hard limit, application limit 8 KiB, JSON object required, `Content-Type` enforced.
* Docker: CPU/memory/pids limits per service; PHP `memory_limit` 128M.

## Process isolation
Each job runs in its own process group/session (`setsid` on Linux, `taskkill /T` on Windows). Cancel kills **only that job's tree**
(yt-dlp + FFmpeg), never other jobs or all yt-dlp processes. After SIGTERM the worker stops accepting work, terminates children,
kills stragglers after `KILL_GRACE_SECONDS`, removes temp data and re-queues interrupted jobs. At start-up stale "running" jobs are
re-queued (max `MAX_JOB_ATTEMPTS`) or failed and orphaned temp directories removed. The desktop app kills all children on exit.

## Cleanup
Scheduled (every `CLEANUP_INTERVAL_SECONDS`) and on demand (`php bin/cleanup.php`): temp directories of finished/abandoned jobs, failed/cancelled
metadata older than `DOWNLOAD_RETENTION_HOURS` (24), orphaned download folders, old logs, rate-limit and info-cache files. Completed files are kept
until the user deletes them unless `COMPLETED_RETENTION_HOURS` is set.

## Logging
JSON lines (timestamp, job id, operation, status, duration, error code, message) in `storage/logs` (web) and
`%APPDATA%\YouTubeDownloader\logs` (desktop, rotated). Messages are sanitised: control characters stripped, cookies/tokens/passwords/
signatures/`Authorization` values redacted, storage paths masked, length capped. Cookies and credentials are never requested or stored.

## Web hardening
Security headers on every response (`X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, strict
`Content-Security-Policy` with self-hosted assets only, `Permissions-Policy`, COOP/CORP); `server_tokens off`; no `X-Powered-By`.
The UI renders data with `textContent` (no `innerHTML`), has no third-party requests (no CDN, analytics or ads — Bootstrap is self-hosted).
The history cookie `ytd_client` is random, `HttpOnly`, `SameSite=Lax` (`Secure` when `APP_URL` is https) and only scopes the history list;
access to a file additionally requires knowing its unguessable job id. HSTS must be added by the TLS-terminating proxy.

## Docker security
Non-root users (`www-data`, `nginx` unprivileged), read-only root filesystem with tmpfs `/tmp`, `cap_drop: ALL`,
`no-new-privileges`, only the `web` port published (PHP-FPM and the worker are internal), downloads mounted read-only into nginx,
media stored in named volumes (not in image layers), healthchecks for all three services, pinned/verified Deno download.

## Desktop
No secrets in `config.json`; settings validated on load; only `https` YouTube thumbnails are fetched; binaries are located relative to the
EXE; unrecognised errors show a generic message while details go to the log. The EXE is not code-signed.

## Out of scope / not implemented (by design)
DRM bypass, login/cookie/PO-token workarounds, arbitrary-site downloading, remote management endpoints.
