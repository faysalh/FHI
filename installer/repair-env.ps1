#Requires -Version 5.1
<#
.SYNOPSIS
    Adds missing SQLite .env keys, clears Laravel caches, and reports Face ID file/db status.
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

function Read-EnvValueLocal([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            return $Matches[2].Trim().Trim('"')
        }
    }
    return ''
}

function Ensure-EnvSqliteKeysLocal([string]$EnvPath, [string]$Root) {
    $dbPath = ($Root -replace '\\', '/').TrimEnd('/')
    $required = [ordered]@{
        'ACCOUNTING_SQLITE_DATABASE' = "$dbPath/database/accounting-local.sqlite"
        'PROMOTIONS_SQLITE_DATABASE' = "$dbPath/database/promotions-local.sqlite"
        'FACE_ID_SQLITE_DATABASE'    = "$dbPath/database/face-id-local.sqlite"
        'MANUFACTURING_SQLITE_DATABASE' = "$dbPath/database/manufacturing-local.sqlite"
    }
    $added = @()
    $lines = @(Get-Content $EnvPath -ErrorAction SilentlyContinue)
    foreach ($key in $required.Keys) {
        if (-not [string]::IsNullOrWhiteSpace((Read-EnvValueLocal -EnvPath $EnvPath -Key $key))) { continue }
        $lines += "$key=`"$($required[$key])`""
        $added += $key
    }
    if (@($added).Count -gt 0) {
        Set-Content -Path $EnvPath -Value $lines -Encoding UTF8
    }
    return @($added)
}

$InstallPath = (Resolve-Path $InstallPath).Path
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'

Write-Host 'Reporting App — repair .env + caches' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

if (-not (Test-Path $envPath)) {
    Write-Host '.env not found. Run installer\create-env.cmd first.' -ForegroundColor Red
    exit 1
}

$added = @(Ensure-EnvSqliteKeysLocal -EnvPath $envPath -Root $InstallPath)
if ($added.Count -gt 0) {
    Write-Host "Added .env keys: $($added -join ', ')" -ForegroundColor Yellow
} else {
    Write-Host 'All SQLite .env keys already present.' -ForegroundColor Green
}

$faceDb = Read-EnvValueLocal -EnvPath $envPath -Key 'FACE_ID_SQLITE_DATABASE'
if ($faceDb) {
    $exists = Test-Path $faceDb
    $size = if ($exists) { (Get-Item $faceDb).Length } else { 0 }
    Write-Host "Face ID database: $faceDb ($size bytes)"
    if ($size -lt 4096) {
        Write-Host '  Database is empty or new. Check backups:' -ForegroundColor Yellow
        Get-ChildItem (Join-Path $InstallPath 'storage\app\sqlite-backups') -Directory -ErrorAction SilentlyContinue |
            Sort-Object LastWriteTime -Descending |
            Select-Object -First 3 |
            ForEach-Object {
                $backup = Join-Path $_.FullName 'face-id-local.sqlite'
                if (Test-Path $backup) {
                    Write-Host "  Backup: $backup ($((Get-Item $backup).Length) bytes)" -ForegroundColor DarkGray
                }
            }
    }
}

$faceJs = Join-Path $InstallPath 'public\js\face-api.min.js'
$faceJsVendor = Join-Path $InstallPath 'public\js\vendor\face-api.min.js'
if (Test-Path $faceJs) {
    Write-Host "face-api.min.js: OK ($((Get-Item $faceJs).Length) bytes) at public\js\face-api.min.js" -ForegroundColor Green
} elseif (Test-Path $faceJsVendor) {
    Write-Host 'face-api.min.js found under public\js\vendor\ — IIS blocks that URL.' -ForegroundColor Yellow
    Write-Host '  Move-Item public\js\vendor\face-api.min.js public\js\face-api.min.js'
} else {
    Write-Host 'face-api.min.js missing — re-run ReportingApp-Setup upgrade.' -ForegroundColor Red
}

Push-Location $InstallPath
& $phpExe artisan config:clear
& $phpExe artisan view:clear
& $phpExe artisan config:cache
Pop-Location

Write-Host ''
Write-Host 'Done. Hard refresh the browser (Ctrl+F5) on /reports/face-id' -ForegroundColor Green
