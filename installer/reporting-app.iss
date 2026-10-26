; Inno Setup script - compile to ReportingApp-Setup.exe
; Requires Inno Setup 6: https://jrsoftware.org/isinfo.php
; Run: scripts\build-setup-exe.ps1 -BundleRuntime

#define MyAppName "Reporting App"
#define MyAppVersion "1.0.29"
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
#ifdef UpdateBuild
OutputBaseFilename=ReportingApp-Update-{#MyAppVersion}
#else
OutputBaseFilename=ReportingApp-Setup
#endif
Compression=lzma2/max
SolidCompression=yes
DiskSpanning=no
WizardStyle=modern
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
SetupLogging=yes

[Files]
; Never overwrite live SQLite files on upgrade (see database\*.sqlite entries below).
; Update copies application files only. Live .env, every SQLite file (including
; WAL/SHM), and stored SQLite backups are never replaced.
Source: "..\dist\ReportingApp-Release\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: ".env,database\*.sqlite,database\*.sqlite-wal,database\*.sqlite-shm,database\*.sqlite-journal,storage\app\sqlite-auto-backup.json,storage\app\pda-auto-sync.json,storage\app\sqlite-backups\*"
#ifexist "..\dist\ReportingApp-Release\database\reports-users.sqlite"
Source: "..\dist\ReportingApp-Release\database\reports-users.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif
#ifexist "..\dist\ReportingApp-Release\database\deliveries-local.sqlite"
Source: "..\dist\ReportingApp-Release\database\deliveries-local.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif
#ifexist "..\dist\ReportingApp-Release\database\damages-local.sqlite"
Source: "..\dist\ReportingApp-Release\database\damages-local.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif
#ifexist "..\dist\ReportingApp-Release\database\operations-tasks.sqlite"
Source: "..\dist\ReportingApp-Release\database\operations-tasks.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif
#ifexist "..\dist\ReportingApp-Release\database\accounting-local.sqlite"
Source: "..\dist\ReportingApp-Release\database\accounting-local.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif
#ifexist "..\dist\ReportingApp-Release\database\promotions-local.sqlite"
Source: "..\dist\ReportingApp-Release\database\promotions-local.sqlite"; DestDir: "{app}\database"; Flags: onlyifdoesntexist uninsneveruninstall
#endif

[Icons]
Name: "{group}\Open Reporting App"; Filename: "{code:GetAppUrl}"; IconFilename: "{sys}\shell32.dll"; IconIndex: 13
Name: "{group}\Start Reporting App"; Filename: "{app}\start-reporting-app.bat"; WorkingDir: "{app}"
Name: "{group}\Install folder"; Filename: "{app}"
Name: "{group}\Diagnose install"; Filename: "{app}\installer\diagnose.cmd"; WorkingDir: "{app}"
Name: "{group}\Repair web (IIS)"; Filename: "{app}\installer\repair-web.cmd"; WorkingDir: "{app}"
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

function ReadExistingEnvValue(const EnvPath, Key: String): String;
var
  Lines: TArrayOfString;
  I, EqPos: Integer;
  Line, LeftPart: String;
begin
  Result := '';
  if not FileExists(EnvPath) then
    Exit;
  if not LoadStringsFromFile(EnvPath, Lines) then
    Exit;
  for I := 0 to GetArrayLength(Lines) - 1 do
  begin
    Line := Trim(Lines[I]);
    if (Line = '') or (Copy(Line, 1, 1) = '#') then
      Continue;
    EqPos := Pos('=', Line);
    if EqPos < 2 then
      Continue;
    LeftPart := Trim(Copy(Line, 1, EqPos - 1));
    if LeftPart = Key then
    begin
      Result := Trim(Copy(Line, EqPos + 1, MaxInt));
      if (Length(Result) >= 2) and (Result[1] = '"') and (Result[Length(Result)] = '"') then
        Result := Copy(Result, 2, Length(Result) - 2);
      Exit;
    end;
  end;
end;

function ExtractPortFromUrl(const Url: String): String;
var
  I: Integer;
  C: String;
begin
  Result := '8090';
  for I := Length(Url) downto 1 do
  begin
    C := Copy(Url, I, 1);
    if C = ':' then
    begin
      Result := Copy(Url, I + 1, MaxInt);
      if Pos('/', Result) > 0 then
        Result := Copy(Result, 1, Pos('/', Result) - 1);
      Exit;
    end;
  end;
end;

procedure PrefillFromExistingInstall;
var
  AppDir, EnvPath, ExistingUrl: String;
  PortValue: Integer;
begin
  AppDir := WizardForm.DirEdit.Text;
  if AppDir = '' then
    Exit;
  EnvPath := AppDir + '\.env';
  if not FileExists(EnvPath) then
    Exit;

  ExistingUrl := ReadExistingEnvValue(EnvPath, 'APP_URL');
  if ExistingUrl <> '' then
  begin
    UrlPage.Values[0] := ExistingUrl;
    PortValue := StrToIntDef(ExtractPortFromUrl(ExistingUrl), 8090);
    if PortValue > 0 then
      PortPage.Values[0] := IntToStr(PortValue);
  end;
end;

procedure InitializeWizard;
begin
  SqlPage := CreateInputQueryPage(wpSelectDir,
    'SQL Server connection',
    'Read-only reporting database',
    'Enter the SQL Server credentials your DBA provided. On upgrade you may leave SQL password blank to keep the existing .env.');
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
    'This account is created on first run if no users exist yet. On upgrade you may leave the password blank to keep existing users.');
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
  if CurPageID = wpSelectDir then
    PrefillFromExistingInstall;
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
    if (Length(UrlPage.Values[0]) < 8) or
       ((Copy(LowerCase(UrlPage.Values[0]), 1, 7) <> 'http://') and
        (Copy(LowerCase(UrlPage.Values[0]), 1, 8) <> 'https://')) then
    begin
      MsgBox('Enter a full URL starting with http:// or https:// (include port if not 80/443).', mbError, MB_OK);
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

procedure StopReportingSite;
var
  ResultCode: Integer;
  AppCmd: String;
begin
  AppCmd := ExpandConstant('{sys}\inetsrv\appcmd.exe');
  if not FileExists(AppCmd) then
    Exit;
  { Stop the live site before files are copied so a request cannot write while we update. }
  Exec(AppCmd, 'stop apppool /apppool.name:ReportingApp', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
  Exec(AppCmd, 'stop site /site.name:ReportingApp', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
end;

procedure BackupSqliteBeforeUpdate;
var
  AppDir, BackupDir, Timestamp: String;
  FindRec: TFindRec;
begin
  AppDir := ExpandConstant('{app}');
  if not DirExists(AppDir + '\database') then
    Exit;

  Timestamp := GetDateTimeString('yyyyMMdd-HHmmss', #0, #0);
  BackupDir := AppDir + '\storage\app\sqlite-backups\pre-install-' + Timestamp;

  if FindFirst(AppDir + '\database\*.sqlite*', FindRec) then
  try
    repeat
      if ((FindRec.Attributes and FILE_ATTRIBUTE_DIRECTORY) = 0) and
         ((CompareText(ExtractFileExt(FindRec.Name), '.sqlite') = 0) or
          (Pos('.sqlite-', FindRec.Name) > 0)) then
      begin
        if not DirExists(BackupDir) then
          ForceDirectories(BackupDir);
        CopyFile(AppDir + '\database\' + FindRec.Name, BackupDir + '\' + FindRec.Name, False);
      end;
    until not FindNext(FindRec);
  finally
    FindClose(FindRec);
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssInstall then
  begin
    StopReportingSite;
    BackupSqliteBeforeUpdate;
    WriteInstallConfig;
  end;
end;

function UpdateReadyMemo(Space, NewLine, MemoUserInfoInfo, MemoDirInfo, MemoTypeInfo,
  MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
begin
  Result := MemoDirInfo + NewLine + NewLine +
    'SQL host: ' + SqlPage.Values[0] + NewLine +
    'Database: ' + SqlPage.Values[1] + NewLine +
    'Site URL: ' + UrlPage.Values[0] + '/login' + NewLine + NewLine +
    'Upgrade installs keep your existing .env, every SQLite database, and auto-sync settings.' + NewLine +
    'This update does not change SQL Server data and does not replace SQLite files.' + NewLine + NewLine +
    'The installer will enable IIS (if needed), install PHP + drivers, and configure the site.';
end;

[UninstallDelete]
Type: filesandordirs; Name: "{app}"
