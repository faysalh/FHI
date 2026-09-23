# Registers a Windows scheduled task that runs Laravel's scheduler every minute.
# Required for daily SQLite auto backup and automatic PDA sync (and any future scheduled jobs).
#
# Run as Administrator from PowerShell:
#   powershell -ExecutionPolicy Bypass -File "C:\Program Files\ReportingApp\scripts\register-reporting-scheduler.ps1"
#
# Or from an elevated PowerShell prompt already open:
#   & "C:\Program Files\ReportingApp\scripts\register-reporting-scheduler.ps1"

param(
    [string]$AppDir = ''
)

$ErrorActionPreference = 'Stop'

if ($AppDir -eq '') {
    $AppDir = Split-Path -Parent $PSScriptRoot
    if (-not (Test-Path (Join-Path $AppDir 'artisan'))) {
        $AppDir = 'C:\Program Files\ReportingApp'
    }
}

$php = Join-Path $AppDir 'runtime\php\php.exe'
$artisan = Join-Path $AppDir 'artisan'

if (-not (Test-Path $php)) {
    Write-Error "PHP not found at $php. Pass -AppDir with your Reporting App install path."
}

if (-not (Test-Path $artisan)) {
    Write-Error "artisan not found at $artisan."
}

$taskName = 'ReportingApp Laravel Scheduler'
$action = New-ScheduledTaskAction -Execute $php -Argument 'artisan schedule:run' -WorkingDirectory $AppDir
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

# Windows rejects [TimeSpan]::MaxValue as RepetitionDuration. Use a long valid window
# and disable "stop at duration end" so the task keeps running every minute.
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
if ($null -ne $trigger.Repetition) {
    $trigger.Repetition.StopAtDurationEnd = $false
}

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description 'Runs php artisan schedule:run every minute for Reporting App (SQLite auto backup, etc.)' `
    -Force | Out-Null

Write-Host "Registered scheduled task: $taskName"
Write-Host "Runs every minute as SYSTEM: $php artisan schedule:run"
Write-Host "Working directory: $AppDir"
Write-Host ''
Write-Host 'Tip: choose a backup folder the SYSTEM account can write to (for example D:\Backups\ReportingApp).'
