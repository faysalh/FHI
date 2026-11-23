#Requires -Version 5.1
<#
.SYNOPSIS
    Sets APP_TIMEZONE=Asia/Baghdad, clears cached config, and rebuilds Laravel config cache.
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

function Set-EnvKey([string]$EnvPath, [string]$Key, [string]$Value) {
    $lines = @(Get-Content $EnvPath -ErrorAction SilentlyContinue)
    $found = $false
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        if ($line -match "^\s*$([regex]::Escape($Key))\s*=") {
            $out.Add("$Key=$Value")
            $found = $true
        } else {
            $out.Add($line)
        }
    }
    if (-not $found) {
        $out.Add("$Key=$Value")
    }
    Set-Content -Path $EnvPath -Value $out -Encoding UTF8
}

$InstallPath = (Resolve-Path $InstallPath).Path
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'
$cachedConfig = Join-Path $InstallPath 'bootstrap\cache\config.php'

Write-Host 'Reporting App — repair timezone (Face ID attendance)' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

if (-not (Test-Path $envPath)) {
    Write-Host '.env not found.' -ForegroundColor Red
    exit 1
}

$before = Read-EnvKey -EnvPath $envPath -Key 'APP_TIMEZONE'
Set-EnvKey -EnvPath $envPath -Key 'APP_TIMEZONE' -Value 'Asia/Baghdad'
$after = Read-EnvKey -EnvPath $envPath -Key 'APP_TIMEZONE'

if ($before -ne $after) {
    Write-Host "APP_TIMEZONE: '$before' -> '$after'" -ForegroundColor Yellow
} else {
    Write-Host "APP_TIMEZONE already '$after'" -ForegroundColor Green
}

if (Test-Path $cachedConfig) {
    Remove-Item $cachedConfig -Force
    Write-Host 'Removed cached config (bootstrap\cache\config.php).' -ForegroundColor Yellow
}

Push-Location $InstallPath
& $phpExe artisan config:clear | Out-Host
& $phpExe artisan view:clear | Out-Host
& $phpExe artisan config:cache | Out-Host
$about = & $phpExe artisan about 2>&1 | Out-String
Pop-Location

if ($about -match 'Timezone\s+\.+\s+(\S+)') {
    Write-Host ''
    Write-Host ('Laravel timezone: ' + $Matches[1]) -ForegroundColor $(if ($Matches[1] -eq 'Asia/Baghdad') { 'Green' } else { 'Red' })
}

Write-Host ''
Write-Host 'Next: delete test attendance rows, punch again, and confirm time on Face ID > Logs.' -ForegroundColor Green
Write-Host 'System check on Face ID > Employees should show Asia/Baghdad and the current local time.' -ForegroundColor Green
