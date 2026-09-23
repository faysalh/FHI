#Requires -Version 5.1
<#
.SYNOPSIS
    Adds missing SQLite .env keys and clears Laravel caches. Safe to run anytime (no .Count bugs).
#>
param(
    [string]$InstallPath = 'C:\Program Files\ReportingApp'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-EnvKey([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            return $Matches[2].Trim().Trim('"')
        }
    }
    return ''
}

$InstallPath = (Resolve-Path $InstallPath).Path
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'
$dbPath = ($InstallPath -replace '\\', '/').TrimEnd('/')

Write-Host 'Reporting App — patch .env SQLite keys' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

if (-not (Test-Path $envPath)) {
    Write-Host '.env not found.' -ForegroundColor Red
    exit 1
}

$required = [ordered]@{
    'APP_TIMEZONE'               = 'Asia/Baghdad'
    'ACCOUNTING_SQLITE_DATABASE' = "$dbPath/database/accounting-local.sqlite"
    'PROMOTIONS_SQLITE_DATABASE' = "$dbPath/database/promotions-local.sqlite"
    'FACE_ID_SQLITE_DATABASE'    = "$dbPath/database/face-id-local.sqlite"
    'MANUFACTURING_SQLITE_DATABASE' = "$dbPath/database/manufacturing-local.sqlite"
}

$lines = @(Get-Content $EnvPath -ErrorAction SilentlyContinue)
$added = New-Object System.Collections.Generic.List[string]
$updated = New-Object System.Collections.Generic.List[string]

foreach ($key in $required.Keys) {
    $current = Read-EnvKey -EnvPath $envPath -Key $key
    if ($key -eq 'APP_TIMEZONE') {
        if ($current -eq 'Asia/Baghdad') { continue }
        $newLines = New-Object System.Collections.Generic.List[string]
        $replaced = $false
        foreach ($line in $lines) {
            if ($line -match '^\s*APP_TIMEZONE\s*=') {
                $newLines.Add('APP_TIMEZONE=Asia/Baghdad')
                $replaced = $true
                if ($current -ne 'Asia/Baghdad') { [void]$updated.Add('APP_TIMEZONE') }
            } else {
                $newLines.Add($line)
            }
        }
        if (-not $replaced) {
            $newLines.Add('APP_TIMEZONE=Asia/Baghdad')
            [void]$added.Add('APP_TIMEZONE')
        }
        $lines = @($newLines)
        continue
    }
    if (-not [string]::IsNullOrWhiteSpace($current)) { continue }
    $lines += "$key=`"$($required[$key])`""
    [void]$added.Add($key)
}

if ($added.Count -gt 0 -or $updated.Count -gt 0) {
    Set-Content -Path $envPath -Value $lines -Encoding UTF8
    if ($added.Count -gt 0) { Write-Host ('Added: ' + ($added -join ', ')) -ForegroundColor Yellow }
    if ($updated.Count -gt 0) { Write-Host ('Updated: ' + ($updated -join ', ')) -ForegroundColor Yellow }
} else {
    Write-Host 'All .env keys already set.' -ForegroundColor Green
}

$cachedConfig = Join-Path $InstallPath 'bootstrap\cache\config.php'
if (Test-Path $cachedConfig) {
    Remove-Item $cachedConfig -Force
    Write-Host 'Removed cached config (bootstrap\cache\config.php).' -ForegroundColor Yellow
}

Push-Location $InstallPath
& $phpExe artisan config:clear
& $phpExe artisan view:clear
& $phpExe artisan config:cache
Pop-Location

Write-Host ''
Write-Host 'If Face ID times are still 3 hours behind, run installer\repair-timezone.cmd' -ForegroundColor DarkGray
Write-Host 'Done. Hard refresh browser on /reports/face-id (Ctrl+F5).' -ForegroundColor Green
