<#
.SYNOPSIS
    Creates or replaces .env for an installed Reporting App (recovery helper).

.DESCRIPTION
    The main installer writes .env during setup. If that step failed or files were
    copied manually, run this from the app root (folder that contains artisan).

    Example:
      powershell -ExecutionPolicy Bypass -File installer\create-env.ps1
#>
param(
    [string]$InstallPath = '',
    [string]$AppUrl = '',
    [int]$SitePort = 8090,
    [string]$SqlHost = '',
    [string]$SqlDatabase = 'AsanAccounting',
    [string]$SqlUser = 'Reporting',
    [string]$SqlPassword = '',
    [string]$AdminUsername = 'admin',
    [string]$AdminPassword = '',
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Read-Required([string]$Prompt, [string]$Default = '') {
    $suffix = if ($Default -ne '') { " [$Default]" } else { '' }
    do {
        $value = Read-Host "$Prompt$suffix"
        if ($value -eq '' -and $Default -ne '') { return $Default }
    } while ([string]::IsNullOrWhiteSpace($value))
    return $value.Trim()
}

function Read-Secret([string]$Prompt) {
    $secure = Read-Host $Prompt -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringAuto($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}

function Write-EnvFile(
    [string]$Path,
    [string]$AppUrl,
    [string]$SqlHost,
    [string]$SqlDatabase,
    [string]$SqlUser,
    [string]$SqlPassword,
    [string]$InstallPath,
    [string]$AdminUsername,
    [string]$AdminPassword
) {
    $dbPath = $InstallPath -replace '\\', '/'
    $passwordEscaped = $SqlPassword -replace '"', '\"'
    $adminEscaped = $AdminPassword -replace '"', '\"'
    @"
APP_NAME="Reporting"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=$AppUrl

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=sqlsrv
DB_HOST=$SqlHost
DB_PORT=1433
DB_DATABASE=$SqlDatabase
DB_USERNAME=$SqlUser
DB_PASSWORD="$passwordEscaped"
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
DB_READONLY=true

DELIVERIES_SQLITE_DATABASE="$dbPath/database/deliveries-local.sqlite"
DAMAGES_SQLITE_DATABASE="$dbPath/database/damages-local.sqlite"
REPORTS_USERS_SQLITE_DATABASE="$dbPath/database/reports-users.sqlite"
OPERATIONS_TASKS_SQLITE_DATABASE="$dbPath/database/operations-tasks.sqlite"
ACCOUNTING_SQLITE_DATABASE="$dbPath/database/accounting-local.sqlite"
PROMOTIONS_SQLITE_DATABASE="$dbPath/database/promotions-local.sqlite"

REPORTS_BOOTSTRAP_ADMIN_USERNAME=$AdminUsername
REPORTS_BOOTSTRAP_ADMIN_PASSWORD="$adminEscaped"

REPORTING_DASHBOARD_GOVERNORATE_NAME=Erbil
REPORTING_DASHBOARD_GOVERNORATE_ID=0

SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
CACHE_STORE=file
FILESYSTEM_DISK=local
"@ | Set-Content -Path $Path -Encoding UTF8
}

function Resolve-PhpExe([string]$InstallPath) {
    $bundled = Join-Path $InstallPath 'runtime\php\php.exe'
    if (Test-Path $bundled) { return $bundled }
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) { return $cmd.Source }
    throw 'PHP not found. Run from an installed package (runtime\php\php.exe) or add PHP to PATH.'
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = Split-Path -Parent $ScriptDir
}
$InstallPath = (Resolve-Path $InstallPath).Path

if (-not (Test-Path (Join-Path $InstallPath 'artisan'))) {
    throw "Cannot find artisan in: $InstallPath"
}

$envPath = Join-Path $InstallPath '.env'
if ((Test-Path $envPath) -and -not $Force) {
    throw ".env already exists at $envPath. Re-run with -Force to replace it."
}

Write-Host ''
Write-Host 'Reporting App - create .env' -ForegroundColor Green
Write-Host '============================' -ForegroundColor Green
Write-Host "App folder: $InstallPath"
Write-Host ''

if ([string]::IsNullOrWhiteSpace($SqlHost)) {
    $SqlHost = Read-Required 'SQL Server host' '10.10.10.250'
}
if ([string]::IsNullOrWhiteSpace($SqlPassword)) {
    $SqlPassword = Read-Secret 'SQL Server password'
}
if ([string]::IsNullOrWhiteSpace($AdminPassword)) {
    $AdminPassword = Read-Secret 'Bootstrap admin password'
}
if ([string]::IsNullOrWhiteSpace($AppUrl)) {
    $AppUrl = Read-Required 'App URL (include http:// and port)' "http://localhost:$SitePort"
}

Write-Host ''
Write-Host 'Writing .env ...' -ForegroundColor Cyan
Write-EnvFile -Path $envPath -AppUrl $AppUrl -SqlHost $SqlHost `
    -SqlDatabase $SqlDatabase -SqlUser $SqlUser -SqlPassword $SqlPassword `
    -InstallPath $InstallPath -AdminUsername $AdminUsername -AdminPassword $AdminPassword

$phpExe = Resolve-PhpExe -InstallPath $InstallPath
Push-Location $InstallPath
& $phpExe artisan key:generate --force
& $phpExe artisan config:clear
& $phpExe artisan config:cache
Pop-Location

Write-Host ''
Write-Host '.env created successfully.' -ForegroundColor Green
Write-Host "  File: $envPath"
Write-Host "  URL:  $AppUrl/login"
Write-Host ''
Write-Host 'Test on the server:' -ForegroundColor Yellow
Write-Host "  & `"$phpExe`" artisan reports:db-health"
