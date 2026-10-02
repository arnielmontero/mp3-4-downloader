<#
.SYNOPSIS
  Downloads the media binaries the desktop app bundles (yt-dlp, FFmpeg, ffprobe, Deno) into media\bin
  and VERIFIES each download against the checksum published by its vendor.

.DESCRIPTION
  * yt-dlp.exe    https://github.com/yt-dlp/yt-dlp/releases      (SHA2-256SUMS from the same release)
  * ffmpeg/ffprobe https://www.gyan.dev/ffmpeg/builds/            (release "essentials" zip + published .sha256)
  * deno.exe      https://github.com/denoland/deno/releases       (.sha256sum from the same release)
                  Deno is the JavaScript runtime yt-dlp needs to solve YouTube's player challenges.

  Nothing is executed from an unverified download; a checksum mismatch aborts the script.
  Re-run with -Force to refresh. To update yt-dlp later just run:  .\fetch_media.ps1 -Force -Only yt-dlp

.PARAMETER Force   Re-download even if the binary already exists.
.PARAMETER Only    Restrict to one component: yt-dlp | ffmpeg | deno
.PARAMETER DenoVersion  Deno release to bundle (default 2.5.6).
#>
param(
    [switch]$Force,
    [ValidateSet('all', 'yt-dlp', 'ffmpeg', 'deno')][string]$Only = 'all',
    [string]$DenoVersion = '2.5.6'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$bin = Join-Path $root 'media\bin'
$tmp = Join-Path ([IO.Path]::GetTempPath()) ("ytd-fetch-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force $bin, $tmp | Out-Null

function Get-File($url, $dest) {
    Write-Host "  downloading $url"
    Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $dest
}

function Get-Text($url) {
    $content = (Invoke-WebRequest -UseBasicParsing -Uri $url).Content
    if ($content -is [byte[]]) { $content = [Text.Encoding]::ASCII.GetString($content) }
    return [string]$content
}

function Assert-Sha256($file, $expected, $label) {
    $actual = (Get-FileHash -Algorithm SHA256 -Path $file).Hash.ToLowerInvariant()
    if ($actual -ne $expected.ToLowerInvariant()) {
        throw "CHECKSUM MISMATCH for $label`n  expected $expected`n  actual   $actual"
    }
    Write-Host "  sha256 OK ($label)" -ForegroundColor Green
}

$versions = @()

try {
    # ---------------------------------------------------------------- yt-dlp
    if ($Only -in 'all', 'yt-dlp') {
        Write-Host "== yt-dlp"
        $target = Join-Path $bin 'yt-dlp.exe'
        if ($Force -or -not (Test-Path $target)) {
            $base = 'https://github.com/yt-dlp/yt-dlp/releases/latest/download'
            Get-File "$base/yt-dlp.exe" (Join-Path $tmp 'yt-dlp.exe')
            Get-File "$base/SHA2-256SUMS" (Join-Path $tmp 'SHA2-256SUMS')
            $line = Get-Content (Join-Path $tmp 'SHA2-256SUMS') | Where-Object { $_ -match '\s\*?yt-dlp\.exe$' } | Select-Object -First 1
            if (-not $line) { throw 'yt-dlp.exe not listed in SHA2-256SUMS' }
            Assert-Sha256 (Join-Path $tmp 'yt-dlp.exe') ($line -split '\s+')[0] 'yt-dlp.exe'
            Copy-Item (Join-Path $tmp 'yt-dlp.exe') $target -Force
        }
        $versions += "yt-dlp $(& $target --version)"
    }

    # ---------------------------------------------------------------- FFmpeg
    if ($Only -in 'all', 'ffmpeg') {
        Write-Host "== FFmpeg"
        if ($Force -or -not (Test-Path (Join-Path $bin 'ffmpeg.exe')) -or -not (Test-Path (Join-Path $bin 'ffprobe.exe'))) {
            $zip = Join-Path $tmp 'ffmpeg.zip'
            Get-File 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip' $zip
            $sha = (Get-Text 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip.sha256').Trim() -split '\s+' | Select-Object -First 1
            Assert-Sha256 $zip $sha 'ffmpeg-release-essentials.zip'
            Expand-Archive -Path $zip -DestinationPath (Join-Path $tmp 'ffmpeg') -Force
            foreach ($name in 'ffmpeg.exe', 'ffprobe.exe') {
                $found = Get-ChildItem -Recurse -Path (Join-Path $tmp 'ffmpeg') -Filter $name | Select-Object -First 1
                if (-not $found) { throw "$name not found in the FFmpeg archive" }
                Copy-Item $found.FullName (Join-Path $bin $name) -Force
            }
            $lic = Get-ChildItem -Recurse -Path (Join-Path $tmp 'ffmpeg') -Filter 'LICENSE*' | Select-Object -First 1
            if ($lic) { Copy-Item $lic.FullName (Join-Path $bin 'FFMPEG-LICENSE.txt') -Force }
        }
        $first = (& (Join-Path $bin 'ffmpeg.exe') -version | Select-Object -First 1)
        $versions += $first
    }

    # ---------------------------------------------------------------- Deno
    if ($Only -in 'all', 'deno') {
        Write-Host "== Deno $DenoVersion"
        $target = Join-Path $bin 'deno.exe'
        if ($Force -or -not (Test-Path $target)) {
            $base = "https://github.com/denoland/deno/releases/download/v$DenoVersion"
            $zip = Join-Path $tmp 'deno.zip'
            Get-File "$base/deno-x86_64-pc-windows-msvc.zip" $zip
            $sum = Get-Text "$base/deno-x86_64-pc-windows-msvc.zip.sha256sum"
            # Windows release: PowerShell Get-FileHash text ("Hash : <hex>"); other platforms: "<hex>  <file>"
            if ($sum -match '(?im)^\s*Hash\s*:\s*([0-9a-f]{64})') { $expectedHash = $Matches[1] } else { $expectedHash = ($sum.Trim() -split '\s+')[0] }
            Assert-Sha256 $zip $expectedHash 'deno.zip'
            Expand-Archive -Path $zip -DestinationPath (Join-Path $tmp 'deno') -Force
            Copy-Item (Join-Path $tmp 'deno\deno.exe') $target -Force
        }
        $versions += "deno $((& $target --version | Select-Object -First 1))"
    }

    $versions | Set-Content -Encoding ascii (Join-Path $bin 'VERSIONS.txt')
    Write-Host "`nBundled media binaries in $bin :" -ForegroundColor Cyan
    $versions | ForEach-Object { Write-Host "  $_" }
}
finally {
    Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
}
