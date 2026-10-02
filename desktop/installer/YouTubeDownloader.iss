; Inno Setup script - builds installer\YouTubeDownloader-Setup.exe from dist\ (EXE + bin\).
; Build:  desktop\installer\build_installer.bat
#define AppName "YouTube Downloader"
#define AppVersion "1.0.0"
#define AppExe "YouTubeDownloader.exe"

[Setup]
AppId={{7B1E6C0E-3F52-4C55-9F8A-5D2B8A1C4E10}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher=YouTube Downloader
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
CloseApplications=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a &desktop shortcut"; GroupDescription: "Additional shortcuts:"; Flags: unchecked

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
