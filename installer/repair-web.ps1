#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Repairs IIS + Laravel routing after an upgrade without re-entering SQL credentials.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File installer\repair-web.ps1
#>
param(
    [string]$InstallPath = '',
    [string]$SiteName = 'ReportingApp',
    [string]$AppPoolName = 'ReportingApp',
    [int]$SitePort = 0,
    [string]$AppUrl = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = Split-Path -Parent $ScriptDir
}
if (-not (Test-Path (Join-Path $InstallPath 'artisan'))) {
    throw "Not a Reporting App folder: $InstallPath"
}
$InstallPath = (Resolve-Path $InstallPath).Path

$envPath = Join-Path $InstallPath '.env'
if (-not (Test-Path $envPath)) {
    throw ".env missing at $envPath - run installer\create-env.cmd first."
}

function Read-EnvValue([string]$Key) {
    foreach ($line in Get-Content $envPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            return $Matches[2].Trim().Trim('"')
        }
    }
    return ''
}

if ($SitePort -le 0) {
    $AppUrlFromEnv = Read-EnvValue 'APP_URL'
    if ($AppUrlFromEnv -match ':(\d+)') {
        $SitePort = [int]$Matches[1]
    }
}
if ($SitePort -le 0) { $SitePort = 8090 }
if ([string]::IsNullOrWhiteSpace($AppUrl)) {
    $AppUrl = Read-EnvValue 'APP_URL'
}
if ([string]::IsNullOrWhiteSpace($AppUrl)) {
    $AppUrl = "http://localhost:$SitePort"
}

Write-Host ''
Write-Host 'Reporting App - repair web (IIS + Laravel caches)' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host "URL:    $AppUrl"
Write-Host ''

$installScript = Join-Path $ScriptDir 'install.ps1'
if (-not (Test-Path $installScript)) {
    throw "Missing $installScript"
}

& $installScript `
    -InstallPath $InstallPath `
    -SitePort $SitePort `
    -SiteName $SiteName `
    -AppPoolName $AppPoolName `
    -AppUrl $AppUrl `
    -SkipDbHealth `
    -Quiet

Write-Host ''
Write-Host 'Repair finished. Open:' -ForegroundColor Green
Write-Host "  $($AppUrl.TrimEnd('/'))/login"
Write-Host ''
