#Requires -Version 5.1
<#
.SYNOPSIS
    Builds an upgrade installer that never ships dev SQLite data.

.DESCRIPTION
    Use this on the dev PC when updating an existing server install.
    The setup wizard / install.ps1 will:
      - Keep database\*.sqlite on the server
      - Keep .env and sqlite-backups
      - Back up SQLite files before copying app files

.EXAMPLE
    .\scripts\build-update.ps1
#>
param(
    [switch]$SkipNpm,
    [switch]$SkipComposer
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root

Write-Host 'Reporting App — UPDATE build (SQLite not bundled)' -ForegroundColor Cyan
Write-Host ''

& (Join-Path $Root 'scripts\build-release.ps1') `
    -BundleRuntime `
    -SkipZip `
    -SkipNpm:$SkipNpm `
    -SkipComposer:$SkipComposer `
    -IncludeSqliteData:$false

$iscc = @(
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe",
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $iscc) {
    Write-Host ''
    Write-Host 'Inno Setup 6 is required to build the update installer.' -ForegroundColor Red
    Write-Host 'Release folder is ready at: dist\ReportingApp-Release'
    Write-Host 'Copy that folder to the server and run installer\Install.cmd against the existing install path.'
    exit 1
}

Write-Host ''
Write-Host 'Compiling update installer...' -ForegroundColor Cyan
& $iscc (Join-Path $Root 'installer\reporting-app.iss')
if ($LASTEXITCODE -ne 0) {
    throw "Inno Setup failed with exit code $LASTEXITCODE"
}

$exePath = Join-Path $Root 'dist\ReportingApp-Setup.exe'
$sizeMb = [math]::Round((Get-Item $exePath).Length / 1MB, 1)

Write-Host ''
Write-Host 'Update installer ready:' -ForegroundColor Green
Write-Host "  $exePath ($sizeMb MB)"
Write-Host ''
Write-Host 'On the server:' -ForegroundColor Yellow
Write-Host '  1. Optional: Settings → SQLite backups → create a backup'
Write-Host '  2. Run ReportingApp-Setup.exe as Administrator'
Write-Host '  3. Choose the SAME install folder (e.g. C:\Program Files\ReportingApp)'
Write-Host '  4. Existing .env and database\*.sqlite are kept automatically'
