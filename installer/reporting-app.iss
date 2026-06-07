; Inno Setup script - compile to ReportingApp-Setup.exe
; Requires Inno Setup 6: https://jrsoftware.org/isinfo.php
; Run: scripts\build-setup-exe.ps1 -BundleRuntime

#define MyAppName "Reporting App"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "Reporting"
#define MyAppURL "http://localhost"

[Setup]
AppId={{A7B3C9D1-4E2F-4A8B-9C1D-2E3F4A5B6C7D}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\ReportingApp
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=ReportingApp-Setup
Compression=lzma2/max
SolidCompression=yes
DiskSpanning=no
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
SetupLogging=yes

[Files]
Source: "..\dist\ReportingApp-Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{group}\Open Reporting App"; Filename: "{code:GetAppUrl}"; IconFilename: "{sys}\shell32.dll"; IconIndex: 13
Name: "{group}\Start Reporting App"; Filename: "{app}\start-reporting-app.bat"; WorkingDir: "{app}"
Name: "{group}\Install folder"; Filename: "{app}"
Name: "{group}\Diagnose install"; Filename: "{app}\installer\diagnose.cmd"; WorkingDir: "{app}"
Name: "{group}\Create .env (recovery)"; Filename: "{app}\installer\create-env.cmd"; WorkingDir: "{app}"

[Run]
Filename: "powershell.exe"; Parameters: "{code:GetInstallParams}"; StatusMsg: "Configuring IIS, PHP, and database (watch the console for errors)..."; Flags: waituntilterminated

[Code]
var
  SqlPage: TInputQueryWizardPage;
  AdminPage: TInputQueryWizardPage;
  PortPage: TInputQueryWizardPage;
  UrlPage: TInputQueryWizardPage;
  InstallConfigPath: String;

function EscapeConfigValue(const S: String): String;
var
  I: Integer;
  C: String;
begin
  Result := '';
  for I := 1 to Length(S) do
  begin
    C := Copy(S, I, 1);
    if (C = #13) or (C = #10) then
      Continue;
    Result := Result + C;
  end;
end;

function GetAppUrl(Param: String): String;
begin
  if UrlPage.Values[0] <> '' then
    Result := UrlPage.Values[0] + '/login'
  else
    Result := 'http://localhost:' + PortPage.Values[0] + '/login';
end;

procedure InitializeWizard;
begin
  SqlPage := CreateInputQueryPage(wpSelectDir,
    'SQL Server connection',
    'Read-only reporting database',
    'Enter the SQL Server credentials your DBA provided. The app only runs SELECT queries.');
  SqlPage.Add('SQL Server host (IP or name):', False);
  SqlPage.Add('Database name:', False);
  SqlPage.Add('SQL username:', False);
  SqlPage.Add('SQL password:', True);
  SqlPage.Values[0] := '10.10.10.250';
  SqlPage.Values[1] := 'AsanAccounting';
  SqlPage.Values[2] := 'Reporting';

  AdminPage := CreateInputQueryPage(SqlPage.ID,
    'Admin login',
    'First sign-in account',
    'This account is created on first run if no users exist yet.');
  AdminPage.Add('Admin username:', False);
  AdminPage.Add('Admin password:', True);
  AdminPage.Values[0] := 'admin';

  PortPage := CreateInputQueryPage(AdminPage.ID,
    'Web site port',
    'IIS binding',
    'The installer creates an IIS site named ReportingApp on this port.');
  PortPage.Add('TCP port:', False);
  PortPage.Values[0] := '8090';

  UrlPage := CreateInputQueryPage(PortPage.ID,
    'Browser URL',
    'Address users type in the browser',
    'Use localhost if only this server opens the app. Use the server IP (e.g. http://10.10.10.250:8090) if other PCs on the network need access.');
  UrlPage.Add('Full URL (http://...):', False);
  UrlPage.Values[0] := 'http://localhost:8090';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  PortValue: Integer;
begin
  Result := True;
  if CurPageID = PortPage.ID then
  begin
    PortValue := StrToIntDef(PortPage.Values[0], 0);
    if (PortValue < 1) or (PortValue > 65535) then
    begin
      MsgBox('Enter a valid port number (1-65535).', mbError, MB_OK);
      Result := False;
    end
    else
      UrlPage.Values[0] := 'http://localhost:' + PortPage.Values[0];
  end;
  if CurPageID = UrlPage.ID then
  begin
    if (Length(UrlPage.Values[0]) < 8) or (Copy(LowerCase(UrlPage.Values[0]), 1, 7) <> 'http://') then
    begin
      MsgBox('Enter a full URL starting with http:// (include port if not 80).', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

function WriteInstallConfig: String;
var
  Lines: TArrayOfString;
begin
  Result := ExpandConstant('{tmp}\reporting-app-install.cfg');
  SetArrayLength(Lines, 9);
  Lines[0] := 'InstallPath=' + EscapeConfigValue(ExpandConstant('{app}'));
  Lines[1] := 'SitePort=' + EscapeConfigValue(PortPage.Values[0]);
  Lines[2] := 'SqlHost=' + EscapeConfigValue(SqlPage.Values[0]);
  Lines[3] := 'SqlDatabase=' + EscapeConfigValue(SqlPage.Values[1]);
  Lines[4] := 'SqlUser=' + EscapeConfigValue(SqlPage.Values[2]);
  Lines[5] := 'SqlPassword=' + EscapeConfigValue(SqlPage.Values[3]);
  Lines[6] := 'AdminUsername=' + EscapeConfigValue(AdminPage.Values[0]);
  Lines[7] := 'AdminPassword=' + EscapeConfigValue(AdminPage.Values[1]);
  Lines[8] := 'AppUrl=' + EscapeConfigValue(UrlPage.Values[0]);
  if SaveStringsToFile(Result, Lines, False) then
    InstallConfigPath := Result
  else
    RaiseException('Could not write installer configuration file.');
end;

function GetInstallParams(Param: String): String;
begin
  if InstallConfigPath = '' then
    WriteInstallConfig;
  Result := '-NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{app}\installer\install.ps1') + '" ' +
    '-ConfigFile "' + InstallConfigPath + '" -Pause';
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssInstall then
    WriteInstallConfig;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo,
  MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine +
    'SQL host: ' + SqlPage.Values[0] + NewLine +
    'Database: ' + SqlPage.Values[1] + NewLine +
    'Site URL: ' + UrlPage.Values[0] + '/login' + NewLine + NewLine +
    'Bundled SQLite data (users, deliveries, damages, tasks) is installed with the app.' + NewLine + NewLine +
    'The installer will enable IIS (if needed), install PHP + drivers, and configure the site.';
end;

[UninstallDelete]
Type: filesandordirs; Name: "{app}"
