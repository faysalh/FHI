#Requires -Version 5.1
<#
.SYNOPSIS
    Builds release folder and compiles ReportingApp-Setup.exe with Inno Setup.

    For a single-file installer only, prefer: scripts\build-installer-exe.ps1
#>
param(
    [switch]$BundleRuntime = $true,
    [switch]$SkipNpm,
    [switch]$SkipComposer,
    [switch]$IncludeSqliteData = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root

& (Join-Path $Root 'scripts\build-release.ps1') `
    -BundleRuntime:$BundleRuntime `
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
    Write-Host 'Inno Setup not found - skipping .exe compile.' -ForegroundColor Yellow
    Write-Host 'Install Inno Setup 6, then run:'
    Write-Host '  & "C:\Program Files (x86)\Inno Setup 6\ISCC.exe" installer\reporting-app.iss'
    Write-Host ''
    Write-Host 'Or deploy dist\ReportingApp-Release and run installer\Install.cmd on the server.'
    exit 0
}

Write-Host 'Compiling ReportingApp-Setup.exe...' -ForegroundColor Cyan
& $iscc (Join-Path $Root 'installer\reporting-app.iss')
Write-Host 'Done: dist\ReportingApp-Setup.exe' -ForegroundColor Green
