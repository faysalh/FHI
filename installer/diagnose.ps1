<#
.SYNOPSIS
    Checks a production Reporting App install and prints what is wrong.
#>
param(
    [string]$InstallPath = '',
    [string]$SiteName = 'ReportingApp',
    [int]$ExpectedPort = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Continue'

function Test-Check([string]$Label, [bool]$Ok, [string]$Detail, [string]$Fix = '') {
    $status = if ($Ok) { 'OK' } else { 'FAIL' }
    $color = if ($Ok) { 'Green' } else { 'Red' }
    Write-Host ("[{0}] {1}" -f $status, $Label) -ForegroundColor $color
    if ($Detail) { Write-Host "      $Detail" -ForegroundColor DarkGray }
    if (-not $Ok -and $Fix) { Write-Host "      Fix: $Fix" -ForegroundColor Yellow }
    return $Ok
}

function Read-EnvValue([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            $value = $Matches[2].Trim().Trim('"')
            return $value
        }
    }
    return ''
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = Split-Path -Parent $ScriptDir
}
if (-not (Test-Path (Join-Path $InstallPath 'artisan'))) {
    Write-Host "Not a Reporting App folder (no artisan): $InstallPath" -ForegroundColor Red
    exit 1
}
$InstallPath = (Resolve-Path $InstallPath).Path

Write-Host ''
Write-Host 'Reporting App - install diagnostic' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

$failures = 0
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'

if (-not (Test-Check 'Application files' $true "artisan found")) { $failures++ }

if (-not (Test-Path $envPath)) {
    if (-not (Test-Check '.env file' $false 'Missing - Laravel cannot run' "Run installer\create-env.cmd, then re-run installer\install.ps1 as Administrator")) { $failures++ }
    $appUrl = ''
    $appKey = ''
} else {
    $appUrl = Read-EnvValue $envPath 'APP_URL'
    $appKey = Read-EnvValue $envPath 'APP_KEY'
    if (-not (Test-Check '.env file' $true $envPath)) { $failures++ }
    if (-not (Test-Check 'APP_KEY in .env' ([string]::IsNullOrWhiteSpace($appKey) -eq $false) $(if ($appKey) { 'Set' } else { 'Empty' }) "Run: runtime\php\php.exe artisan key:generate --force")) { $failures++ }
    if (-not (Test-Check 'APP_URL in .env' ([string]::IsNullOrWhiteSpace($appUrl) -eq $false) $appUrl "Set APP_URL to http://YOUR_SERVER_IP:PORT")) { $failures++ }
}

if (-not (Test-Path $phpExe)) {
    if (-not (Test-Check 'Bundled PHP' $false 'runtime\php\php.exe missing' 'Re-run ReportingApp-Setup.exe as Administrator')) { $failures++ }
} else {
    if (-not (Test-Check 'Bundled PHP' $true $phpExe)) { $failures++ }
    Push-Location $InstallPath
    $health = & $phpExe artisan reports:db-health 2>&1 | Out-String
    Pop-Location
    $dbOk = ($LASTEXITCODE -eq 0) -and ($health -notmatch 'FAIL|Error|Exception')
    $healthDetail = ($health.Trim() -replace '\s+', ' ')
    if (-not (Test-Check 'SQL Server connection' $dbOk $healthDetail 'Check DB_PASSWORD in .env and SQL login on the server')) { $failures++ }
}

$port = $ExpectedPort
if ($port -le 0 -and $appUrl -match ':(\d+)') {
    $port = [int]$Matches[1]
}
if ($port -le 0) { $port = 8090 }

$iisOk = $false
$siteState = 'unknown'
try {
    Import-Module WebAdministration -ErrorAction Stop
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    if ($site) {
        $siteState = [string]$site.State
        $bindings = Get-WebBinding -Name $SiteName | ForEach-Object { "$($_.protocol)://$($_.bindingInformation)" }
        $iisOk = ($site.State -eq 'Started')
        if (-not (Test-Check "IIS site '$SiteName'" $true "State: $siteState | Bindings: $($bindings -join ', ')")) { }
        if (-not (Test-Check 'IIS site running' $iisOk $siteState "Run as Admin: Start-Website -Name $SiteName")) { $failures++ }

        $phys = $site.physicalPath
        $expectedPublic = Join-Path $InstallPath 'public'
        $pathOk = ($phys -eq $expectedPublic)
        if (-not (Test-Check 'IIS physical path' $pathOk "IIS: $phys" "Should be: $expectedPublic. Re-run installer\install.ps1 as Administrator")) { $failures++ }
    } else {
        if (-not (Test-Check "IIS site '$SiteName'" $false 'Site not found' 'Re-run installer\install.ps1 as Administrator')) { $failures++ }
    }

    $rewrite = Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue
    if (-not (Test-Check 'IIS URL Rewrite module' ($null -ne $rewrite) $(if ($rewrite) { 'Installed' } else { 'Missing - /login will 404' }) 'Re-run installer as Administrator (bundled rewrite MSI)')) { $failures++ }
} catch {
    if (-not (Test-Check 'IIS check' $false $_.Exception.Message 'Install IIS and re-run installer\install.ps1 as Administrator')) { $failures++ }
}

$listening = netstat -an | Select-String "LISTENING" | Select-String ":$port\s"
if (-not (Test-Check "Port $port listening" ($null -ne $listening) $(if ($listening) { $listening[0].Line.Trim() } else { 'Nothing listening' }) "Re-run installer\install.ps1 as Administrator; check port conflict")) { $failures++ }

if ($appUrl) {
    try {
        $probeUrl = $appUrl.TrimEnd('/') + '/login'
        $resp = Invoke-WebRequest -Uri $probeUrl -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        $httpOk = ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400)
        if (-not (Test-Check 'HTTP /login on this server' $httpOk "Status $($resp.StatusCode) for $probeUrl")) { $failures++ }
    } catch {
        if (-not (Test-Check 'HTTP /login on this server' $false $_.Exception.Message "Check IIS, URL Rewrite, and storage\logs\laravel.log")) { $failures++ }
    }
}

$logPath = Join-Path $InstallPath 'storage\logs\laravel.log'
if (Test-Path $logPath) {
    $tail = Get-Content $logPath -Tail 8 -ErrorAction SilentlyContinue
    if ($tail) {
        Write-Host ''
        Write-Host 'Recent laravel.log:' -ForegroundColor DarkGray
        $tail | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkGray }
    }
}

$installLog = Get-ChildItem (Join-Path $InstallPath 'storage\logs') -Filter 'install-*.log' -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($installLog) {
    Write-Host ''
    Write-Host "Last install log: $($installLog.FullName)" -ForegroundColor DarkGray
}

Write-Host ''
if ($failures -eq 0) {
    Write-Host 'All checks passed. Open in browser:' -ForegroundColor Green
    Write-Host "  $($appUrl.TrimEnd('/'))/login"
} else {
    Write-Host "$failures check(s) failed." -ForegroundColor Red
    Write-Host ''
    Write-Host 'Most installs are fixed by re-running the server setup (Administrator PowerShell):' -ForegroundColor Yellow
    Write-Host "  cd `"$InstallPath`""
    Write-Host "  powershell -ExecutionPolicy Bypass -File installer\install.ps1 -InstallPath `"$InstallPath`" -SitePort $port -AppUrl `"$appUrl`""
    Write-Host ''
    Write-Host 'Do NOT use start-reporting-app or run-dev on the server - those are for development PCs with Composer/npm.' -ForegroundColor Yellow
}
Write-Host ''
exit $(if ($failures -eq 0) { 0 } else { 1 })
