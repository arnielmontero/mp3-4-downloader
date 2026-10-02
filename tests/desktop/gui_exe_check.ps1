<#
  UI-automation check of the PACKAGED EXE (acceptance tests 12-17 + search/sidebar/multi-download).
  Drives the real window through Windows UI Automation: start, search, several simultaneous downloads in the
  right sidebar, MP4 + MP3, cancel, settings persistence across restart.

  Usage:  powershell -File tests\desktop\gui_exe_check.ps1 [-Exe dist\YouTubeDownloader.exe]
  Needs internet access. Uses an isolated data folder (YTD_DATA_DIR) and a temporary output folder.
#>
param(
    [string]$Exe = (Join-Path $PSScriptRoot '..\..\dist\YouTubeDownloader.exe'),
    [string]$Query = 'rick astley never gonna give you up',
    [string]$LongUrl = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
    [string]$ShotDir = (Join-Path $env:TEMP 'ytd-gui-shots')
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName UIAutomationClient, UIAutomationTypes, System.Drawing, System.Windows.Forms
$Exe = (Resolve-Path $Exe).Path
$UIA = [Windows.Automation.AutomationElement]
$Desc = [Windows.Automation.TreeScope]::Descendants
$data = Join-Path $env:TEMP 'ytd-gui-data'
$out = Join-Path $env:TEMP 'ytd-gui-out'
foreach ($d in $data, $out, $ShotDir) { if (Test-Path $d) { Get-ChildItem $d -Force | Remove-Item -Recurse -Force } else { New-Item -ItemType Directory -Force $d | Out-Null } }
$env:YTD_DATA_DIR = $data
Remove-Item Env:QT_QPA_PLATFORM -ErrorAction SilentlyContinue
$results = New-Object System.Collections.ArrayList
function Check($name, $ok, $detail = '') { [void]$results.Add([pscustomobject]@{ Test = $name; Result = $(if ($ok) { 'PASS' } else { 'FAIL' }) }); Write-Host ("{0,-4} {1} {2}" -f $(if ($ok) { 'PASS' } else { 'FAIL' }), $name, $detail) }

function Start-App {
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $p = Start-Process -FilePath $Exe -PassThru
    # one-file EXE: the window belongs to the bootloader's child process, so match by title only
    $cond = New-Object Windows.Automation.PropertyCondition($UIA::NameProperty, 'YouTube Downloader')
    $win = $null
    for ($i = 0; $i -lt 80 -and -not $win; $i++) { Start-Sleep -Milliseconds 250; $win = $UIA::RootElement.FindFirst([Windows.Automation.TreeScope]::Children, $cond) }
    if (-not $win) { throw 'main window did not appear' }
    return @{ Proc = $p; Win = $win; Seconds = [math]::Round($sw.Elapsed.TotalSeconds, 1) }
}
# UI Automation trees change while we read them (items are added/removed) - retry and skip vanished elements
function All($win) {
    for ($i = 0; $i -lt 8; $i++) {
        try { return @($win.FindAll($Desc, [Windows.Automation.Condition]::TrueCondition)) } catch { Start-Sleep -Milliseconds 250 }
    }
    return @()
}
function SafeProp($e, [scriptblock]$get) { try { return (& $get $e) } catch { return $null } }
function FindAll($win, $idSuffix) { $r = @(); foreach ($e in (All $win)) { if ((SafeProp $e { param($x) $x.Current.AutomationId }) -like "*.$idSuffix") { $r += $e } }; return $r }
function Find($win, $idSuffix) { return (FindAll $win $idSuffix | Select-Object -First 1) }
function ButtonsNamed($win, $pattern) { $r = @(); foreach ($e in (All $win)) { if ((SafeProp $e { param($x) $x.Current.ControlType.ProgrammaticName }) -eq 'ControlType.Button' -and (SafeProp $e { param($x) $x.Current.Name }) -like $pattern) { $r += $e } }; return $r }
function Statuses($win) { return @(FindAll $win 'jobStatus' | ForEach-Object { SafeProp $_ { param($x) $x.Current.Name } } | Where-Object { $null -ne $_ }) }
function WaitFor([scriptblock]$cond, $timeout = 60, $what = 'condition') {
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
    [void]$app.Proc.CloseMainWindow()
    if (-not $app.Proc.WaitForExit(15000)) { $app.Proc.Kill() }
    for ($i = 0; $i -lt 40 -and (Get-Process YouTubeDownloader -ErrorAction SilentlyContinue); $i++) { Start-Sleep -Milliseconds 250 }
}
function Stray-Processes { Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.Path -and $_.Path -like (Join-Path (Split-Path $Exe) 'bin\*') } }
function Search($win, $text) {
    SetText (Find $win 'searchInput') $text
    Click (Find $win 'searchButton')
}
# choose "MP4 (video)" for the Nth result (its radio button)
function Choose-Mp4($win, $index) {
    # the result list is rebuilt after a search; retry until we hold a live radio button
    for ($i = 0; $i -lt 10; $i++) {
        try {
            $radio = (FindAll $win 'resultMp4')[$index]
            $pattern = $radio.GetCurrentPattern([Windows.Automation.SelectionItemPattern]::Pattern)
            $pattern.Select()
            Start-Sleep -Milliseconds 300
            if ($pattern.Current.IsSelected) { return }
        } catch { Start-Sleep -Milliseconds 500 }
    }
    throw 'could not select MP4'
}

@{ output_dir = $out; default_format = 'mp3'; default_quality = 'best'; default_bitrate = 192; version = 1 } | ConvertTo-Json | Set-Content -Encoding utf8 (Join-Path $data 'config.json')

# ===== T12: EXE starts; layout; saved settings loaded
$app = Start-App
$win = $app.Win
Check 'T12 EXE starts and shows its window' $true "in $($app.Seconds)s"
$searchBox = (Find $win 'searchInput').Current.BoundingRectangle
$sideX = (Find $win 'jobsTitle').Current.BoundingRectangle.X
Check 'layout: right sidebar "Downloads" is present' ($null -ne (Find $win 'jobsTitle') -and $null -ne (Find $win 'jobsEmpty'))
Check 'layout: sidebar is right of the search display' ($sideX -gt $searchBox.X + $searchBox.Width - 5) "search x=$([int]$searchBox.X) w=$([int]$searchBox.Width); sidebar x=$([int]$sideX)"
Check 'T17 saved output folder loaded' ((GetText (Find $win 'folderInput')) -eq $out)
Shot $win '01-start'

# ===== T07 / T09: invalid input
Click (Find $win 'searchButton'); Start-Sleep -Milliseconds 500
Check 'T07 empty search shows friendly message' ((Find $win 'searchError').Current.Name -eq 'Please type something to search for.') (Find $win 'searchError').Current.Name
SetText (Find $win 'searchInput') 'http://localhost/secret'; Click (Find $win 'searchButton'); Start-Sleep -Milliseconds 500
Check 'T09 unsupported domain rejected' ((Find $win 'searchError').Current.Name -ne '') (Find $win 'searchError').Current.Name

# ===== search: results with Download buttons
Search $win $Query
$buttons = WaitFor { $b = ButtonsNamed $win 'Download *'; if ($b.Count -ge 3) { $b } } 90 'search results'
Check 'T13 search shows results with a Download button each' ($buttons.Count -ge 3) "$($buttons.Count) results; first='$($buttons[0].Current.Name)'"
Shot $win '02-results'

# ===== T14: MP4 from result #2 + T15: MP3 from result #1, started back-to-back (multiple downloads)
Choose-Mp4 $win 1
Click $buttons[0]
Start-Sleep -Milliseconds 300
$buttons = ButtonsNamed $win 'Download *'
Click $buttons[1]
$two = WaitFor { $s = Statuses $win; if ($s.Count -ge 2) { $s } } 30 'two sidebar items'
Check 'pressing Download adds items to the right sidebar' ($two.Count -ge 2) ($two -join ' | ')
Start-Sleep -Seconds 2
Shot $win '03-two-downloads'
WaitFor { @(Statuses $win | Where-Object { $_ -like 'Download complete*' }).Count -ge 2 } 300 'both downloads complete' | Out-Null
$mp3 = Get-ChildItem $out -Filter *.mp3 | Select-Object -First 1
$mp4 = Get-ChildItem $out -Filter *.mp4 | Select-Object -First 1
$ffprobe = Join-Path (Split-Path $Exe) 'bin\ffprobe.exe'
$p3 = & $ffprobe -v error -show_entries format=duration:stream=codec_name,bit_rate -of compact $mp3.FullName
$p4 = & $ffprobe -v error -show_entries format=duration:stream=codec_name -of compact $mp4.FullName
Check 'T15 MP3 appears in selected folder and is valid' (($p3 -join ' ') -match 'codec_name=mp3') "$($mp3.Name) $([int]($mp3.Length/1KB)) KB; $($p3 -join ' ')"
Check 'T14 MP4 appears in selected folder and is playable' (($p4 -join ' ') -match 'h264' -and ($p4 -join ' ') -match 'aac') "$($mp4.Name) $([int]($mp4.Length/1MB)) MB"
Check 'Open File / Open Folder offered for finished items' ((@(FindAll $win 'jobOpenFile').Count -ge 2) -and (@(FindAll $win 'jobOpenFolder').Count -ge 2))
Shot $win '04-both-done'
foreach ($d in (FindAll $win 'jobDismiss')) { try { Click $d } catch {}; Start-Sleep -Milliseconds 200 }

# ===== T16: cancel one of two simultaneous downloads
Search $win $LongUrl
WaitFor { (ButtonsNamed $win 'Download *').Count -ge 1 } 90 'link result' | Out-Null
Choose-Mp4 $win 0
Click ((ButtonsNamed $win 'Download *')[0])
Search $win 'rick astley'
$b2 = WaitFor { $b = ButtonsNamed $win 'Download *'; if ($b.Count -ge 2) { $b } } 90 'second search'
Choose-Mp4 $win 1
Click $b2[1]
WaitFor { @(FindAll $win 'jobStats' | Where-Object { (SafeProp $_ { param($x) $x.Current.Name }) -match 'MiB' }).Count -ge 2 } 150 'two downloads with statistics' | Out-Null
$stats = @(FindAll $win 'jobStats' | ForEach-Object { SafeProp $_ { param($x) $x.Current.Name } }) -join ' || '
Shot $win '05-downloading'
Check 'progress, speed and ETA are shown (two at once)' ($stats -match '/s' -and $stats -match 'ETA') $stats
$cancels = @(FindAll $win 'jobCancel')
Check 'each running download has its own Cancel button' ($cancels.Count -ge 2) "$($cancels.Count) cancel buttons"
$before = @(Stray-Processes).Count
Click $cancels[0]
WaitFor { @(Statuses $win | Where-Object { $_ -eq 'Download cancelled.' }).Count -ge 1 } 60 'one cancelled' | Out-Null
Start-Sleep -Seconds 1
$still = @(Statuses $win | Where-Object { $_ -ne 'Download cancelled.' -and $_ -notlike 'Download complete*' })
Check 'T16 cancelling one leaves the other running' ($still.Count -ge 1) ($still -join ' | ')
foreach ($c in (FindAll $win 'jobCancel')) { try { Click $c } catch {} }
WaitFor { @(Statuses $win | Where-Object { $_ -eq 'Download cancelled.' }).Count -ge 2 } 60 'all cancelled' | Out-Null
Start-Sleep -Seconds 2
$stray = @(Stray-Processes)
Check 'T16 cancel stops all child processes' ($stray.Count -eq 0) "before=$before after=$($stray.Count)"
Check 'T16 temp files cleaned' (-not (Get-ChildItem (Join-Path $data 'temp') -ErrorAction SilentlyContinue))
Check 'T16 nothing half-written in output folder' (@(Get-ChildItem $out -Filter *Bunny*).Count -eq 0)
Shot $win '06-cancelled'

# ===== T17: close / reopen keeps settings
Stop-App $app
Check 'closing the window exits the process cleanly' $app.Proc.HasExited
$cfg = Get-Content (Join-Path $data 'config.json') -Raw | ConvertFrom-Json
Check 'T17 config.json still on disk' ($cfg.output_dir -eq $out) ($cfg | ConvertTo-Json -Compress)
$app2 = Start-App
Check 'T17 reopened EXE reuses saved settings' ((GetText (Find $app2.Win 'folderInput')) -eq $out) "second start in $($app2.Seconds)s"
Stop-App $app2
Check 'no stray yt-dlp/ffmpeg/deno processes left after exit' (@(Stray-Processes).Count -eq 0)

$failed = @($results | Where-Object { $_.Result -eq 'FAIL' })
Write-Host "`n$($results.Count - $failed.Count)/$($results.Count) checks passed. Screenshots: $ShotDir"
exit $(if ($failed.Count) { 1 } else { 0 })
