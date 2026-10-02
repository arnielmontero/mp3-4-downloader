# Third-party components

This project is MIT-licensed (see `LICENSE`). It uses and/or redistributes the components below. They are
**not** owned by this project and remain under their own licenses. License identifiers were taken from the
package metadata / license files of the exact versions used while building (checked 2026-10-02).

## Media engine

| Component | Used for | License | Notes |
|---|---|---|---|
| [yt-dlp](https://github.com/yt-dlp/yt-dlp) | metadata + media download | Unlicense (public domain). The standalone `yt-dlp.exe` also embeds third-party libraries under their own licenses (see the yt-dlp release notes). | Bundled unmodified, downloaded from the official release and verified against `SHA2-256SUMS`. |
| [yt-dlp-ejs](https://github.com/yt-dlp/ejs) | YouTube JS-challenge solver scripts | Unlicense AND MIT AND ISC | Installed with `yt-dlp[default]` in the Docker image; embedded in `yt-dlp.exe`. |
| [FFmpeg](https://ffmpeg.org/) | merging streams, MP3 conversion | LGPL-2.1+ / **GPL-3.0** depending on the build. The Windows build used here is the *gyan.dev "release essentials"* build, which is **GPL v3**; the Debian `ffmpeg` package in the Docker image is built with GPL components too. | FFmpeg is invoked as a separate executable (not linked). Redistributing the binaries carries GPL obligations: keep `bin\FFMPEG-LICENSE.txt` with them and be ready to provide the corresponding source (FFmpeg source: <https://ffmpeg.org/download.html>, build recipe: <https://www.gyan.dev/ffmpeg/builds/>). |
| [Deno](https://deno.com/) | JavaScript runtime required by yt-dlp for YouTube | MIT | Bundled unmodified, checksum verified. |

## Web application

| Component | License |
|---|---|
| [CodeIgniter 3](https://codeigniter.com/userguide3/) (3.1.13, `codeigniter/framework`) | MIT |
| [Bootstrap](https://getbootstrap.com/) 5.3.3 (self-hosted copy in `frontend/assets/vendor`) | MIT |
| [PHP](https://www.php.net/) 8.3 (Docker base image `php:8.3-fpm-bookworm`) | PHP License v3.01 |
| [nginx](https://nginx.org/) (`nginxinc/nginx-unprivileged`) | BSD-2-Clause |
| Debian GNU/Linux packages in the image (python3, ffmpeg, util-linux, ...) | various (DFSG-free), see `/usr/share/doc/*/copyright` in the image |
| [Composer](https://getcomposer.org/) (build time only) | MIT |
| [PHPUnit](https://phpunit.de/) (tests only, not shipped) | BSD-3-Clause |

Python libraries installed into the yt-dlp virtualenv of the Docker image (via `yt-dlp[default]`):
brotli (MIT), certifi (MPL-2.0), charset-normalizer (MIT), idna (BSD-3-Clause), mutagen (**GPL-2.0-or-later**),
pycryptodomex (BSD / public domain), requests (Apache-2.0), urllib3 (MIT), websockets (BSD-3-Clause).

## Desktop application

| Component | License | Notes |
|---|---|---|
| [PySide6 / Qt for Python](https://doc.qt.io/qtforpython-6/) 6.x | **LGPL-3.0-only** OR GPL-2.0-only OR GPL-3.0-only | Used under the LGPLv3. The PyInstaller build links Qt dynamically (DLLs unpacked next to the program at run time); users may replace them. Qt sources: <https://download.qt.io/>. |
| [PyInstaller](https://pyinstaller.org/) | GPL-2.0-or-later **with a special exception** that allows building and distributing non-GPL programs | Build tool; its bootloader is embedded in the EXE under that exception. |
| [Python](https://www.python.org/) | PSF License | runtime embedded in the EXE |
| [pytest](https://pytest.org/) | MIT | tests only |
| [Inno Setup](https://jrsoftware.org/isinfo.php) | Inno Setup License (free, including commercial use) | build tool for the installer only |

## Trademarks / content

YouTube is a trademark of Google LLC; this project is not affiliated with or endorsed by YouTube or Google.
The software is meant for downloading content you own, that is licensed for download, or that you are otherwise
permitted to download. You are responsible for complying with the terms of service and copyright law that apply to you.
