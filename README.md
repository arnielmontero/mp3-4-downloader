# YouTube Downloader — Web + Windows EXE

*Developed by Arniel D. Montero.*

A self-hosted downloader for single YouTube videos with two front ends that share the same engine
(**yt-dlp** + **FFmpeg**):

* **Web** — nginx + PHP 8.3 (CodeIgniter 3) REST API + a download worker, all in Docker.
* **Desktop** — a native Windows app (Python + PySide6) packaged with PyInstaller; bundles its own yt-dlp/FFmpeg and
  needs no Docker, PHP or Python.

> Use it only for content you are authorized to download. No DRM/authentication/access-control circumvention is
> implemented (see *Known limitations*).

## Features

* **Search-first layout (web and desktop):** the main display searches YouTube (or takes a pasted link) and lists results
  with thumbnail, title, uploader and duration. Each result has an **MP3 / MP4** choice and a **Download** button; pressing it
  moves the download to the **right-hand Downloads sidebar**, which shows live percentage, speed and ETA with its own **Cancel**.
  You can keep searching and add **as many downloads as you like** at the same time (the web server runs
  `MAX_CONCURRENT_DOWNLOADS` at once and queues the rest; the desktop app runs 3 at once and queues the rest).
* MP3 uses 192 kbps by default (desktop: configurable in Settings); MP4 uses the best H.264/AAC quality (desktop: default quality
  from Settings). The API still accepts 128/192/256/320 kbps and 144p-4320p.
* MP4 prefers H.264/AAC for compatibility; separate streams are merged by FFmpeg into `.mp4`.
* MP3 is extracted/converted by FFmpeg with title/artist metadata (only what YouTube provides). The UI warns when the
  chosen bitrate exceeds the source audio — conversion cannot improve quality.
* **Playability guarantee:** before a file is offered, it is verified: ffprobe checks the container and streams (MP4 = video track,
  MP3 = audio track) and that the length matches what YouTube announced, then FFmpeg decodes the whole file and the decoded length must
  reach the announced length (this catches truncated files whose headers still promise the full length). A file that fails any step is
  discarded and the job fails with "The downloaded file is damaged or incomplete..." - nothing broken ever reaches you. The UI shows
  "Verifying..." while this runs.
* Safe, unique file names (`video.mp4`, `video (1).mp4`, …), never overwrites.
* Web: job queue (`MAX_CONCURRENT_DOWNLOADS`), rate limits, size/duration limits, history (per browser), scheduled cleanup,
  crash recovery, graceful shutdown. Desktop: output folder, settings in `%APPDATA%\YouTubeDownloader\config.json`, about box.

## Architecture

```
Browser ─► nginx (static UI, /api → FastCGI, X-Accel-Redirect for finished files)
              └─► PHP-FPM + CodeIgniter 3  (REST API, validation, rate limiting)  ─┐ JSON job files
                                                                                    ▼ storage/jobs/{id}.json
           Worker (php bin/worker.php) ─ event loop: claims jobs, one process group per job
              ├─ yt-dlp  ──► FFmpeg            temp:  storage/temp/{job}/
              └─ on success: storage/downloads/{job}/{safe title}.mp4|mp3

Windows EXE ─► PySide6 UI ─► YouTubeService ─► MediaDownloader (yt-dlp) ─► user's output folder
               (QThread workers)                bundled bin\yt-dlp.exe, ffmpeg.exe, ffprobe.exe, deno.exe
```

Backend classes (`backend/application/src`): `SecurityService`, `VideoInfoService`, `DownloadService`, `JobService`,
`ProcessService`, `FileService`, `RateLimitService`, `HistoryService`, `CleanupService`, `HealthService`, `Worker`, and the
`MediaDownloader` interface with `YtDlpDownloader`. Desktop (`desktop/app`): `MainWindow`, `DownloadWorker`,
`YouTubeService`, `SettingsService`, `FileService`, `ProcessManager`, `MediaDownloader`/`YtDlpDownloader`.

Repository layout: `backend/` · `frontend/` · `desktop/` · `docker/` · `media/` · `storage/` (runtime data, git-ignored) ·
`tests/` · `docker-compose.yml` · `.env.example` · `BUILD-WINDOWS.md` · `SECURITY.md` · `THIRD-PARTY-NOTICES.md` · `TRACKER.md`.

## Requirements

* Web: Docker with Compose v2 (internet access at image build time and for downloads).
* Desktop build: Windows 10/11 x64, Python 3.11+ (tested with 3.13), PowerShell, optionally Inno Setup 6. End users need nothing.

## Docker installation

```bash
cp .env.example .env          # optional; defaults work
docker compose up -d --build
docker compose ps             # app, worker, web should become "healthy"
```

Open <http://localhost:8080> (change with `WEB_PORT`). Health: `curl http://localhost:8080/api/health`.
Stop: `docker compose down` (volumes `youtube_downloads`, `youtube_jobs`, `youtube_logs`, `youtube_temp` are kept;
add `-v` to delete them). Downloads live in the `youtube_downloads` volume.

## Development setup

```bash
# backend unit + worker integration tests (inside Docker, real FFmpeg, fake yt-dlp)
docker build -f backend/Dockerfile --target test -t ytd-backend-test .

# or locally (PHP 8.2+, Composer): unit tests only
cd backend && composer install && vendor/bin/phpunit --testsuite unit

# frontend static checks + unit tests
node tests/frontend/validate.mjs && node --test tests/frontend/format.test.mjs

# desktop
python -m venv desktop/.venv && desktop\.venv\Scripts\pip install -r desktop/requirements-dev.txt
desktop\.venv\Scripts\python desktop\run_app.py         # run from source (needs media\bin, see BUILD-WINDOWS.md)
desktop\.venv\Scripts\python -m pytest tests/desktop
```

## Production setup

* Put a TLS-terminating reverse proxy (Caddy, Traefik, nginx…) in front of the `web` service; add HSTS there. Set
  `APP_URL=https://…` (Secure cookies) and, only if the proxy sets `X-Forwarded-For`, `TRUST_PROXY_HEADERS=true`.
* Containers already run non-root (`www-data`/`nginx`), with read-only root filesystem, dropped capabilities,
  `no-new-privileges`, CPU/memory/pids limits and a 30 s graceful-stop period for the worker. Tune `WORKER_CPUS`/`WORKER_MEMORY`.
* Monitor disk usage of `youtube_downloads`; set `COMPLETED_RETENTION_HOURS` for automatic expiry; rotate/ship logs from `youtube_logs`.
* Do not expose the worker (it has no network port). There are no management endpoints.
* There are no accounts: protect the instance with your proxy (basic auth/VPN) if it is reachable from untrusted networks.

## Windows EXE build / installer

`desktop\build\build_windows.bat` produces `dist\YouTubeDownloader.exe` + `dist\bin\`; `desktop\installer\build_installer.bat`
produces `installer\YouTubeDownloader-Setup.exe`. Exact steps: **[BUILD-WINDOWS.md](BUILD-WINDOWS.md)**.

## Configuration (environment variables)

See `.env.example` for every variable with its default. Most important: `MAX_CONCURRENT_DOWNLOADS`, `MAX_DOWNLOAD_SIZE_GB`,
`MAX_VIDEO_DURATION_MINUTES`, `DOWNLOAD_RETENTION_HOURS`, `RATE_LIMIT_ANALYZE`, `RATE_LIMIT_DOWNLOAD`, `HISTORY_SCOPE`.

## API

All responses: `{"success":true,"data":{…}}` or `{"success":false,"error":{"code":"…","message":"…"}}`.
Requests with a body must be `Content-Type: application/json` (max 8 KiB). Job ids are 32 hex characters.

| Method & path | Purpose |
|---|---|
| `GET /api/health` | `{"status":"ok","yt_dlp":true,"ffmpeg":true,"worker":{…}}` — 503 if yt-dlp/FFmpeg are missing |
| `GET /api/about` | app, yt-dlp and FFmpeg versions, limits |
| `POST /api/search` | search YouTube by text (max 10 results) |
| `POST /api/video/info` | analyze a pasted URL |
| `POST /api/download` | create a job → `202 {"success":true,"job_id":"…","data":{…}}` |
| `GET /api/download/{id}` | status/progress |
| `POST /api/download/{id}/cancel` | cancel (queued: immediate; running: stops that job's processes) |
| `GET /api/download/{id}/file` | the finished file (only when `completed`) |
| `GET /api/download/history` | this browser's jobs (cookie `ytd_client`) |
| `DELETE /api/download/history/{id}` | delete entry and stored file |

### `POST /api/search`
Request `{"query":"rick astley never gonna"}` (1-100 characters; control characters/whitespace are normalised) → `200`
```json
{"success":true,"data":{"query":"rick astley never gonna","results":[
 {"video_id":"dQw4w9WgXcQ","title":"…","uploader":"Rick Astley","duration":214,"duration_formatted":"3:34",
  "thumbnail":"https://i.ytimg.com/vi/dQw4w9WgXcQ/mqdefault.jpg","webpage_url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ"}]}}
```
Live streams are filtered out; an empty `results` list means nothing was found. Each result can be passed straight to `POST /api/download`
(`url` = `webpage_url`). Errors: `INVALID_QUERY` 400, `RATE_LIMITED` 429 (shares the `RATE_LIMIT_ANALYZE` limit), `DOWNLOAD_FAILED` 502 (network), `INVALID_REQUEST`/`REQUEST_TOO_LARGE`/`UNSUPPORTED_MEDIA_TYPE`.

### `POST /api/video/info`
Request `{"url":"https://youtu.be/dQw4w9WgXcQ"}` → `200`
```json
{"success":true,"data":{"video_id":"dQw4w9WgXcQ","title":"…","uploader":"…","duration":213,"duration_formatted":"3:33",
 "thumbnail":"https://i.ytimg.com/…","webpage_url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","is_live":false,
 "formats":{"mp4":true,"mp3":true},"qualities":["best","1080p","720p","360p"],"filesize":11903239,
 "source_audio_bitrate":130,"mp3_bitrates":[128,192,256,320],"default_mp3_bitrate":192,"limits":{…}}}
```
Errors: `INVALID_URL` 400, `UNSUPPORTED_DOMAIN` 400, `VIDEO_UNAVAILABLE` 422, `VIDEO_TOO_LONG` 422, `RATE_LIMITED` 429 (+`Retry-After`),
`REQUEST_TOO_LARGE` 413, `UNSUPPORTED_MEDIA_TYPE` 415, `DOWNLOAD_FAILED` 502.

### `POST /api/download`
Request `{"url":"…","format":"mp4","quality":"720p"}` or `{"url":"…","format":"mp3","bitrate":192}`.
Response `202 {"success":true,"job_id":"abc…","data":{"job_id":"abc…","status":"queued",…}}`.
Errors: `INVALID_URL`, `UNSUPPORTED_DOMAIN`, `INVALID_FORMAT`, `INVALID_QUALITY`, `INVALID_BITRATE` (400), `VIDEO_TOO_LONG` (422), `RATE_LIMITED` (429).

### `GET /api/download/{id}`
```json
{"success":true,"data":{"job_id":"…","status":"downloading","message":"Downloading...","progress":47.5,"indeterminate":false,
 "downloaded":"5.0MiB","total":"10.0MiB","speed":"3.2MiB/s","eta":"00:42","format":"mp4","quality":"720p","title":"…",
 "filename":null,"filesize":null,"error":null,"created_at":"…","updated_at":"…","finished_at":null}}
```
States: `queued, analyzing, downloading, processing, completed, failed, cancelled`. While FFmpeg merges/converts or when yt-dlp
cannot report a size, `indeterminate` is `true` (the UI shows a busy bar, never a fake percentage). Failed jobs carry
`error:{code,message}` with codes such as `VIDEO_UNAVAILABLE`, `VIDEO_TOO_LONG`, `FILE_TOO_LARGE`, `DOWNLOAD_FAILED`, `PROCESSING_FAILED`, `SERVER_ERROR`.
Errors: `INVALID_JOB_ID` 400, `JOB_NOT_FOUND` 404.

### `POST /api/download/{id}/cancel`
`200` with the job (normally already `cancelled`). `409 JOB_NOT_CANCELLABLE` for completed/failed jobs (their files are kept). Idempotent for cancelled jobs.

### `GET /api/download/{id}/file`
`200` with `Content-Type: video/mp4` or `audio/mpeg` and a safe `Content-Disposition: attachment; filename="…"; filename*=UTF-8''…`.
Errors: `JOB_NOT_READY` 409, `JOB_CANCELLED` 409, `FILE_NOT_FOUND` 404, `JOB_NOT_FOUND` 404, `INVALID_JOB_ID` 400.

### History
`GET /api/download/history` → `{"data":{"items":[{…job…,"file_available":true}]}}` (newest first, max 100).
`DELETE /api/download/history/{id}` → `{"data":{"deleted":true}}`; `409 JOB_ACTIVE` while the job is running; `404` for other browsers' entries.

```bash
curl -s -X POST localhost:8080/api/download -H "Content-Type: application/json" \
     -d '{"url":"https://youtu.be/dQw4w9WgXcQ","format":"mp3","bitrate":192}'
```

## Testing

| Suite | Command | Needs |
|---|---|---|
| Backend (unit + worker integration) | `docker build -f backend/Dockerfile --target test .` | Docker |
| End-to-end vs. running stack (fake yt-dlp) | `WEB_PORT=18080 docker compose -p ytd-test -f docker-compose.yml -f docker-compose.test.yml up -d --build` then `cd tests/e2e && python -m unittest test_e2e -v` | Docker, Python |
| Live check vs. real YouTube | `python tests/e2e/live_check.py http://localhost:8080 [URL]` | internet, ffprobe |
| Frontend | `node tests/frontend/validate.mjs && node --test tests/frontend/format.test.mjs` | Node 20+ |
| Desktop | `desktop\.venv\Scripts\python -m pytest tests/desktop` | Windows, FFmpeg on PATH |
| Web UI in a real browser (Playwright + installed Edge/Chrome, fresh fake-engine stack) | `python tests/e2e/ui_check.py http://localhost:18080` | `pip install playwright` |
| Packaged EXE (UI automation) | `powershell -File tests\desktop\gui_exe_check.ps1` | built EXE, internet |

The automated suites never depend on YouTube: they use `tests/fixtures/fake-yt-dlp` / `fake_ytdlp.py`.
`TRACKER.md` records what was verified and how.

## Troubleshooting

* **Port 8080 already in use** → set `WEB_PORT` in `.env`.
* **Downloads fail with HTTP 403 / "could not be downloaded"** → YouTube changes often. Update yt-dlp (below). The image
  includes Deno and yt-dlp's solver (`yt-dlp[default]`); an outdated yt-dlp is the usual cause. Some videos (private,
  age-restricted, members-only, region-locked, live) cannot be downloaded without signing in, which this app deliberately does not do.
* **`/api/health` shows `"worker":{"alive":false}`** → `docker compose logs worker`.
* **Logs**: `docker compose exec worker sh -c "tail -n 50 /var/www/storage/logs/app-*.log"` (JSON lines). Desktop: Help → *Open log folder*.
* **Stuck "queued"** → the worker is down or at `MAX_CONCURRENT_DOWNLOADS`.

## Security

Summary in `SECURITY.md`: strict YouTube host allow-list + SSRF rejection, argument-array process execution with `--`,
filename sanitising, path-traversal-proof file endpoint, rate/size/duration limits, per-job process-group kill, cleanup,
sanitised logs, hardened containers.

## Updating yt-dlp

* **Docker**: `docker compose build --no-cache app && docker compose up -d` (latest release; set `YTDLP_VERSION=2026.08.19`
  in `.env` to pin). Check the version at `/api/about` or the *Help* tab.
* **Windows**: `powershell -File desktop\build\fetch_media.ps1 -Force -Only yt-dlp`, then rebuild/reinstall; or replace
  `bin\yt-dlp.exe` next to the installed EXE with a binary from <https://github.com/yt-dlp/yt-dlp/releases> after checking
  it against that release's `SHA2-256SUMS`. Never use executables from other sources.

## Updating FFmpeg

* **Docker**: FFmpeg comes from the Debian package of the base image: rebuild with `--pull --no-cache`.
* **Windows**: `fetch_media.ps1 -Force -Only ffmpeg` (downloads gyan.dev "release essentials", verified against its published SHA-256).

## Known limitations

* YouTube enforcement (PO tokens, JS challenges, SABR) changes constantly; downloads may break until yt-dlp is updated.
  Videos requiring login/age confirmation/membership or live streams are refused — no cookies/PO-token workarounds are implemented.
* Single videos only (no playlists/channels). No subtitles, scheduling or browser integration (extension points exist: `MediaDownloader`).
* The web "Open Folder" button (on a finished card) opens the **History** tab (a server cannot open folders on the visitor's computer).
* No accounts: history is scoped by an anonymous cookie, not a login. Job metadata is JSON files (fine for personal/small team use; not meant for thousands of jobs).
* The desktop app runs up to 3 downloads at once (`MainWindow.MAX_PARALLEL`); more wait in the sidebar. The desktop EXE is not code-signed, so Windows SmartScreen may warn.
* The Windows build is a one-file EXE that unpacks Qt into a temp folder at start; the `bin\` folder must stay next to it.
* FFmpeg builds bundled/installed are GPL-licensed; see `THIRD-PARTY-NOTICES.md`.
