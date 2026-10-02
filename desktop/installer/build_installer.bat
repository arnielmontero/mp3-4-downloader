@echo off
setlocal
rem Builds installer\YouTubeDownloader-Setup.exe (requires Inno Setup 6 and a finished build_windows.bat)
set "ROOT=%~dp0..\.."
if not exist "%ROOT%\dist\YouTubeDownloader.exe" (echo Run desktop\build\build_windows.bat first & exit /b 1)
set "ISCC="
for %%P in ("%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe" "%ProgramFiles%\Inno Setup 6\ISCC.exe" "%LOCALAPPDATA%\Programs\Inno Setup 6\ISCC.exe") do if exist %%P set "ISCC=%%~P"
if "%ISCC%"=="" (echo Inno Setup 6 not found. Install: winget install JRSoftware.InnoSetup & exit /b 1)
"%ISCC%" "%~dp0YouTubeDownloader.iss" || exit /b 1
if not exist "%ROOT%\installer\YouTubeDownloader-Setup.exe" (echo installer was not produced & exit /b 1)
echo INSTALLER OK: %ROOT%\installer\YouTubeDownloader-Setup.exe
