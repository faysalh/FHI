#Requires -Version 5.1
<#
.SYNOPSIS
    Builds a single self-contained setup.exe (ReportingApp-Setup.exe).

.DESCRIPTION
    Produces ONE file to copy to the server: dist\ReportingApp-Setup.exe
    Everything is embedded: Laravel app, PHP, SQL drivers, ODBC/URL Rewrite MSIs,
    and your local SQLite databases (users, deliveries, damages, tasks).

.EXAMPLE
    .\scripts\build-installer-exe.ps1
    .\scripts\build-installer-exe.ps1 -SkipNpm
#>
param(
    [switch]$SkipNpm,
    [switch]$SkipComposer,
    [switch]$IncludeSqliteData = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root

& (Join-Path $Root 'scripts\build-release.ps1') `
    -BundleRuntime `
    -SkipZip `
    -SkipNpm:$SkipNpm `
    -SkipComposer:$SkipComposer `
    -IncludeSqliteData:$IncludeSqliteData

$iscc = @(
    "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe",
    "$env:ProgramFiles\Inno Setup 6\ISCC.exe",
    "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $iscc) {
    Write-Host ''
    Write-Host 'Inno Setup 6 is required to build the single-file installer.' -ForegroundColor Red
    Write-Host 'Download: https://jrsoftware.org/isdl.php' -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'After installing Inno Setup, run this script again.'
    Write-Host 'Release folder is ready at: dist\ReportingApp-Release'
    exit 1
}

Write-Host ''
Write-Host 'Compiling single-file installer...' -ForegroundColor Cyan
& $iscc (Join-Path $Root 'installer\reporting-app.iss')
if ($LASTEXITCODE -ne 0) {
    throw "Inno Setup failed with exit code $LASTEXITCODE"
}

$exePath = Join-Path $Root 'dist\ReportingApp-Setup.exe'
if (-not (Test-Path $exePath)) {
    throw "Expected installer not found: $exePath"
}

$sizeMb = [math]::Round((Get-Item $exePath).Length / 1MB, 1)
Write-Host ''
Write-Host 'Single-file installer ready:' -ForegroundColor Green
Write-Host "  $exePath ($sizeMb MB)"
Write-Host ''
Write-Host 'Copy ONLY this file to the target server, run as Administrator.' -ForegroundColor Yellow
