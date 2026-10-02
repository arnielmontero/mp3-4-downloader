# media/

`media/bin/` receives the Windows binaries bundled by the desktop build (`yt-dlp.exe`, `ffmpeg.exe`, `ffprobe.exe`,
`deno.exe`). They are **not committed**: run `desktop\build\fetch_media.ps1`, which downloads each from its official
source and verifies the vendor-published SHA-256 before use. The Docker image installs its own copies at build time.
