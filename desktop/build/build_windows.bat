@echo off
setlocal EnableExtensions EnableDelayedExpansion
rem ===========================================================================================
rem  Repeatable Windows build:  desktop\build\build_windows.bat  [skip-fetch] [skip-tests]
rem
rem   1. create / reuse the virtual environment      desktop\.venv
rem   2. install requirements
rem   3. verify (or fetch + checksum-verify) yt-dlp.exe
rem   4. verify FFmpeg / ffprobe
rem   5. build the PyInstaller EXE                   dist\YouTubeDownloader.exe
rem   6. copy the media binaries next to it          dist\bin\
rem   7. verify the EXE (exists, starts, finds its bundled binaries - runs --self-test)
rem ===========================================================================================

set "HERE=%~dp0"
pushd "%HERE%..\.."
set "ROOT=%CD%"
set "VENV=%ROOT%\desktop\.venv"
set "PY=%VENV%\Scripts\python.exe"
set "DIST=%ROOT%\dist"
set "SKIP_FETCH=0"
set "SKIP_TESTS=0"
for %%A in (%*) do (
  if /I "%%A"=="skip-fetch" set "SKIP_FETCH=1"
  if /I "%%A"=="skip-tests" set "SKIP_TESTS=1"
)

echo.
echo [1/7] Virtual environment
if not exist "%PY%" (
  where py >nul 2>nul && (py -3 -m venv "%VENV%") || (python -m venv "%VENV%")
  if errorlevel 1 goto :fail
)

echo.
echo [2/7] Installing requirements
"%PY%" -m pip install --quiet --upgrade pip || goto :fail
"%PY%" -m pip install --quiet -r "%ROOT%\desktop\requirements-dev.txt" || goto :fail

echo.
echo [3/7] yt-dlp / [4/7] FFmpeg binaries
if "%SKIP_FETCH%"=="0" (
  powershell -NoProfile -ExecutionPolicy Bypass -File "%HERE%fetch_media.ps1" || goto :fail
)
for %%F in (yt-dlp.exe ffmpeg.exe ffprobe.exe) do (
  if not exist "%ROOT%\media\bin\%%F" (
    echo ERROR: media\bin\%%F is missing. Run desktop\build\fetch_media.ps1 or place a verified binary there.
    goto :fail
  )
)
"%ROOT%\media\bin\yt-dlp.exe" --version || goto :fail
"%ROOT%\media\bin\ffmpeg.exe" -version | findstr /B "ffmpeg version" || goto :fail
if not exist "%ROOT%\media\bin\deno.exe" echo WARNING: deno.exe missing - YouTube downloads may fail without a JavaScript runtime.

if "%SKIP_TESTS%"=="0" (
  echo.
  echo [tests] desktop test-suite
  "%PY%" -m pytest "%ROOT%\tests\desktop" -q || goto :fail
)

echo.
echo [5/7] Building the EXE with PyInstaller
"%PY%" "%HERE%make_icon.py" || goto :fail
if exist "%DIST%\YouTubeDownloader.exe" del /q "%DIST%\YouTubeDownloader.exe"
"%PY%" -m PyInstaller --noconfirm --clean ^
  --distpath "%DIST%" --workpath "%ROOT%\desktop\build\work" ^
  "%HERE%YouTubeDownloader.spec" || goto :fail

echo.
echo [6/7] Copying media binaries next to the EXE
if exist "%DIST%\bin" rmdir /s /q "%DIST%\bin"
mkdir "%DIST%\bin" || goto :fail
for %%F in (yt-dlp.exe ffmpeg.exe ffprobe.exe deno.exe FFMPEG-LICENSE.txt VERSIONS.txt) do (
  if exist "%ROOT%\media\bin\%%F" copy /y "%ROOT%\media\bin\%%F" "%DIST%\bin\" >nul
)
copy /y "%ROOT%\LICENSE" "%DIST%\LICENSE.txt" >nul
copy /y "%ROOT%\THIRD-PARTY-NOTICES.md" "%DIST%\THIRD-PARTY-NOTICES.md" >nul

echo.
echo [7/7] Verifying the EXE
if not exist "%DIST%\YouTubeDownloader.exe" (
  echo ERROR: %DIST%\YouTubeDownloader.exe was not produced.
  goto :fail
)
rem Run from an unrelated working directory to prove binaries are found relative to the EXE.
pushd "%TEMP%"
set "QT_QPA_PLATFORM=offscreen"
"%DIST%\YouTubeDownloader.exe" --self-test > "%TEMP%\ytd-selftest.json"
set "RC=%ERRORLEVEL%"
popd
type "%TEMP%\ytd-selftest.json"
if not "%RC%"=="0" (
  echo ERROR: the packaged EXE failed its self-test ^(exit code %RC%^).
  goto :fail
)

echo.
echo BUILD OK: %DIST%\YouTubeDownloader.exe
for %%F in ("%DIST%\YouTubeDownloader.exe") do echo size: %%~zF bytes
popd
exit /b 0

:fail
echo.
echo BUILD FAILED
popd
exit /b 1
