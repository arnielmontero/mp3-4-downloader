; Inno Setup script - builds installer\YouTubeDownloader-Setup.exe from dist\ (EXE + bin\).
; Build:  desktop\installer\build_installer.bat
#define AppName "YouTube Downloader"
#define AppVersion "1.1.1"
#define AppExe "YouTubeDownloader.exe"

[Setup]
AppId={{7B1E6C0E-3F52-4C55-9F8A-5D2B8A1C4E10}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=Arniel D. Montero
AppCopyright=Developed by Arniel D. Montero
DefaultDirName={autopf}\YouTubeDownloader
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
OutputDir=..\..\installer
OutputBaseFilename=YouTubeDownloader-Setup
SetupIconFile=..\assets\icon.ico
UninstallDisplayIcon={app}\{#AppExe}
LicenseFile=..\..\LICENSE
Compression=lzma2
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
WizardStyle=modern
; Upgrades: same AppId => Setup installs over the existing copy (and the uninstaller entry is updated).
; A running copy is closed first (and not restarted automatically).
CloseApplications=force
RestartApplications=no
UsePreviousAppDir=yes
UsePreviousTasks=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

[InstallDelete]
; Remove the previous version's media binaries first so an upgrade never leaves stale/mixed files behind
; (user settings live in %APPDATA%\YouTubeDownloader and are not touched).
Type: filesandordirs; Name: "{app}\bin"
Type: files; Name: "{app}\{#AppExe}"

[Files]
Source: "..\..\dist\{#AppExe}"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\..\dist\bin\*"; DestDir: "{app}\bin"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\..\dist\LICENSE.txt"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\..\dist\THIRD-PARTY-NOTICES.md"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\{#AppName}"; Filename: "{app}\{#AppExe}"
Name: "{group}\Uninstall {#AppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}"; Filename: "{app}\{#AppExe}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#AppExe}"; Description: "Launch {#AppName}"; Flags: nowait postinstall skipifsilent

[UninstallDelete]
; Only files the installer created are removed. User settings and logs in %APPDATA%\YouTubeDownloader are
; deliberately kept (see [Code]); downloaded media is never touched.
Type: filesandordirs; Name: "{app}\bin"

[Code]
var
  InstalledVersion: String;

function ReadInstalledVersion(): String;
var
  Key: String;
begin
  Result := '';
  Key := 'Software\Microsoft\Windows\CurrentVersion\Uninstall\{7B1E6C0E-3F52-4C55-9F8A-5D2B8A1C4E10}_is1';
  if not RegQueryStringValue(HKCU, Key, 'DisplayVersion', Result) then
    if not RegQueryStringValue(HKLM, Key, 'DisplayVersion', Result) then
      Result := '';
end;

function InitializeSetup(): Boolean;
begin
  Result := True;
  InstalledVersion := ReadInstalledVersion();
  // Installing an OLDER version over a newer one needs an explicit confirmation (silent installs refuse).
  if (InstalledVersion <> '') and (CompareStr(InstalledVersion, '{#AppVersion}') > 0) then
  begin
    if WizardSilent() then
      Result := False
    else
      Result := MsgBox('Version ' + InstalledVersion + ' is already installed, which is newer than this installer ({#AppVersion}).' + #13#10 +
                       'Install the older version anyway?', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES;
  end;
end;

procedure CurPageChanged(CurPageID: Integer);
begin
  if (CurPageID = wpReady) and (InstalledVersion <> '') then
    WizardForm.ReadyLabel.Caption := 'Setup will update {#AppName} from version ' + InstalledVersion + ' to {#AppVersion}.' + #13#10 +
      'Your settings are kept; the application will be closed first if it is running.' + #13#10#13#10 +
      'Click Install to continue.';
end;
procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
var
  DataDir: String;
begin
  if CurUninstallStep = usPostUninstall then
  begin
    DataDir := ExpandConstant('{userappdata}\YouTubeDownloader');
    if DirExists(DataDir) and (not UninstallSilent) then
      if MsgBox('Also delete your settings and logs?' + #13#10 + DataDir + #13#10#13#10 +
                'Your downloaded files are never deleted.', mbConfirmation, MB_YESNO or MB_DEFBUTTON2) = IDYES then
        DelTree(DataDir, True, True, True);
  end;
end;
