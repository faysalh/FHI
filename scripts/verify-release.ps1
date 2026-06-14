#Requires -Version 5.1
<#
.SYNOPSIS
    Validates dist\ReportingApp-Release before packaging the installer.
#>
param(
    [string]$ReleaseRoot = '',
    [switch]$BundleRuntime
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root
if ([string]::IsNullOrWhiteSpace($ReleaseRoot)) {
    $ReleaseRoot = Join-Path $Root 'dist\ReportingApp-Release'
}

if (-not (Test-Path $ReleaseRoot)) {
    throw "Release folder not found: $ReleaseRoot"
}

$errors = @()
$warnings = @()

function Require-File([string]$RelativePath, [string]$Label = '') {
    $full = Join-Path $ReleaseRoot $RelativePath
    if (-not (Test-Path $full)) {
        $msg = if ($Label) { "$Label ($RelativePath)" } else { $RelativePath }
        $script:errors += "Missing: $msg"
        return $false
    }
    return $true
}

Write-Host "Verifying release: $ReleaseRoot" -ForegroundColor Cyan

# Laravel core
@(
    'artisan',
    'composer.json',
    'vendor\autoload.php',
    'bootstrap\app.php',
    'public\index.php',
    'public\web.config',
    'public\build\manifest.json',
    'routes\web.php',
    'config\database.php',
    'config\reporting.php'
) | ForEach-Object { [void](Require-File $_) }

# Installer
@(
    'installer\install.ps1',
    'installer\Install.cmd',
    'installer\create-env.ps1',
    'installer\create-env.cmd',
    'installer\start-reporting-app.ps1',
    'installer\reporting-app.iss',
    'installer\.env.production.example',
    'installer\BUNDLED-DATA.md'
) | ForEach-Object { [void](Require-File $_) }

[void](Require-File '.env.example' 'Environment example')

if (-not (Test-Path (Join-Path $ReleaseRoot '.env'))) {
    Write-Host '  OK: .env not bundled (created at install time)' -ForegroundColor DarkGray
} else {
    $warnings += '.env should not be in the release package'
}

# SQLite bundle
$sqliteExpected = @(
    'reports-users.sqlite',
    'deliveries-local.sqlite',
    'damages-local.sqlite',
    'operations-tasks.sqlite'
)
foreach ($name in $sqliteExpected) {
    $rel = "database\$name"
    if (Require-File $rel "SQLite: $name") {
        $len = (Get-Item (Join-Path $ReleaseRoot $rel)).Length
        if ($len -lt 1024) {
            $warnings += "$name is very small ($len bytes)"
        }
    }
}

$testDbs = Get-ChildItem (Join-Path $ReleaseRoot 'database') -Filter 'damages-test-*.sqlite' -ErrorAction SilentlyContinue
if ($testDbs) {
    $warnings += "Test SQLite files should be removed: $($testDbs.Count) damages-test-*.sqlite"
}

if (Test-Path (Join-Path $ReleaseRoot 'database\database.sqlite')) {
    $warnings += 'database\database.sqlite should not be in release'
}

# Runtime bundle
if ($BundleRuntime) {
    @(
        'installer\assets\php\php.exe',
        'installer\assets\php\php-cgi.exe',
        'installer\assets\msodbcsql18.msi',
        'installer\assets\rewrite_amd64.msi',
        'installer\assets\vc_redist.x64.exe'
    ) | ForEach-Object { [void](Require-File $_ 'Runtime bundle') }

    $extDir = Join-Path $ReleaseRoot 'installer\assets\php\ext'
    if (Test-Path $extDir) {
        $hasSqlsrv = Get-ChildItem $extDir -Filter 'php_sqlsrv*.dll' -ErrorAction SilentlyContinue
        $hasPdo = Get-ChildItem $extDir -Filter 'php_pdo_sqlsrv*.dll' -ErrorAction SilentlyContinue
        if (-not $hasSqlsrv) { $errors += 'Missing: php_sqlsrv driver DLL in installer\assets\php\ext' }
        if (-not $hasPdo) { $errors += 'Missing: php_pdo_sqlsrv driver DLL in installer\assets\php\ext' }
    }
}

# Identifier glossary route (missing import caused HTTP 500 on /reports/identifier)
@(
    'app\Http\Controllers\IdentifierController.php',
    'app\Repositories\IdentifierRepository.php',
    'resources\views\reports\identifier\index.blade.php'
) | ForEach-Object { [void](Require-File $_ 'Identifier report') }

$webRoutes = Join-Path $ReleaseRoot 'routes\web.php'
if (Test-Path $webRoutes) {
    $webRoutesText = Get-Content $webRoutes -Raw
    if ($webRoutesText -notmatch 'use App\\Http\\Controllers\\IdentifierController;') {
        $errors += 'routes\web.php must import App\Http\Controllers\IdentifierController'
    }
    if ($webRoutesText -notmatch 'reports\.identifier\.index') {
        $errors += 'routes\web.php must register reports.identifier.index'
    }
}

# Fonts for PDF
@(
    'public\fonts\NotoNaskhArabic-Regular.ttf',
    'public\fonts\NotoNaskhArabic-Bold.ttf'
) | ForEach-Object { [void](Require-File $_ 'PDF font') }

if ($warnings.Count -gt 0) {
    Write-Host ''
    Write-Host 'Warnings:' -ForegroundColor Yellow
    foreach ($w in $warnings) { Write-Host "  - $w" -ForegroundColor Yellow }
}

if ($errors.Count -gt 0) {
    Write-Host ''
    Write-Host 'Release verification FAILED:' -ForegroundColor Red
    foreach ($e in $errors) { Write-Host "  - $e" -ForegroundColor Red }
    throw "Release has $($errors.Count) missing item(s)."
}

Write-Host ''
Write-Host 'Release verification passed.' -ForegroundColor Green
