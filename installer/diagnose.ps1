<#
.SYNOPSIS
    Checks a production Reporting App install and prints what is wrong.
#>
param(
    [string]$InstallPath = '',
    [string]$SiteName = 'ReportingApp',
    [int]$ExpectedPort = 0
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Continue'

function Test-Check([string]$Label, [bool]$Ok, [string]$Detail, [string]$Fix = '') {
    $status = if ($Ok) { 'OK' } else { 'FAIL' }
    $color = if ($Ok) { 'Green' } else { 'Red' }
    Write-Host ("[{0}] {1}" -f $status, $Label) -ForegroundColor $color
    if ($Detail) { Write-Host "      $Detail" -ForegroundColor DarkGray }
    if (-not $Ok -and $Fix) { Write-Host "      Fix: $Fix" -ForegroundColor Yellow }
    return $Ok
}

function Read-EnvValue([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            $value = $Matches[2].Trim().Trim('"')
            return $value
        }
    }
    return ''
}

function Invoke-DiagnoseWebRequest([string]$Uri) {
    if ($Uri -match '^https://') {
        $previousCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        try {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
            return Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
        } finally {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $previousCallback
        }
    }

    return Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 15 -ErrorAction Stop
}

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = Split-Path -Parent $ScriptDir
}
if (-not (Test-Path (Join-Path $InstallPath 'artisan'))) {
    Write-Host "Not a Reporting App folder (no artisan): $InstallPath" -ForegroundColor Red
    exit 1
}
$InstallPath = (Resolve-Path $InstallPath).Path

Write-Host ''
Write-Host 'Reporting App - install diagnostic' -ForegroundColor Cyan
Write-Host "Folder: $InstallPath"
Write-Host ''

$failures = 0
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'

if (-not (Test-Check 'Application files' $true "artisan found")) { $failures++ }

if (-not (Test-Path $envPath)) {
    if (-not (Test-Check '.env file' $false 'Missing - Laravel cannot run' "Run installer\create-env.cmd, then re-run installer\install.ps1 as Administrator")) { $failures++ }
    $appUrl = ''
    $appKey = ''
} else {
    $appUrl = Read-EnvValue $envPath 'APP_URL'
    $appKey = Read-EnvValue $envPath 'APP_KEY'
    if (-not (Test-Check '.env file' $true $envPath)) { $failures++ }
    if (-not (Test-Check 'APP_KEY in .env' ([string]::IsNullOrWhiteSpace($appKey) -eq $false) $(if ($appKey) { 'Set' } else { 'Empty' }) "Run: runtime\php\php.exe artisan key:generate --force")) { $failures++ }
    if (-not (Test-Check 'APP_URL in .env' ([string]::IsNullOrWhiteSpace($appUrl) -eq $false) $appUrl "Set APP_URL to http://YOUR_SERVER_IP:PORT")) { $failures++ }
}

if (-not (Test-Path $phpExe)) {
    if (-not (Test-Check 'Bundled PHP' $false 'runtime\php\php.exe missing' 'Re-run ReportingApp-Setup.exe as Administrator')) { $failures++ }
} else {
    if (-not (Test-Check 'Bundled PHP' $true $phpExe)) { $failures++ }
    Push-Location $InstallPath
    $health = & $phpExe artisan reports:db-health 2>&1 | Out-String
    Pop-Location
    $dbOk = ($LASTEXITCODE -eq 0) -and ($health -notmatch 'FAIL|Error|Exception|undefined function')
    $healthDetail = ($health.Trim() -replace '\s+', ' ')
    $dbFix = 'Check DB_PASSWORD in .env and SQL login on the server'
    if ($health -match 'mb_split|mbstring') {
        $dbFix = 'PHP mbstring extension missing. Run installer\repair-web.cmd as Administrator (rebuilds php.ini).'
    }
    if (-not (Test-Check 'SQL Server connection' $dbOk $healthDetail $dbFix)) { $failures++ }
}

$sqliteKeys = @(
    @{ Key = 'REPORTS_USERS_SQLITE_DATABASE'; Label = 'Users & permissions' },
    @{ Key = 'DELIVERIES_SQLITE_DATABASE'; Label = 'Deliveries' },
    @{ Key = 'DAMAGES_SQLITE_DATABASE'; Label = 'Damages' },
    @{ Key = 'OPERATIONS_TASKS_SQLITE_DATABASE'; Label = 'Tasks' },
    @{ Key = 'ACCOUNTING_SQLITE_DATABASE'; Label = 'Accounting' },
    @{ Key = 'PROMOTIONS_SQLITE_DATABASE'; Label = 'Promotions' },
    @{ Key = 'FACE_ID_SQLITE_DATABASE'; Label = 'Face ID' },
    @{ Key = 'MANUFACTURING_SQLITE_DATABASE'; Label = 'Manufacturing storage' }
)
if (Test-Path $envPath) {
    Write-Host ''
    Write-Host 'SQLite databases (.env paths):' -ForegroundColor Cyan
    foreach ($entry in $sqliteKeys) {
        $dbPath = Read-EnvValue $envPath $entry.Key
        if ([string]::IsNullOrWhiteSpace($dbPath)) {
            if (-not (Test-Check $entry.Label $false 'Path not set in .env' "Add $($entry.Key) to .env, then run: runtime\php\php.exe artisan config:cache")) { $failures++ }
            continue
        }
        $exists = Test-Path $dbPath
        $sizeText = 'missing'
        if ($exists) {
            $bytes = (Get-Item $dbPath).Length
            $sizeText = "$bytes bytes"
        }
        $ok = $exists -and ((Get-Item $dbPath -ErrorAction SilentlyContinue).Length -gt 0)
        $fix = if (-not $exists) {
            'File missing. Restore from storage\app\sqlite-backups\pre-install-* or a manual backup.'
        } elseif (-not $ok) {
            'File is empty (0 bytes). Restore from backup or re-enter data in the app.'
        } else {
            ''
        }
        if (-not (Test-Check $entry.Label $ok "$dbPath | $sizeText" $fix)) { $failures++ }
    }
}

$port = $ExpectedPort
if ($port -le 0 -and $appUrl -match ':(\d+)') {
    $port = [int]$Matches[1]
}
if ($port -le 0) { $port = 8090 }

$iisOk = $false
$siteState = 'unknown'
$bindings = @()
try {
    Import-Module WebAdministration -ErrorAction Stop
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    if ($site) {
        $siteState = [string]$site.State
        $bindings = Get-WebBinding -Name $SiteName | ForEach-Object { "$($_.protocol)://$($_.bindingInformation)" }
        $iisOk = ($site.State -eq 'Started')
        if (-not (Test-Check "IIS site '$SiteName'" $true "State: $siteState | Bindings: $($bindings -join ', ')")) { }
        if (-not (Test-Check 'IIS site running' $iisOk $siteState "Run as Admin: Start-Website -Name $SiteName")) { $failures++ }

        if ($appUrl -match '^https://' -and ($bindings -notmatch 'https://')) {
            if (-not (Test-Check 'APP_URL vs IIS bindings' $false "APP_URL is HTTPS but IIS has no https binding" 'Run installer\enable-https.cmd OR set APP_URL to http://10.10.10.250:8090 and run: runtime\php\php.exe artisan config:cache')) { $failures++ }
        }

        $phys = $site.physicalPath
        $expectedPublic = Join-Path $InstallPath 'public'
        $pathOk = ($phys -eq $expectedPublic)
        if (-not (Test-Check 'IIS physical path' $pathOk "IIS: $phys" "Should be: $expectedPublic. Re-run installer\install.ps1 as Administrator")) { $failures++ }
    } else {
        if (-not (Test-Check "IIS site '$SiteName'" $false 'Site not found' 'Re-run installer\install.ps1 as Administrator')) { $failures++ }
    }

    $rewrite = Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue
    if (-not (Test-Check 'IIS URL Rewrite module' ($null -ne $rewrite) $(if ($rewrite) { 'Installed' } else { 'Missing - /login will 404' }) 'Re-run installer as Administrator (bundled rewrite MSI)')) { $failures++ }
} catch {
    if (-not (Test-Check 'IIS check' $false $_.Exception.Message 'Install IIS and re-run installer\install.ps1 as Administrator')) { $failures++ }
}

$listening = netstat -an | Select-String "LISTENING" | Select-String ":$port\s"
if (-not (Test-Check "Port $port listening" ($null -ne $listening) $(if ($listening) { $listening[0].Line.Trim() } else { 'Nothing listening' }) "Re-run installer\install.ps1 as Administrator; check port conflict")) { $failures++ }

if ($appUrl) {
    $probeUrl = $appUrl.TrimEnd('/') + '/login'
    $httpFallback = $probeUrl -replace '^https://', 'http://'
    if ($port -gt 0 -and $httpFallback -notmatch ":$port/") {
        $httpFallback = "http://127.0.0.1:$port/login"
    }
    $probeOk = $false
    $probeDetail = ''
    foreach ($url in @($probeUrl, $httpFallback)) {
        if ($url -eq $probeUrl -and $probeUrl -match '^https://' -and $appUrl -match '^https://' -and ($bindings -notmatch 'https://')) {
            continue
        }
        try {
            $resp = Invoke-DiagnoseWebRequest -Uri $url
            if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 400) {
                $probeOk = $true
                $probeDetail = "Status $($resp.StatusCode) for $url"
                if ($url -match '^https://') {
                    $probeDetail += ' (self-signed cert OK for install; browsers warn once)'
                } elseif ($url -ne $probeUrl) {
                    $probeDetail += ' (APP_URL uses HTTPS but site answered on HTTP - fix APP_URL or run enable-https.cmd)'
                }
                break
            }
        } catch {
            $probeDetail = "$url - $($_.Exception.Message)"
        }
    }
    if (-not (Test-Check 'HTTP /login on this server' $probeOk $probeDetail 'Check IIS, URL Rewrite, APP_URL scheme/port, and storage\logs\laravel.log')) { $failures++ }
}

Write-Host ''
Write-Host 'Application timezone:' -ForegroundColor Cyan
if (Test-Path $phpExe) {
    $about = & $phpExe artisan about 2>&1 | Out-String
    $tzOk = $false
    $tzDetail = 'Could not read timezone'
    if ($about -match 'Timezone\s+\.+\s+(\S+)') {
        $tzDetail = $Matches[1]
        $tzOk = $tzDetail -eq 'Asia/Baghdad'
    }
    if (-not (Test-Check 'Laravel timezone (Face ID attendance)' $tzOk $tzDetail 'Run installer\repair-timezone.cmd as Administrator')) { $failures++ }
}

Write-Host ''
Write-Host 'Face ID assets:' -ForegroundColor Cyan
$faceApiJs = Join-Path $InstallPath 'public\js\face-api.min.js'
$faceApiOk = (Test-Path $faceApiJs) -and ((Get-Item $faceApiJs -ErrorAction SilentlyContinue).Length -gt 100000)
if (-not (Test-Check 'Bundled face-api.min.js' $faceApiOk $(if ($faceApiOk) { $faceApiJs } else { 'Missing or too small' }) 'Re-run ReportingApp-Setup.exe upgrade')) { $failures++ }
if ($faceApiOk -and $appUrl) {
    $faceApiUrl = $appUrl.TrimEnd('/') + '/js/face-api.min.js'
    $httpOk = $false
    $httpDetail = ''
    try {
        $resp = Invoke-DiagnoseWebRequest -Uri $faceApiUrl
        $httpOk = $resp.StatusCode -eq 200 -and $resp.Content.Length -gt 100000
        $httpDetail = if ($httpOk) { "HTTP 200 ($($resp.Content.Length) bytes)" } else { "HTTP $($resp.StatusCode), $($resp.Content.Length) bytes" }
    } catch {
        $httpDetail = $_.Exception.Message
    }
    if (-not (Test-Check 'face-api.min.js served over HTTP(S)' $httpOk $httpDetail 'IIS hiddenSegments blocks /js/vendor/ — file must be at public\js\face-api.min.js')) { $failures++ }
}

$modelsDir = Join-Path $InstallPath 'public\face-api-models'
$requiredModels = @(
    'tiny_face_detector_model-weights_manifest.json',
    'tiny_face_detector_model-shard1.bin',
    'face_landmark_68_model-weights_manifest.json',
    'face_landmark_68_model-shard1.bin',
    'face_recognition_model-weights_manifest.json',
    'face_recognition_model-shard1.bin',
    'face_recognition_model-shard2.bin'
)
$missingModels = @()
foreach ($model in $requiredModels) {
    $modelPath = Join-Path $modelsDir $model
    if (-not (Test-Path $modelPath) -or (Get-Item $modelPath -ErrorAction SilentlyContinue).Length -le 0) {
        $missingModels += $model
    }
}
$modelsOk = $missingModels.Count -eq 0
$modelsDetail = if ($modelsOk) { 'All 7 model files present' } else { 'Missing: ' + ($missingModels -join ', ') }
if (-not (Test-Check 'Face-api model weights' $modelsOk $modelsDetail 'Re-run ReportingApp-Setup.exe upgrade')) { $failures++ }

if ($modelsOk -and $appUrl) {
    $shardUrl = $appUrl.TrimEnd('/') + '/face-api-models/tiny_face_detector_model-shard1.bin'
    $shardOk = $false
    $shardDetail = ''
    try {
        $resp = Invoke-DiagnoseWebRequest -Uri $shardUrl
        $shardOk = $resp.StatusCode -eq 200 -and $resp.Content.Length -gt 100000
        $shardDetail = if ($shardOk) { "HTTP 200 ($($resp.Content.Length) bytes)" } else { "HTTP $($resp.StatusCode), $($resp.Content.Length) bytes — shard may be routed to Laravel" }
    } catch {
        $shardDetail = $_.Exception.Message
    }
    if (-not (Test-Check 'tinyFaceDetector weight shard served' $shardOk $shardDetail 'Ensure public\web.config FaceApiModelWeightFiles rule and .bin shards are deployed')) { $failures++ }
}

$logPath = Join-Path $InstallPath 'storage\logs\laravel.log'
if (Test-Path $logPath) {
    $tail = Get-Content $logPath -Tail 8 -ErrorAction SilentlyContinue
    if ($tail) {
        Write-Host ''
        Write-Host 'Recent laravel.log:' -ForegroundColor DarkGray
        $tail | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkGray }
    }
}

$installLog = Get-ChildItem (Join-Path $InstallPath 'storage\logs') -Filter 'install-*.log' -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
if ($installLog) {
    Write-Host ''
    Write-Host "Last install log: $($installLog.FullName)" -ForegroundColor DarkGray
}

Write-Host ''
if ($failures -eq 0) {
    Write-Host 'All checks passed. Open in browser:' -ForegroundColor Green
    Write-Host "  $($appUrl.TrimEnd('/'))/login"
} else {
    Write-Host "$failures check(s) failed." -ForegroundColor Red
    Write-Host ''
    Write-Host 'Most installs are fixed by re-running the server setup (Administrator PowerShell):' -ForegroundColor Yellow
    Write-Host "  cd `"$InstallPath`""
    Write-Host "  powershell -ExecutionPolicy Bypass -File installer\install.ps1 -InstallPath `"$InstallPath`" -SitePort $port -AppUrl `"$appUrl`""
    Write-Host ''
    Write-Host 'Do NOT use start-reporting-app or run-dev on the server - those are for development PCs with Composer/npm.' -ForegroundColor Yellow
}
Write-Host ''
exit $(if ($failures -eq 0) { 0 } else { 1 })
