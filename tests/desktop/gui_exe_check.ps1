<#
  UI-automation check of the PACKAGED EXE (acceptance tests 12-17). Drives the real window through Windows
  UI Automation: start, analyze, MP3 + MP4 download, cancel, settings persistence across restart.

  Usage:  powershell -File tests\desktop\gui_exe_check.ps1 [-Exe dist\YouTubeDownloader.exe] [-Url https://youtu.be/...]
  Needs internet access. Uses an isolated data folder (YTD_DATA_DIR) and a temporary output folder.
#>
param(
    [string]$Exe = (Join-Path $PSScriptRoot '..\..\dist\YouTubeDownloader.exe'),
    [string]$Url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    [string]$LongUrl = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
    [string]$ShotDir = (Join-Path $env:TEMP 'ytd-gui-shots')
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes, System.Drawing, System.Windows.Forms
$Exe = (Resolve-Path $Exe).Path
$UIA = [Windows.Automation.AutomationElement]
$data = Join-Path $env:TEMP 'ytd-gui-data'
$out = Join-Path $env:TEMP 'ytd-gui-out'
Remove-Item -Recurse -Force $data, $out, $ShotDir -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force $data, $out, $ShotDir | Out-Null
$env:YTD_DATA_DIR = $data
Remove-Item Env:QT_QPA_PLATFORM -ErrorAction SilentlyContinue
$results = New-Object System.Collections.ArrayList
function Check($name, $ok, $detail = '') { [void]$results.Add([pscustomobject]@{ Test = $name; Result = $(if ($ok) { 'PASS' } else { 'FAIL' }); Detail = $detail }); Write-Host ("{0,-4} {1} {2}" -f $(if ($ok) { 'PASS' } else { 'FAIL' }), $name, $detail) }

function Start-App {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process -FilePath $Exe -PassThru
    # NB: the one-file EXE is a bootloader process that spawns the real app process, so the window's
    # process id differs from $p.Id - find the window by title (there must be no other instance running).
    $cond = New-Object Windows.Automation.PropertyCondition($UIA::NameProperty, 'YouTube Downloader')
    $win = $null
    for ($i = 0; $i -lt 80 -and -not $win; $i++) { Start-Sleep -Milliseconds 250; $win = $UIA::RootElement.FindFirst([Windows.Automation.TreeScope]::Children, $cond) }
    if (-not $win) { throw 'main window did not appear' }
    return @{ Proc = $p; Win = $win; Seconds = [math]::Round($sw.Elapsed.TotalSeconds, 1) }
}
function Find($win, $idSuffix) {
    $all = $win.FindAll([Windows.Automation.TreeScope]::Descendants, [Windows.Automation.Condition]::TrueCondition)
    foreach ($e in $all) { if ($e.Current.AutomationId -like "*.$idSuffix") { return $e } }
    return $null
}
function FindByName($win, $name, $type = $null) {
    $all = $win.FindAll([Windows.Automation.TreeScope]::Descendants, [Windows.Automation.Condition]::TrueCondition)
    foreach ($e in $all) { if ($e.Current.Name -eq $name -and (-not $type -or $e.Current.ControlType.ProgrammaticName -eq $type)) { return $e } }
    return $null
}
function WaitFor($win, [scriptblock]$cond, $timeout = 60, $what = 'condition') {
    $deadline = (Get-Date).AddSeconds($timeout)
    while ((Get-Date) -lt $deadline) { $r = & $cond; if ($r) { return $r }; Start-Sleep -Milliseconds 400 }
    throw "timeout waiting for $what"
}
function Click($el) { $el.GetCurrentPattern([Windows.Automation.InvokePattern]::Pattern).Invoke() }
function SetText($el, $text) { $el.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern).SetValue($text) }
function GetText($el) { try { return $el.GetCurrentPattern([Windows.Automation.ValuePattern]::Pattern).Current.Value } catch { return $el.Current.Name } }
function Shot($win, $name) {
    $r = $win.Current.BoundingRectangle
    $bmp = New-Object Drawing.Bitmap ([int]$r.Width), ([int]$r.Height)
    $g = [Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen([int]$r.X, [int]$r.Y, 0, 0, $bmp.Size)
    $g.Dispose(); $bmp.Save((Join-Path $ShotDir "$name.png")); $bmp.Dispose()
}
function Stop-App($app) {
    # close the window the way a user does (WindowPattern.Close); never kill - a clean exit is part of the test
    try { $app.Win.GetCurrentPattern([Windows.Automation.WindowPattern]::Pattern).Close() } catch { }
    $app.Exited = $app.Proc.WaitForExit(20000)
    if (-not $app.Exited) { $app.Proc.Kill() }
    Start-Sleep -Seconds 1
}
function Stray-Processes { Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path -like (Join-Path (Split-Path $Exe) 'bin\*') } }

# ---- seed settings the way the Settings dialog would have written them (persisted from a previous run)
@{ output_dir = $out; default_format = 'mp3'; default_quality = 'best'; default_bitrate = 128; version = 1 } | ConvertTo-Json | Set-Content -Encoding utf8 (Join-Path $data 'config.json')

# ===== TEST 12: EXE starts; TEST 17 (part): saved settings are loaded
if (Get-Process YouTubeDownloader -ErrorAction SilentlyContinue) { throw 'another YouTubeDownloader instance is running - close it first' }
$app = Start-App
Check 'T12 EXE starts and shows its window' $true "in $($app.Seconds)s"
$win = $app.Win
Check 'T17 saved output folder loaded' ((GetText (Find $win 'folderInput')) -eq $out) (GetText (Find $win 'folderInput'))
Shot $win '01-start'

# ===== invalid URL (friendly message)
SetText (Find $win 'urlInput') 'definitely not a url'
Click (Find $win 'analyzeButton')
Start-Sleep -Milliseconds 800
$msg = (Find $win 'urlError').Current.Name
Check 'T07 invalid URL shows friendly message' ($msg -eq 'Please enter a valid YouTube URL.') $msg
SetText (Find $win 'urlInput') 'http://localhost/secret'
Click (Find $win 'analyzeButton'); Start-Sleep -Milliseconds 500
Check 'T09 unsupported domain rejected' ((Find $win 'urlError').Current.Name -eq 'Please enter a valid YouTube URL.')

# ===== TEST 13: analyze from EXE
SetText (Find $win 'urlInput') $Url
Click (Find $win 'analyzeButton')
$title = WaitFor $win { $t = Find $win 'titleLabel'; if ($t -and $t.Current.Name -like 'Rick Astley*') { $t.Current.Name } } 90 'analysis'
Check 'T13 analyze shows metadata' ($true) "title='$title' duration='$((Find $win 'durationLabel').Current.Name)' uploader='$((Find $win 'uploaderLabel').Current.Name)'"
Shot $win '02-analyzed'

# ===== TEST 15: MP3 (default format from persisted settings is MP3; bitrate 128)
$mp3 = Find $win 'mp3Radio'
$checked = $mp3.GetCurrentPattern([Windows.Automation.SelectionItemPattern]::Pattern).Current.IsSelected
Check 'T17 saved default format (MP3) applied' $checked
Click (Find $win 'downloadButton')
WaitFor $win { (Find $win 'statusLabel').Current.Name -like 'Download complete*' } 240 'mp3 download' | Out-Null
$mp3file = Get-ChildItem $out -Filter *.mp3 | Select-Object -First 1
Check 'T15 MP3 appears in selected folder' ($null -ne $mp3file) "$($mp3file.Name) $([int]($mp3file.Length/1KB)) KB"
$probe = & (Join-Path (Split-Path $Exe) 'bin\ffprobe.exe') -v error -show_entries format=duration:stream=codec_name,bit_rate -of compact $mp3file.FullName
Check 'T15 MP3 is valid audio' (($probe -join ' ') -match 'codec_name=mp3') ($probe -join ' ')
Shot $win '03-mp3-done'

# ===== TEST 14: MP4
Click (FindByName $win 'MP4 (video)' 'ControlType.RadioButton')
Start-Sleep -Milliseconds 300
Click (Find $win 'downloadButton')
WaitFor $win { (Find $win 'statusLabel').Current.Name -like 'Download complete*' -and (Get-ChildItem $out -Filter *.mp4 -ErrorAction SilentlyContinue) } 300 'mp4 download' | Out-Null
$mp4file = Get-ChildItem $out -Filter *.mp4 | Select-Object -First 1
$probe = & (Join-Path (Split-Path $Exe) 'bin\ffprobe.exe') -v error -show_entries format=duration:stream=codec_name -of compact $mp4file.FullName
Check 'T14 MP4 appears in selected folder and is playable' (($probe -join ' ') -match 'h264' -and ($probe -join ' ') -match 'aac') "$($mp4file.Name) $([int]($mp4file.Length/1MB)) MB; $($probe -join ' ')"
Shot $win '04-mp4-done'
Check 'Open File / Open Output Folder buttons offered' ((Find $win 'openFileButton') -and (Find $win 'openFolderButton')) -ne $null

# ===== TEST 16: cancel
SetText (Find $win 'urlInput') $LongUrl
Click (Find $win 'analyzeButton')
WaitFor $win { $t = Find $win 'titleLabel'; $t -and $t.Current.Name -like '*Big Buck Bunny*' } 90 'long video analysis' | Out-Null
Click (FindByName $win 'MP4 (video)' 'ControlType.RadioButton')
Click (Find $win 'downloadButton')
WaitFor $win { $s = (Find $win 'statsLabel'); $s -and $s.Current.Name -match 'MiB' } 120 'progress statistics' | Out-Null
$stats = (Find $win 'statsLabel').Current.Name
Shot $win '05-downloading'
Check 'progress, speed and ETA are shown' ($stats -match '/s' -and $stats -match 'ETA') $stats
$strayBefore = @(Stray-Processes).Count
Click (Find $win 'cancelButton')
WaitFor $win { (Find $win 'statusLabel').Current.Name -eq 'Download cancelled.' } 60 'cancellation' | Out-Null
Start-Sleep -Seconds 2
$stray = @(Stray-Processes)
Check 'T16 cancel stops the download and all child processes' ($stray.Count -eq 0) "before=$strayBefore after=$($stray.Count)"
$leftover = Get-ChildItem (Join-Path $data 'temp') -ErrorAction SilentlyContinue
Check 'T16 temp files cleaned' (-not $leftover) "temp entries: $(@($leftover).Count)"
Check 'T16 nothing half-written in output folder' (@(Get-ChildItem $out -Filter *Bunny*).Count -eq 0)
Shot $win '06-cancelled'

# ===== TEST 17: close / reopen keeps settings (change via the real Settings dialog is covered by pytest; here the
#               persisted file must survive a full restart and be reused)
Stop-App $app
Check 'closing the window exits the process cleanly (no kill needed)' $app.Exited
$cfg = Get-Content (Join-Path $data 'config.json') -Raw | ConvertFrom-Json
Check 'T17 config.json still on disk' ($cfg.output_dir -eq $out -and $cfg.default_format -eq 'mp3') ($cfg | ConvertTo-Json -Compress)
$app2 = Start-App
# the format radios are hidden until a video is analysed, so verify through the output folder and an analysis
SetText (Find $app2.Win 'urlInput') $Url
Click (Find $app2.Win 'analyzeButton')
WaitFor $app2.Win { $t = Find $app2.Win 'titleLabel'; $t -and $t.Current.Name -like 'Rick Astley*' } 90 'analysis after restart' | Out-Null
$sel = (Find $app2.Win 'mp3Radio').GetCurrentPattern([Windows.Automation.SelectionItemPattern]::Pattern).Current.IsSelected
Check 'T17 reopened EXE reuses saved settings' (((GetText (Find $app2.Win 'folderInput')) -eq $out) -and $sel) "second start in $($app2.Seconds)s; folder + MP3 default restored"
Stop-App $app2
Check 'no stray yt-dlp/ffmpeg/deno processes left after exit' (@(Stray-Processes).Count -eq 0)

$failed = @($results | Where-Object { $_.Result -eq 'FAIL' })
Write-Host "`n$($results.Count - $failed.Count)/$($results.Count) checks passed. Screenshots: $ShotDir"
exit $(if ($failed.Count) { 1 } else { 0 })
