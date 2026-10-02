# Project Tracker

Legend: `[ ]` todo · `[~]` in progress · `[x]` done and verified · `[!]` blocked / partial (see note)

Only items that were implemented **and** tested are ticked.

## Phases (task.md §79)

- [x] P1  Repository structure
- [x] P2  Backend foundation (CI3, config, base controller, logging)
- [x] P3  URL validation / SSRF / normalization
- [x] P4  yt-dlp metadata service
- [x] P5  MP4 download service
- [x] P6  MP3 + FFmpeg service
- [x] P7  Job manager (JSON files)
- [x] P8  Progress tracking
- [x] P9  Cancellation (per-job process groups)
- [x] P10 File storage + cleanup + stale-job recovery
- [x] P11 REST API
- [x] P12 Bootstrap web UI
- [x] P13 Docker (nginx + php-fpm + worker)
- [x] P14 Backend automated tests
- [x] P15 Desktop application (PySide6)
- [x] P16 Desktop background workers
- [x] P17 Desktop packaging (PyInstaller)
- [x] P18 Windows installer (Inno Setup)
- [x] P19 Integration tests
- [x] P20 Security testing
- [x] P21 Documentation
- [x] P22 Final build verification

## Acceptance tests (task.md §80)

| #  | Test | Status | Evidence |
|----|------|--------|----------|
| 1 |Docker starts | [x] | `docker compose ps`: app/web/worker healthy |
| 2 |Web UI loads | [x] | e2e `test_02_ui_loads`; frontend validate.mjs |
| 3 |Valid URL -> metadata | [x] | e2e `TestAnalyze`; live_check (real YouTube) |
| 4 |MP4 download playable | [x] | live_check: h264+aac, 213 s; e2e fake MP4 |
| 5 |MP3 download playable | [x] | live_check: mp3 213 s @192k; e2e fake MP3 |
| 6 |Cancel + temp cleaned | [x] | e2e `TestCancellation` (process group gone, temp removed); live cancel at 8 % |
| 7 |Invalid URL friendly error | [x] | e2e `TestAnalyze` (invalid, SSRF, metachar) |
| 8 |Path traversal rejected | [x] | e2e `test_job_ids_are_validated_and_traversal_is_rejected` |
| 9 |Unsupported domain rejected | [x] | e2e `test_unsupported_domains_and_ssrf_are_rejected` |
| 10 |Excessive duration rejected | [x] | e2e `test_excessive_duration_is_rejected` |
| 11 |Excessive size rejected/stopped | [x] | e2e `test_size_limits` (known + unknown size) |
| 12 |EXE starts | [x] | `gui_exe_check.ps1`: window in 1.2 s |
| 13 |Analyze from EXE | [x] | `gui_exe_check.ps1` T13 |
| 14 |MP4 from EXE | [x] | `gui_exe_check.ps1` T14: h264+aac |
| 15 |MP3 from EXE | [x] | `gui_exe_check.ps1` T15: mp3 128k |
| 16 |Cancel EXE download | [x] | `gui_exe_check.ps1` T16: 0 stray processes, temp clean |
| 17 |Settings persist | [x] | `gui_exe_check.ps1` T17 + pytest |
| 18 |Installer builds/installs | [x] | installer built (145 MB); silent install, self-test of installed copy OK |
| 19 |Uninstall clean | [x] | silent uninstall: dir + shortcuts removed |
| 20 |Backend tests pass | [x] | 185 PHPUnit tests OK in Docker test stage |

## Notes / decisions / deviations

- Test totals (final run): backend PHPUnit 185 (unit 173 + worker integration, Docker/Linux, real FFmpeg); e2e 35 against the running Docker stack
  (fake yt-dlp); desktop pytest 136 (core 108, engine integration 13, GUI offscreen 15); frontend 5 node tests + static validation;
  packaged-EXE UI automation 18/18; live YouTube checks (web 5/5, desktop self-test MP4+MP3, installed copy self-test).
- Deviation: the EXE is a one-file build at `dist/YouTubeDownloader.exe` with `dist/bin/` next to it (matches the spec path).
  yt-dlp/FFmpeg/Deno are not packed inside it.
- Finding during build: YouTube now needs a current yt-dlp + `yt-dlp-ejs` + a JS runtime (Deno) for most videos; a stale yt-dlp gave HTTP 403.
  Docker image installs `yt-dlp[default]` + Deno; desktop bundles yt-dlp.exe + deno.exe. No PO-token/cookie workaround was added.
- Not verified: a pristine Windows machine/VM (all Windows tests ran on the development machine); code signing; ARM64 images.
- Port 8080 was occupied on the dev machine, so local runs used `WEB_PORT` (8085 / 18080).

## Search + sidebar feature (added after the first release)
- Web: `POST /api/search`, search-first UI with a right-hand Downloads sidebar (any number of downloads at once).
  Verified: PHPUnit 188 (Docker), e2e 39 (fake engine, incl. 4 search tests), real-browser UI check 18/18 (Playwright + Edge), live search on real
  YouTube followed by two simultaneous MP3 downloads (live_check).
- Desktop: search main display + right sidebar (`ResultRow`, `DownloadItem`), up to 3 simultaneous downloads, the rest queue.
  Verified: pytest 144 (core 110, engine integration 17, GUI 17), packaged-EXE UI automation 21/21 (search, MP3+MP4 together, two downloads with
  progress, cancel one of two, restart persistence), installer rebuilt.
- Bug found by the new tests: `ProcessService::run()` never drained stderr, so error classification of one-shot commands saw an empty message
  (fixed). Per-row format choice on desktop is two radio buttons (a combo box cannot be driven reliably through UI Automation / screen readers).

## Playability verification (release 1.1.1)
- Every MP3/MP4 is verified (ffprobe structure + announced length, then a full FFmpeg decode whose decoded length must reach the announced length)
  before delivery, on both web (worker stage, non-blocking) and desktop. Damaged files are discarded with PROCESSING_FAILED.
- Test evidence: PHPUnit 200 (damaged cases: truncated mp4/mp3, garbage, corrupted payload, too short -> rejected; good files decode clean);
  e2e 41 (incl. TestPlayability); desktop pytest 167 (7 damaged cases + decode of delivered files + cancel during verification);
  real YouTube: web MP4/MP3 and 4 searched MP3s verified OK, desktop MP4 360p/best + MP3 incl. a 10-minute video verified OK (no false rejections);
  packaged EXE UI automation 21/21.
- Finding: a truncated MP3 passed ffprobe and a plain decode (its Xing header still promised the full length); only comparing the decoded length with
  the announced length catches it - that check was added after the test failed.
- One real download failed once with YouTube HTTP 403 (unrelated to verification, the retry succeeded); this can happen when YouTube throttles.
