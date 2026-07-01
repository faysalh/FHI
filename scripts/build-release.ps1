#Requires -Version 5.1
<#
.SYNOPSIS
    Builds a self-contained release folder ready for the Windows installer.

.EXAMPLE
    .\scripts\build-release.ps1
    .\scripts\build-release.ps1 -BundleRuntime
#>
param(
    [string]$OutputDir = '',
    [switch]$BundleRuntime,
    [switch]$SkipNpm,
    [switch]$SkipComposer,
    [switch]$IncludeSqliteData = $true,
    [switch]$SkipZip
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root
if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $Root 'dist\ReportingApp-Release'
}

function Assert-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Required command not found on PATH: $Name"
    }
}

function Finalize-ReleaseTree([string]$ReleaseRoot, [bool]$WithSqliteData, [string]$ProjectRoot) {
    $dbDir = Join-Path $ReleaseRoot 'database'
    if (Test-Path $dbDir) {
        Get-ChildItem -Path $dbDir -Filter 'damages-test-*.sqlite' -File -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
        Get-ChildItem -Path $dbDir -Filter '*-test-*.sqlite' -File -ErrorAction SilentlyContinue |
            Remove-Item -Force -ErrorAction SilentlyContinue
        Remove-Item (Join-Path $dbDir 'database.sqlite') -Force -ErrorAction SilentlyContinue
    }

    foreach ($rel in @(
        'storage\framework\sessions',
        'storage\framework\views',
        'storage\logs'
    )) {
        $dir = Join-Path $ReleaseRoot $rel
        if (Test-Path $dir) {
            Get-ChildItem -Path $dir -Force -ErrorAction SilentlyContinue |
                Where-Object { -not $_.PSIsContainer -or $_.Name -ne '.gitignore' } |
                Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    if ($WithSqliteData) {
        $expected = @(
            'reports-users.sqlite',
            'deliveries-local.sqlite',
            'damages-local.sqlite',
            'operations-tasks.sqlite',
            'accounting-local.sqlite',
            'promotions-local.sqlite'
        )
        $bundled = @()
        $missing = @()
        foreach ($name in $expected) {
            $path = Join-Path $dbDir $name
            if (Test-Path $path) {
                $item = Get-Item $path
                $bundled += [pscustomobject]@{
                    name = $name
                    bytes = $item.Length
                    updated = $item.LastWriteTimeUtc.ToString('o')
                }
            } else {
                $missing += $name
            }
        }

        $manifestPath = Join-Path $ReleaseRoot 'installer\BUNDLED-SQLITE.json'
        $manifest = @{
            generated_at = (Get-Date).ToUniversalTime().ToString('o')
            databases = $bundled
            missing = $missing
        }
        $manifest | ConvertTo-Json -Depth 4 | Set-Content -Path $manifestPath -Encoding UTF8

        if ($missing.Count -gt 0) {
            Write-Warning "SQLite bundle incomplete (missing: $($missing -join ', ')). Install will create empty DBs on first use."
        } else {
            Write-Host "Bundled SQLite databases: $($bundled.Count)" -ForegroundColor Green
            foreach ($row in $bundled) {
                Write-Host ("  {0} ({1:N0} bytes)" -f $row.name, $row.bytes)
            }
        }
    } else {
        $expected = @(
            'reports-users.sqlite',
            'deliveries-local.sqlite',
            'damages-local.sqlite',
            'operations-tasks.sqlite',
            'accounting-local.sqlite',
            'promotions-local.sqlite'
        )
        foreach ($name in $expected) {
            Remove-Item (Join-Path $dbDir $name) -Force -ErrorAction SilentlyContinue
        }
        Write-Host 'Update build: SQLite databases omitted from release package (server files are never replaced).' -ForegroundColor Yellow
    }

    $envExampleSrc = Join-Path $ProjectRoot 'installer\.env.production.example'
    if (Test-Path $envExampleSrc) {
        Copy-Item $envExampleSrc (Join-Path $ReleaseRoot 'installer\.env.production.example') -Force
    }
}

Write-Host "Reporting App release build" -ForegroundColor Cyan
Write-Host "Output: $OutputDir"

if (-not $SkipComposer) {
    Write-Host "Running composer install --no-dev..."
    Assert-Command composer
    Push-Location $Root
    composer install --no-dev --optimize-autoloader --no-interaction --ignore-platform-reqs
    Pop-Location
}

if (-not $SkipNpm) {
    Write-Host "Building front-end assets..."
    Assert-Command npm
    Push-Location $Root
    if (Test-Path 'package-lock.json') {
        npm ci 2>$null
        if ($LASTEXITCODE -ne 0) { npm install }
    } else {
        npm install
    }
    npm run build
    Pop-Location
}

if (-not (Test-Path (Join-Path $Root 'vendor'))) {
    throw 'vendor\ missing after composer install'
}
if (-not (Test-Path (Join-Path $Root 'public\build'))) {
    throw 'public\build\ missing after npm run build'
}

if (Test-Path $OutputDir) {
    Remove-Item $OutputDir -Recurse -Force
}
New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null

$excludeDirs = @('node_modules', '.git', 'dist', 'tests', '.cursor', '.idea', '.vscode', 'storage\app\sqlite-backups')
$robocopyArgs = @($Root, $OutputDir, '/MIR', '/NFL', '/NDL', '/NJH', '/NJS', '/nc', '/ns', '/np')
foreach ($d in $excludeDirs) {
    $robocopyArgs += '/XD'
    $robocopyArgs += $d
}
$robocopyArgs += '/XF'
$robocopyArgs += '.env'
$robocopyArgs += 'sqlite-auto-backup.json'

& robocopy @robocopyArgs | Out-Null
if ($LASTEXITCODE -ge 8) {
    throw "robocopy failed with exit code $LASTEXITCODE"
}

Finalize-ReleaseTree -ReleaseRoot $OutputDir -WithSqliteData:$IncludeSqliteData -ProjectRoot $Root

if ($BundleRuntime) {
    $phpSrc = Join-Path $Root 'installer\assets\php'
    $phpDst = Join-Path $OutputDir 'installer\assets\php'
    if (-not (Test-Path (Join-Path $phpSrc 'php.exe'))) {
        throw 'BundleRuntime requires installer\assets\php\php.exe - see installer\assets\README.md'
    }
    $assetsDst = Join-Path $OutputDir 'installer\assets'
    New-Item -ItemType Directory -Path $assetsDst -Force | Out-Null
    New-Item -ItemType Directory -Path $phpDst -Force | Out-Null
    Copy-Item -Path (Join-Path $phpSrc '*') -Destination $phpDst -Recurse -Force

    $odbcMsi = Join-Path $Root 'installer\assets\msodbcsql18.msi'
    if (Test-Path $odbcMsi) {
        Copy-Item $odbcMsi (Join-Path $assetsDst 'msodbcsql18.msi')
    }

    $rewriteMsi = Join-Path $Root 'installer\assets\rewrite_amd64.msi'
    if (Test-Path $rewriteMsi) {
        Copy-Item $rewriteMsi (Join-Path $assetsDst 'rewrite_amd64.msi')
    }

    $vcRedist = Join-Path $Root 'installer\assets\vc_redist.x64.exe'
    if (Test-Path $vcRedist) {
        Copy-Item $vcRedist (Join-Path $assetsDst 'vc_redist.x64.exe')
    }
}

& (Join-Path $Root 'scripts\verify-release.ps1') -ReleaseRoot $OutputDir -BundleRuntime:$BundleRuntime -UpdateBuild:(-not $IncludeSqliteData)

$version = Get-Date -Format 'yyyyMMdd-HHmm'
$zipPath = Join-Path (Split-Path $OutputDir -Parent) "ReportingApp-Release-$version.zip"
if (-not $SkipZip) {
    if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
    Compress-Archive -Path $OutputDir -DestinationPath $zipPath
}

Write-Host ""
Write-Host "Release ready:" -ForegroundColor Green
Write-Host "  Folder: $OutputDir"
if (-not $SkipZip) {
    Write-Host "  Zip:    $zipPath"
} else {
    Write-Host '  Zip:    (skipped - use build-setup-exe.ps1 for single-file .exe only)'
}
Write-Host ""
Write-Host "On the build machine (one-time, for offline setup):" -ForegroundColor Yellow
Write-Host "  .\scripts\fetch-installer-assets.ps1"
Write-Host "  .\scripts\build-setup-exe.ps1 -BundleRuntime"
Write-Host ""
Write-Host "Bundled local data: reports-users, deliveries, damages, operations-tasks SQLite files from database\" -ForegroundColor Yellow
Write-Host ""
Write-Host "On the server (as Administrator):" -ForegroundColor Yellow
Write-Host "  Run dist\ReportingApp-Setup.exe"
Write-Host "  OR extract the zip and double-click installer\Install.cmd"
