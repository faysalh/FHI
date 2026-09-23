#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Rebuilds runtime\php\php.ini (enables mbstring and other required extensions).

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File installer\fix-php.ps1
#>
param(
    [string]$InstallPath = ''
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

$installScript = Join-Path $ScriptDir 'install.ps1'
if (-not (Test-Path $installScript)) {
    throw "Missing $installScript"
}

Write-Host ''
Write-Host 'Reporting App - fix PHP extensions' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

& $installScript `
    -InstallPath $InstallPath `
    -SitePort 8090 `
    -AppUrl 'http://10.10.10.250:8090' `
    -SkipIis `
    -SkipOdbc `
    -SkipDbHealth `
    -Quiet

$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'
$mb = & $phpExe -m 2>&1 | Select-String -Pattern '^mbstring$'
if (-not $mb) {
    throw 'mbstring is still not loaded. Check runtime\php\ext\php_mbstring.dll exists.'
}

Write-Host 'mbstring loaded OK.' -ForegroundColor Green
Write-Host 'Restarting IIS...'
iisreset /restart | Out-Null
Write-Host 'Done. Test: Invoke-WebRequest http://10.10.10.250:8090/login -UseBasicParsing'
Write-Host ''
