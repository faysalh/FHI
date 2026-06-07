#Requires -Version 5.1
<#
.SYNOPSIS
    Downloads PHP, SQL Server drivers, ODBC, and IIS URL Rewrite for offline installer bundles.

.EXAMPLE
    .\scripts\fetch-installer-assets.ps1
#>
param(
    [string]$PhpVersion = '',
    [string]$SqlPhpDriverTag = 'v5.13.1'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $Root
$Assets = Join-Path $Root 'installer\assets'
$PhpDir = Join-Path $Assets 'php'
$ExtDir = Join-Path $PhpDir 'ext'

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Download-File([string]$Url, [string]$Destination) {
    Write-Host "  $Url"
    if (Test-Path $Destination) { Remove-Item $Destination -Force }
    Invoke-WebRequest -Uri $Url -OutFile $Destination -UseBasicParsing
}

New-Item -ItemType Directory -Path $Assets -Force | Out-Null
New-Item -ItemType Directory -Path $ExtDir -Force | Out-Null

Write-Host "Fetching installer runtime assets into installer\assets\" -ForegroundColor Green

Write-Step 'PHP 8.3 NTS x64'
if (-not (Test-Path (Join-Path $PhpDir 'php.exe'))) {
    if ([string]::IsNullOrWhiteSpace($PhpVersion)) {
        $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -UseBasicParsing
        $PhpVersion = $releases.'8.3'.version
        Write-Host "  Using latest PHP 8.3 from releases.json: $PhpVersion"
    }
    $phpZipName = "php-$PhpVersion-nts-Win32-vs16-x64.zip"
    $phpZip = Join-Path $env:TEMP $phpZipName
    $phpUrl = "https://windows.php.net/downloads/releases/$phpZipName"
    Download-File $phpUrl $phpZip
    if (Test-Path $PhpDir) { Remove-Item $PhpDir -Recurse -Force }
    New-Item -ItemType Directory -Path $PhpDir -Force | Out-Null
    Expand-Archive -Path $phpZip -DestinationPath $PhpDir -Force
    Copy-Item (Join-Path $PhpDir 'php.ini-production') (Join-Path $PhpDir 'php.ini') -Force
} else {
    Write-Host '  php.exe already present - skipping'
}

Write-Step 'Microsoft PHP drivers for SQL Server'
$sqlZip = Join-Path $env:TEMP 'Windows_sqlsrv.zip'
$sqlUrl = "https://github.com/microsoft/msphpsql/releases/download/$SqlPhpDriverTag/Windows_5.13.1RTW.zip"
Download-File $sqlUrl $sqlZip
$sqlExtract = Join-Path $env:TEMP 'msphpsql-windows'
if (Test-Path $sqlExtract) { Remove-Item $sqlExtract -Recurse -Force }
Expand-Archive -Path $sqlZip -DestinationPath $sqlExtract -Force
$dlls = Get-ChildItem -Path $sqlExtract -Recurse -Filter '*83*nts*x64*.dll' -File
$sqlsrv = $dlls | Where-Object { $_.Name -like 'php_sqlsrv*' } | Select-Object -First 1
$pdo = $dlls | Where-Object { $_.Name -like 'php_pdo_sqlsrv*' } | Select-Object -First 1
if (-not $sqlsrv -or -not $pdo) {
    throw 'Could not find PHP 8.3 NTS x64 SQL Server driver DLLs in the release archive.'
}
Copy-Item $sqlsrv.FullName (Join-Path $ExtDir $sqlsrv.Name) -Force
Copy-Item $pdo.FullName (Join-Path $ExtDir $pdo.Name) -Force
Copy-Item $sqlsrv.FullName (Join-Path $ExtDir 'php_sqlsrv.dll') -Force
Copy-Item $pdo.FullName (Join-Path $ExtDir 'php_pdo_sqlsrv.dll') -Force
Write-Host "  $($sqlsrv.Name)"
Write-Host "  $($pdo.Name)"

Write-Step 'ODBC Driver 18 for SQL Server'
$odbcMsi = Join-Path $Assets 'msodbcsql18.msi'
if (-not (Test-Path $odbcMsi)) {
    Download-File 'https://go.microsoft.com/fwlink/?linkid=2249004' $odbcMsi
} else {
    Write-Host '  msodbcsql18.msi already present - skipping'
}

Write-Step 'IIS URL Rewrite 2.x'
$rewriteMsi = Join-Path $Assets 'rewrite_amd64.msi'
if (-not (Test-Path $rewriteMsi)) {
    Download-File 'https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi' $rewriteMsi
} else {
    Write-Host '  rewrite_amd64.msi already present - skipping'
}

Write-Step 'Visual C++ x64 runtime (required by SQL ODBC driver)'
$vcRedist = Join-Path $Assets 'vc_redist.x64.exe'
if (-not (Test-Path $vcRedist)) {
    Download-File 'https://aka.ms/vs/17/release/vc_redist.x64.exe' $vcRedist
} else {
    Write-Host '  vc_redist.x64.exe already present - skipping'
}

Write-Host ""
Write-Host "Assets ready. Build the offline package with:" -ForegroundColor Green
Write-Host "  .\scripts\build-setup-exe.ps1 -BundleRuntime"
