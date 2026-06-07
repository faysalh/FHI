<#
.SYNOPSIS
    Opens the Reporting App on a production (IIS) install.

.DESCRIPTION
    Reads APP_URL from .env, ensures the IIS site is running, opens the login page.
    Does not require Composer, npm, or PHP on PATH - uses runtime\php if needed.
#>
param(
    [string]$InstallPath = '',
    [string]$SiteName = 'ReportingApp'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-EnvValue([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath) {
        if ($line -match '^\s*#' -or $line -match '^\s*$') { continue }
        if ($line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { continue }
        if ($Matches[1] -ne $Key) { continue }
        $value = $Matches[2].Trim()
        if ($value.StartsWith('"') -and $value.EndsWith('"')) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        return $value
    }
    return ''
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = Split-Path -Parent $ScriptDir
}
$InstallPath = (Resolve-Path $InstallPath).Path

$envPath = Join-Path $InstallPath '.env'
if (-not (Test-Path $envPath)) {
    Write-Host ''
    Write-Host 'Missing .env in the install folder.' -ForegroundColor Red
    Write-Host "Run: $InstallPath\installer\create-env.cmd"
    Write-Host ''
    exit 1
}

$appUrl = Read-EnvValue -EnvPath $envPath -Key 'APP_URL'
if ([string]::IsNullOrWhiteSpace($appUrl)) {
    $appUrl = 'http://localhost:8090'
}

$loginUrl = $appUrl.TrimEnd('/') + '/login'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'

Write-Host ''
Write-Host 'Reporting App (production)' -ForegroundColor Green
Write-Host "  Install: $InstallPath"
Write-Host "  URL:     $loginUrl"
Write-Host ''

try {
    Import-Module WebAdministration -ErrorAction Stop
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    if ($site) {
        if ($site.State -ne 'Started') {
            Start-Website -Name $SiteName | Out-Null
            Write-Host "Started IIS site: $SiteName" -ForegroundColor Cyan
        } else {
            Write-Host "IIS site already running: $SiteName" -ForegroundColor DarkGray
        }
    } else {
        Write-Host "IIS site '$SiteName' not found. Re-run the installer as Administrator." -ForegroundColor Yellow
    }
} catch {
    Write-Host "Could not check IIS (run as Administrator to start the site): $($_.Exception.Message)" -ForegroundColor Yellow
}

if (Test-Path $phpExe) {
    Push-Location $InstallPath
    & $phpExe artisan reports:db-health
    Pop-Location
} else {
    Write-Host 'Bundled PHP not found - skipping database health check.' -ForegroundColor Yellow
}

Start-Process $loginUrl
Write-Host ''
Write-Host 'Opened the login page in your browser.' -ForegroundColor Green
Write-Host 'This app runs under IIS - you do not need Composer on the server.' -ForegroundColor DarkGray
Write-Host ''
