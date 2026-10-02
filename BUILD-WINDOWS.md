# Building the Windows EXE and installer

Tested on Windows 11 x64 with Python 3.13, PySide6 6.11, PyInstaller 6.22, Inno Setup 6.7.

## One command

```bat
desktop\build\build_windows.bat            :: add "skip-fetch" and/or "skip-tests" to shorten
desktop\installer\build_installer.bat      :: optional installer (needs Inno Setup 6)
```

Outputs: `dist\YouTubeDownloader.exe` (+ `dist\bin\` with the media binaries) and
`installer\YouTubeDownloader-Setup.exe`. The script stops at the first error and ends with `BUILD OK` / `BUILD FAILED`.

## Exact steps (what the script does)

1. **Install a supported Python** — 3.11 or newer, 64-bit, from <https://www.python.org/downloads/> ("Add to PATH"/`py` launcher).
2. **Create the virtual environment**
   ```bat
   py -3 -m venv desktop\.venv
   ```
3. **Install dependencies**
   ```bat
   desktop\.venv\Scripts\python -m pip install -r desktop\requirements-dev.txt
   ```
   (`requirements.txt` = runtime: PySide6; `-dev` adds PyInstaller and pytest.)
4. **Obtain a verified yt-dlp binary and 5. verified FFmpeg binaries**
   ```powershell
   powershell -ExecutionPolicy Bypass -File desktop\build\fetch_media.ps1
   ```
   Downloads into `media\bin\` and **aborts on any checksum mismatch**:
   * `yt-dlp.exe` — official GitHub release, checked against that release's `SHA2-256SUMS`;
   * `ffmpeg.exe`, `ffprobe.exe` — gyan.dev *release essentials* zip, checked against its published `.sha256`;
   * `deno.exe` — official Deno release (the JavaScript runtime yt-dlp needs for YouTube), checked against its `.sha256sum`.

   Prefer your own binaries? Copy them to `media\bin\` yourself and run the build with `skip-fetch`; verify them first.
6. **Build with PyInstaller** (done by the script)
   ```bat
   desktop\.venv\Scripts\python desktop\build\make_icon.py
   desktop\.venv\Scripts\python -m PyInstaller --noconfirm --clean --distpath dist --workpath desktop\build\work desktop\build\YouTubeDownloader.spec
   ```
   then the media binaries, `LICENSE.txt` and `THIRD-PARTY-NOTICES.md` are copied to `dist\bin\` / `dist\`.
   Final layout (the app finds `bin\` relative to the **EXE location**, never the working directory):
   ```
   dist\YouTubeDownloader.exe
   dist\bin\yt-dlp.exe  ffmpeg.exe  ffprobe.exe  deno.exe  FFMPEG-LICENSE.txt  VERSIONS.txt
   ```
7. **Verify the EXE** — the script runs `YouTubeDownloader.exe --self-test` from an unrelated working directory: it checks
   that all binaries are found, prints their versions and creates the real window offscreen; exit code 0 = OK.
   For a real end-to-end check add a download:
   ```bat
   dist\YouTubeDownloader.exe --self-test --download "https://youtu.be/dQw4w9WgXcQ" --format mp3 --output C:\temp\out
   ```
   Full GUI automation of the built EXE (start, analyze, MP3, MP4, cancel, settings persistence):
   ```powershell
   powershell -ExecutionPolicy Bypass -File tests\desktop\gui_exe_check.ps1
   ```
8. **Build the installer** — `desktop\installer\build_installer.bat` (compiles `desktop\installer\YouTubeDownloader.iss`;
   install Inno Setup with `winget install JRSoftware.InnoSetup`). The installer installs EXE + `bin\`, creates a Start Menu
   shortcut and an optional desktop shortcut, registers an uninstaller, and on a normal (non-silent) uninstall asks whether to
   delete the settings/logs in `%APPDATA%\YouTubeDownloader` (silent uninstall keeps them; downloaded media is never deleted).
   Per-user install by default (no admin rights); silent switches: `/VERYSILENT /SUPPRESSMSGBOXES /DIR=...`.
9. **Test on a clean Windows machine** — copy only `installer\YouTubeDownloader-Setup.exe` to a machine/VM without Python, PHP,
   Docker, yt-dlp or FFmpeg; install, start from the Start Menu, analyze a URL, download MP3 and MP4, cancel one, close and
   reopen (settings persist), uninstall (folder, Start Menu and desktop shortcuts disappear).
   *Note: this repository's automated runs were done on a development machine, not a pristine VM — see `TRACKER.md`.*

## Updating bundled components

* yt-dlp: `fetch_media.ps1 -Force -Only yt-dlp`, rebuild. FFmpeg: `-Only ffmpeg`. Deno: `-Only deno -DenoVersion X.Y.Z`.
* A quick fix without rebuilding: replace `bin\yt-dlp.exe` beside the installed EXE (verify the checksum first).
* Auto-update of the application itself is intentionally not part of v1 (the version lives in `desktop/app/__init__.py`
  and the installer `AppId`/`AppVersion`, so an update mechanism can be added later).

## Notes

* One-file build: Windows SmartScreen/antivirus may warn about an unsigned, self-extracting EXE. Sign it with your certificate
  (`signtool sign /fd SHA256 /tr <timestamp-url> dist\YouTubeDownloader.exe`) for distribution.
* FFmpeg (GPL build) and the other bundled components keep their licenses — see `THIRD-PARTY-NOTICES.md`.
