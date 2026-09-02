#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Installs Reporting App on Windows Server (IIS + bundled PHP).

.DESCRIPTION
    Run from the release package root (contains artisan + vendor).
    Example: powershell -ExecutionPolicy Bypass -File installer\install.ps1
#>
param(
    [string]$InstallPath = '',
    [int]$SitePort = 8090,
    [string]$SiteName = 'ReportingApp',
    [string]$AppPoolName = 'ReportingApp',
    [string]$SqlHost = '',
    [string]$SqlDatabase = 'AsanAccounting',
    [string]$SqlUser = 'Reporting',
    [string]$SqlPassword = '',
    [string]$AdminUsername = 'admin',
    [string]$AdminPassword = '',
    [string]$AppUrl = '',
    [switch]$AllowDownload,
    [switch]$SkipIis,
    [switch]$SkipOdbc,
    [switch]$SkipDbHealth,
    [switch]$Quiet,
    [switch]$Pause,
    [string]$ConfigFile = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:PauseOnExit = $Pause.IsPresent

trap {
    Write-Host ''
    Write-Host '============================================' -ForegroundColor Red
    Write-Host ' INSTALL FAILED' -ForegroundColor Red
    Write-Host '============================================' -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    if ($_.ScriptStackTrace) {
        Write-Host ''
        Write-Host $_.ScriptStackTrace -ForegroundColor DarkGray
    }
    Write-Host ''
    Write-Host 'Re-run as Administrator and read the messages above.' -ForegroundColor Yellow
    Write-Host 'You can also run installer\diagnose.cmd to check what is wrong.' -ForegroundColor Yellow
    try { Stop-Transcript | Out-Null } catch {}
    if ($script:PauseOnExit) { Read-Host 'Press Enter to close' | Out-Null }
    exit 1
}

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Read-Required([string]$Prompt, [string]$Default = '') {
    if ($Quiet -and $Default -ne '') { return $Default }
    $suffix = if ($Default -ne '') { " [$Default]" } else { '' }
    do {
        $value = Read-Host "$Prompt$suffix"
        if ($value -eq '' -and $Default -ne '') { return $Default }
    } while ([string]::IsNullOrWhiteSpace($value))
    return $value.Trim()
}

function Read-Secret([string]$Prompt, [string]$Preset = '') {
    if ($Quiet) {
        if ([string]::IsNullOrWhiteSpace($Preset)) {
            throw "Quiet mode requires a password value for: $Prompt"
        }
        return $Preset
    }
    $secure = Read-Host $Prompt -AsSecureString
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringAuto($ptr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
}

function Read-InstallConfig([string]$Path) {
    if (-not (Test-Path $Path)) { throw "Config file not found: $Path" }
    $map = @{}
    foreach ($line in Get-Content $Path) {
        if ($line -match '^\s*#' -or $line -match '^\s*$') { continue }
        $idx = $line.IndexOf('=')
        if ($idx -lt 1) { continue }
        $key = $line.Substring(0, $idx).Trim()
        $value = $line.Substring($idx + 1)
        $map[$key] = $value
    }
    return $map
}

function Read-EnvValue([string]$EnvPath, [string]$Key) {
    foreach ($line in Get-Content $EnvPath -ErrorAction SilentlyContinue) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            if ($Matches[1] -ne $Key) { continue }
            return $Matches[2].Trim().Trim('"')
        }
    }
    return ''
}

function Get-CollectionLength($value) {
    if ($null -eq $value) {
        return 0
    }
    return @($value).Length
}

function Ensure-EnvSqliteKeys([string]$EnvPath, [string]$InstallPath) {
    if (-not (Test-Path $EnvPath)) { return @() }
    $dbPath = ($InstallPath -replace '\\', '/').TrimEnd('/')
    $required = [ordered]@{
        'APP_TIMEZONE'               = 'Asia/Baghdad'
        'ACCOUNTING_SQLITE_DATABASE' = "$dbPath/database/accounting-local.sqlite"
        'PROMOTIONS_SQLITE_DATABASE' = "$dbPath/database/promotions-local.sqlite"
        'FACE_ID_SQLITE_DATABASE'    = "$dbPath/database/face-id-local.sqlite"
    }
    $added = @()
    $lines = @(Get-Content $EnvPath -ErrorAction SilentlyContinue)
    foreach ($key in $required.Keys) {
        if (-not [string]::IsNullOrWhiteSpace((Read-EnvValue -EnvPath $EnvPath -Key $key))) { continue }
        $lines += "$key=`"$($required[$key])`""
        $added += $key
    }
    if (Get-CollectionLength $added -gt 0) {
        Set-Content -Path $EnvPath -Value $lines -Encoding UTF8
    }
    return ,@($added)
}

function Install-UrlRewrite([string]$AssetsRoot) {
    if (Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue) {
        Write-Host 'IIS URL Rewrite already installed.'
        return
    }
    $msi = Join-Path $AssetsRoot 'rewrite_amd64.msi'
    if (-not (Test-Path $msi)) {
        throw 'IIS URL Rewrite is required for Laravel routes (/login, /reports/*) but rewrite_amd64.msi was not found in installer\assets.'
    }
    Write-Step 'Installing IIS URL Rewrite...'
    $p = Start-Process msiexec.exe -ArgumentList @('/i', "`"$msi`"", '/qn', 'REBOOT=ReallySuppress') -Wait -PassThru
    if ($p.ExitCode -ne 0) { throw "URL Rewrite installer exited with code $($p.ExitCode)" }
    if (-not (Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue)) {
        throw 'IIS URL Rewrite did not register after install. Reboot the server, then run installer\repair-web.cmd as Administrator.'
    }
}

function Invoke-InstallerWebRequest([string]$Uri) {
    if ($Uri -match '^https://') {
        $previousCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
        try {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }
            return Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop
        } finally {
            [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $previousCallback
        }
    }

    return Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 20 -ErrorAction Stop
}

function Test-IisWebSetup(
    [string]$SiteName,
    [int]$Port,
    [string]$InstallPath,
    [string]$AppUrl
) {
    Import-Module WebAdministration -ErrorAction Stop

    $expectedPublic = Join-Path $InstallPath 'public'
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    if (-not $site) {
        throw "IIS site '$SiteName' was not created."
    }
    if ($site.State -ne 'Started') {
        Start-Website -Name $SiteName
    }
    if ($site.physicalPath -ne $expectedPublic) {
        throw "IIS physical path is wrong: $($site.physicalPath). Expected: $expectedPublic. Run installer\repair-web.cmd as Administrator."
    }

    $rewrite = Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue
    if (-not $rewrite) {
        throw 'IIS URL Rewrite is not installed. Without it, /login returns 404. Run installer\repair-web.cmd as Administrator.'
    }

    $portsToCheck = @($Port)
    if ($AppUrl -match '^https://' -and $AppUrl -match ':(\d+)') {
        $httpsPort = [int]$Matches[1]
        if ($portsToCheck -notcontains $httpsPort) { $portsToCheck += $httpsPort }
    } elseif ($AppUrl -match '^https://') {
        if ($portsToCheck -notcontains 443) { $portsToCheck += 443 }
    }
    $anyListening = $false
    foreach ($checkPort in $portsToCheck) {
        $listening = netstat -an | Select-String 'LISTENING' | Select-String ":$checkPort\s"
        if ($listening) { $anyListening = $true }
    }
    if (-not $anyListening) {
        throw "Nothing is listening on TCP port(s) $($portsToCheck -join ', '). Check for a port conflict or re-run installer\repair-web.cmd."
    }

    $probeUrls = [System.Collections.Generic.List[string]]::new()
    if (-not [string]::IsNullOrWhiteSpace($AppUrl)) {
        $probeUrls.Add($AppUrl.TrimEnd('/') + '/login')
    }
    $probeUrls.Add("http://127.0.0.1:$Port/login")

    $lastError = 'No probe URL attempted.'
    $selfSignedHttps = $false
    foreach ($probeUrl in $probeUrls) {
        try {
            $resp = Invoke-InstallerWebRequest -Uri $probeUrl
            if ($resp.StatusCode -lt 200 -or $resp.StatusCode -ge 400) {
                $lastError = "HTTP $($resp.StatusCode) from $probeUrl"
                continue
            }
            if ($probeUrl -match '^https://') {
                $selfSignedHttps = $true
            }
            Write-Host "  Verified login page: $probeUrl (status $($resp.StatusCode))" -ForegroundColor Green
            if ($selfSignedHttps) {
                Write-Host '  Note: HTTPS uses a self-signed certificate. Browsers show a warning once - tap Advanced -> Proceed.' -ForegroundColor Yellow
            }
            return
        } catch {
            $lastError = "$probeUrl - $($_.Exception.Message)"
        }
    }

    throw "Could not load login page. Last error: $lastError. Run installer\diagnose.cmd as Administrator."
}

function Enable-IisWindowsFeatures {
    # Windows Server path
    if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) {
        $needed = @(
            'Web-Server', 'Web-CGI', 'Web-ISAPI-Ext', 'Web-ISAPI-Filter',
            'Web-Static-Content', 'Web-Default-Doc', 'Web-Dir-Browsing',
            'Web-Http-Logging', 'Web-Filtering', 'Web-Mgmt-Console'
        )
        $missing = @()
        foreach ($feature in $needed) {
            $state = (Get-WindowsFeature -Name $feature -ErrorAction SilentlyContinue).InstallState
            if ($state -ne 'Installed') { $missing += $feature }
        }
        if ($missing.Count -eq 0) {
            Write-Host 'IIS features already installed.'
            return
        }
        Write-Step "Enabling IIS features (Server): $($missing -join ', ')"
        $result = Install-WindowsFeature -Name $missing -IncludeManagementTools
        if ($result.ExitCode -ne 'Success' -and $result.ExitCode -ne 'SuccessRestartRequired') {
            throw "Failed to enable IIS features: $($result.ExitCode)"
        }
        return
    }

    # Windows 10/11 client path (DISM optional features)
    Write-Step 'Enabling IIS features (Windows client via DISM)'
    $clientFeatures = @(
        'IIS-WebServerRole', 'IIS-WebServer', 'IIS-CommonHttpFeatures',
        'IIS-StaticContent', 'IIS-DefaultDocument', 'IIS-DirectoryBrowsing',
        'IIS-HttpErrors', 'IIS-HttpLogging', 'IIS-RequestFiltering',
        'IIS-CGI', 'IIS-ISAPIExtensions', 'IIS-ISAPIFilter',
        'IIS-ManagementConsole'
    )

    if (Get-Command Enable-WindowsOptionalFeature -ErrorAction SilentlyContinue) {
        foreach ($feature in $clientFeatures) {
            $state = (Get-WindowsOptionalFeature -Online -FeatureName $feature -ErrorAction SilentlyContinue).State
            if ($state -eq 'Enabled') { continue }
            Write-Host "  Enabling $feature"
            Enable-WindowsOptionalFeature -Online -FeatureName $feature -All -NoRestart -ErrorAction Stop | Out-Null
        }
        return
    }

    # Fallback: dism.exe directly
    Write-Host '  Using dism.exe to enable IIS features'
    foreach ($feature in $clientFeatures) {
        & dism.exe /online /enable-feature /featurename:$feature /all /norestart | Out-Null
    }
    if (-not (Get-Command Get-Website -ErrorAction SilentlyContinue) -and -not (Test-Path "$env:WINDIR\System32\inetsrv\appcmd.exe")) {
        throw 'IIS could not be enabled automatically. Enable "Internet Information Services" in Windows Features, then re-run installer\install.ps1.'
    }
}

function Install-SqlPhpDrivers([string]$ExtDir, [switch]$AllowDownload) {
    $hasSqlsrv = Get-ChildItem -Path $ExtDir -Filter 'php_sqlsrv*.dll' -ErrorAction SilentlyContinue | Select-Object -First 1
    $hasPdo = Get-ChildItem -Path $ExtDir -Filter 'php_pdo_sqlsrv*.dll' -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($hasSqlsrv -and $hasPdo) { return }

    if (-not $AllowDownload) {
        throw 'SQL Server PHP drivers missing in runtime\php\ext. Rebuild with -BundleRuntime or use -AllowDownload.'
    }

    Write-Step 'Downloading Microsoft PHP drivers for SQL Server...'
    $sqlZip = Join-Path $env:TEMP 'Windows_sqlsrv.zip'
    Invoke-WebRequest -Uri 'https://github.com/microsoft/msphpsql/releases/download/v5.13.1/Windows_5.13.1RTW.zip' `
        -OutFile $sqlZip -UseBasicParsing
    $sqlExtract = Join-Path $env:TEMP 'msphpsql-windows-install'
    if (Test-Path $sqlExtract) { Remove-Item $sqlExtract -Recurse -Force }
    Expand-Archive -Path $sqlZip -DestinationPath $sqlExtract -Force
    $dlls = Get-ChildItem -Path $sqlExtract -Recurse -Filter '*83*nts*x64*.dll' -File
    $sqlsrv = $dlls | Where-Object { $_.Name -like 'php_sqlsrv*' } | Select-Object -First 1
    $pdo = $dlls | Where-Object { $_.Name -like 'php_pdo_sqlsrv*' } | Select-Object -First 1
    if (-not $sqlsrv -or -not $pdo) {
        throw 'Could not find PHP 8.3 NTS x64 SQL Server driver DLLs.'
    }
    Copy-Item $sqlsrv.FullName (Join-Path $ExtDir $sqlsrv.Name) -Force
    Copy-Item $pdo.FullName (Join-Path $ExtDir $pdo.Name) -Force
    Copy-Item $sqlsrv.FullName (Join-Path $ExtDir 'php_sqlsrv.dll') -Force
    Copy-Item $pdo.FullName (Join-Path $ExtDir 'php_pdo_sqlsrv.dll') -Force
}

function Test-VcRedistInstalled {
    # VC++ 2015-2022 x64 runtime registers Installed=1 here.
    $key = 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64'
    $val = Get-ItemProperty -Path $key -Name 'Installed' -ErrorAction SilentlyContinue
    if ($val -and $val.Installed -eq 1) { return $true }
    # Fallback: presence of the runtime DLL.
    return (Test-Path (Join-Path $env:WINDIR 'System32\vcruntime140.dll'))
}

function Install-VcRedist([string]$AssetsRoot, [switch]$AllowDownload) {
    if (Test-VcRedistInstalled) {
        Write-Host 'Visual C++ x64 runtime already installed.'
        return $true
    }
    $exe = Join-Path $AssetsRoot 'vc_redist.x64.exe'
    if (-not (Test-Path $exe)) {
        if (-not $AllowDownload) {
            Write-Warning 'vc_redist.x64.exe not bundled. The ODBC driver needs the Visual C++ x64 runtime.'
            return $false
        }
        Write-Step 'Downloading Visual C++ x64 runtime...'
        $exe = Join-Path $env:TEMP 'vc_redist.x64.exe'
        Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $exe -UseBasicParsing
    }
    Write-Step 'Installing Visual C++ x64 runtime (required by SQL ODBC driver)...'
    $p = Start-Process $exe -ArgumentList @('/install', '/quiet', '/norestart') -Wait -PassThru
    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010 -or $p.ExitCode -eq 1638) {
        Write-Host 'Visual C++ x64 runtime installed.'
        return $true
    }
    if (Test-VcRedistInstalled) { return $true }
    Write-Warning "Visual C++ runtime installer returned $($p.ExitCode). ODBC driver install may fail."
    return $false
}

function Get-InstalledSqlOdbcDriver {
    # pdo_sqlsrv works with ODBC Driver 17 or 18; accept either.
    Get-OdbcDriver -Platform '64-bit' -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -match 'ODBC Driver 1[78] for SQL Server' } |
        Select-Object -First 1
}

# Returns $true if a usable SQL ODBC driver is present after this call.
function Install-OdbcDriver18([string]$AssetsRoot) {
    $existing = Get-InstalledSqlOdbcDriver
    if ($existing) {
        Write-Host "SQL ODBC driver already installed: $($existing.Name)"
        return $true
    }
    $msi = Join-Path $AssetsRoot 'msodbcsql18.msi'
    if (-not (Test-Path $msi)) {
        if (-not $AllowDownload) {
            Write-Warning "ODBC Driver 18 MSI not found and -AllowDownload not set. SQL Server reports will not work until an ODBC driver is installed."
            return $false
        }
        Write-Step 'Downloading ODBC Driver 18...'
        $tmp = Join-Path $env:TEMP 'msodbcsql18.msi'
        Invoke-WebRequest -Uri 'https://go.microsoft.com/fwlink/?linkid=2249004' -OutFile $tmp -UseBasicParsing
        $msi = $tmp
    }
    Write-Step 'Installing ODBC Driver 18...'
    $msiLog = Join-Path $env:TEMP 'msodbcsql18-install.log'
    $p = Start-Process msiexec.exe -ArgumentList @('/i', "`"$msi`"", '/qn', '/norestart', '/l*v', "`"$msiLog`"", 'IACCEPTMSODBCLICENSETERMS=YES') -Wait -PassThru
    if ($p.ExitCode -eq 0 -or $p.ExitCode -eq 3010) {
        Write-Host 'ODBC Driver 18 installed.'
        return $true
    }
    # Re-check: a 1603 often means the driver is already present (MSI refuses to reinstall).
    $afterFail = Get-InstalledSqlOdbcDriver
    if ($afterFail) {
        Write-Warning "ODBC MSI returned $($p.ExitCode) but a SQL ODBC driver is already present: $($afterFail.Name). Continuing."
        return $true
    }
    Write-Warning "ODBC Driver 18 install failed (msiexec code $($p.ExitCode)). Log: $msiLog"
    Write-Warning "Install continues; SQL Server reports will not work until an ODBC driver is installed manually."
    return $false
}

function Ensure-BundledPhp([string]$AssetsRoot, [string]$RuntimePhpDir, [switch]$AllowDownload) {
    $phpExe = Join-Path $RuntimePhpDir 'php.exe'
    if (Test-Path $phpExe) { return $phpExe }

    $bundled = Join-Path $AssetsRoot 'php'
    if (Test-Path (Join-Path $bundled 'php.exe')) {
        Write-Step "Copying bundled PHP to $RuntimePhpDir"
        New-Item -ItemType Directory -Path $RuntimePhpDir -Force | Out-Null
        Copy-Item -Path (Join-Path $bundled '*') -Destination $RuntimePhpDir -Recurse -Force
        return $phpExe
    }

    if (-not $AllowDownload) {
        throw "PHP not bundled. See installer\assets\README.md or run with -AllowDownload."
    }

    Write-Step 'Downloading PHP 8.3 NTS x64...'
    $zipUrl = 'https://windows.php.net/downloads/releases/php-8.3.31-nts-Win32-vs16-x64.zip'
    $zipPath = Join-Path $env:TEMP 'php83nts.zip'
    Invoke-WebRequest -Uri $zipUrl -OutFile $zipPath -UseBasicParsing
    New-Item -ItemType Directory -Path $RuntimePhpDir -Force | Out-Null
    Expand-Archive -Path $zipPath -DestinationPath $RuntimePhpDir -Force
    $iniProd = Join-Path $RuntimePhpDir 'php.ini-production'
    $ini = Join-Path $RuntimePhpDir 'php.ini'
    if ((Test-Path $iniProd) -and -not (Test-Path $ini)) {
        Copy-Item $iniProd $ini
    }
    Install-SqlPhpDrivers -ExtDir (Join-Path $RuntimePhpDir 'ext') -AllowDownload:$AllowDownload
    return $phpExe
}

function Initialize-PhpIni([string]$PhpRoot) {
    $ini = Join-Path $PhpRoot 'php.ini'
    if (-not (Test-Path $ini)) {
        $prod = Join-Path $PhpRoot 'php.ini-production'
        if (Test-Path $prod) { Copy-Item $prod $ini } else { Set-Content -Path $ini -Value '' -Encoding ASCII }
    }
    $extDir = (Join-Path $PhpRoot 'ext')

    # Use canonical short extension names. We bundle php_sqlsrv.dll / php_pdo_sqlsrv.dll,
    # so PHP resolves extension=sqlsrv -> ext\php_sqlsrv.dll.
    $extensions = @(
        'curl', 'fileinfo', 'gd', 'mbstring', 'openssl',
        'pdo_sqlite', 'sqlite3', 'zip', 'sqlsrv', 'pdo_sqlsrv'
    )

    $lines = Get-Content $ini
    $out = New-Object System.Collections.Generic.List[string]
    foreach ($line in $lines) {
        # Drop any existing extension_dir lines (commented or not) and any of our managed extension lines.
        if ($line -match '^\s*;?\s*extension_dir\s*=') { continue }
        $isManaged = $false
        foreach ($e in $extensions) {
            if ($line -match "^\s*;?\s*extension\s*=\s*(php_)?$([regex]::Escape($e))(_83_nts_x64)?(\.dll)?\s*$") { $isManaged = $true; break }
        }
        if ($isManaged) { continue }
        if ($line -match '^\s*;?\s*date\.timezone\s*=') { continue }
        $out.Add($line)
    }

    # Drop any prior copies of the CGI/FastCGI keys we manage so we don't duplicate.
    $managedKeys = @(
        'cgi.force_redirect', 'cgi.fix_pathinfo', 'fastcgi.impersonate',
        'log_errors', 'error_log', 'memory_limit', 'upload_max_filesize', 'post_max_size',
        'display_errors', 'display_startup_errors', 'html_errors'
    )
    $filtered = New-Object System.Collections.Generic.List[string]
    foreach ($line in $out) {
        $drop = $false
        foreach ($k in $managedKeys) {
            if ($line -match "^\s*;?\s*$([regex]::Escape($k))\s*=") { $drop = $true; break }
        }
        if (-not $drop) { $filtered.Add($line) }
    }
    $out = $filtered

    $logPath = (Join-Path (Split-Path $PhpRoot -Parent | Split-Path -Parent) 'storage\logs\php_errors.log')

    $out.Add('')
    $out.Add('; --- Reporting App runtime configuration ---')
    $out.Add('extension_dir = "' + $extDir + '"')
    foreach ($e in $extensions) { $out.Add("extension=$e") }
    $out.Add('date.timezone = Asia/Baghdad')
    # Required for php-cgi.exe under IIS FastCGI (otherwise blank HTTP 500).
    $out.Add('cgi.force_redirect = 0')
    $out.Add('cgi.fix_pathinfo = 1')
    # impersonate=0 -> php-cgi runs as the app pool identity (granted via IIS_IUSRS),
    # avoiding IUSR access issues.
    $out.Add('fastcgi.impersonate = 0')
    # display_errors MUST be Off under CGI: stray startup warnings printed before the
    # HTTP headers corrupt the response and cause a blank HTTP 500. Errors go to the log.
    $out.Add('display_errors = Off')
    $out.Add('display_startup_errors = Off')
    $out.Add('html_errors = Off')
    $out.Add('log_errors = On')
    $out.Add('error_log = "' + $logPath + '"')
    $out.Add('memory_limit = 512M')
    $out.Add('upload_max_filesize = 32M')
    $out.Add('post_max_size = 32M')

    Set-Content -Path $ini -Value $out -Encoding ASCII
}

function Resolve-AppPoolIdentitySid([string]$AppPoolName) {
    # ApplicationPoolIdentity virtual account; resolvable only after the pool exists.
    try {
        $acc = New-Object System.Security.Principal.NTAccount("IIS AppPool\$AppPoolName")
        return $acc.Translate([System.Security.Principal.SecurityIdentifier])
    } catch {
        return $null
    }
}

function Set-FolderModifyAcl([string]$Path, [System.Security.Principal.IdentityReference]$Identity) {
    $acl = Get-Acl $Path
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        $Identity, 'Modify', 'ContainerInherit,ObjectInherit', 'None', 'Allow'
    )
    $acl.AddAccessRule($rule)
    Set-Acl $Path $acl
}

function Install-IisSite(
    [string]$SiteName,
    [string]$AppPoolName,
    [int]$Port,
    [string]$PublicPath,
    [string]$PhpCgi
) {
    Import-Module WebAdministration -ErrorAction Stop

    $rewrite = Get-WebGlobalModule -Name 'RewriteModule' -ErrorAction SilentlyContinue
    if (-not $rewrite) {
        Write-Warning 'IIS URL Rewrite module not detected. Install it for Laravel routing: https://www.iis.net/downloads/microsoft/url-rewrite'
    }

    if (-not (Test-Path "IIS:\AppPools\$AppPoolName")) {
        New-WebAppPool -Name $AppPoolName | Out-Null
        Set-ItemProperty "IIS:\AppPools\$AppPoolName" -Name managedRuntimeVersion -Value ''
        Set-ItemProperty "IIS:\AppPools\$AppPoolName" -Name processModel.identityType -Value ApplicationPoolIdentity
    }

    $configPath = 'MACHINE/WEBROOT/APPHOST'
    $phpIni = Join-Path (Split-Path $PhpCgi -Parent) 'php.ini'
    $fastCgiFilter = "system.webServer/fastCgi/application[@fullPath='$PhpCgi']"
    $existing = Get-WebConfiguration -Filter $fastCgiFilter -PSPath $configPath -ErrorAction SilentlyContinue
    if (-not $existing) {
        # Add-WebConfigurationProperty with -Name '.' is the reliable way to add a
        # collection element; Add-WebConfiguration silently no-ops on the fastCgi section.
        Add-WebConfigurationProperty -PSPath $configPath -Filter 'system.webServer/fastCgi' -Name '.' -Value @{
            fullPath             = $PhpCgi
            monitorChangesTo     = $phpIni
            activityTimeout      = 600
            requestTimeout       = 600
            instanceMaxRequests  = 10000
        }
    }

    if (Get-Website -Name $SiteName -ErrorAction SilentlyContinue) {
        Write-Host "  Updating existing IIS site '$SiteName' (physical path + app pool)."
        Set-ItemProperty "IIS:\Sites\$SiteName" -Name physicalPath -Value $PublicPath
        Set-ItemProperty "IIS:\Sites\$SiteName" -Name applicationPool -Value $AppPoolName
        $binding = Get-WebBinding -Name $SiteName -Protocol 'http' -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($binding -and $binding.bindingInformation -notmatch ":$Port`:") {
            Remove-WebBinding -Name $SiteName -Protocol 'http' -ErrorAction SilentlyContinue
            New-WebBinding -Name $SiteName -Protocol 'http' -Port $Port -IPAddress '*'
        }
        if ((Get-Website -Name $SiteName).State -ne 'Started') {
            Start-Website -Name $SiteName
        }
    } else {
        New-Website -Name $SiteName -Port $Port -PhysicalPath $PublicPath -ApplicationPool $AppPoolName | Out-Null
    }

    # The handlers section is locked at the server level by default, so register the
    # PHP FastCGI handler in applicationHost.config (global) rather than per-site.
    # Unlock first so the app's web.config can also be processed without 500.19 errors.
    $appcmd = Join-Path $env:WINDIR 'System32\inetsrv\appcmd.exe'
    if (Test-Path $appcmd) {
        & $appcmd unlock config -section:system.webServer/handlers | Out-Null
        & $appcmd unlock config -section:system.webServer/fastCgi | Out-Null
    }

    $handlerName = 'ReportingApp-PHP'
    $existingHandler = Get-WebConfiguration -Filter "system.webServer/handlers/add[@name='$handlerName']" -PSPath $configPath -ErrorAction SilentlyContinue
    if (-not $existingHandler) {
        Add-WebConfigurationProperty -PSPath $configPath -Filter 'system.webServer/handlers' -Name '.' -Value @{
            name             = $handlerName
            path             = '*.php'
            verb             = 'GET,HEAD,POST'
            modules          = 'FastCgiModule'
            scriptProcessor  = "$PhpCgi|"
            resourceType     = 'Either'
        }
    }
}

function Enable-FirewallPort([int]$Port, [string]$SiteName) {
    $ruleName = "Reporting App ($SiteName port $Port)"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "  Firewall rule already exists: $ruleName"
        return
    }
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow `
        -Protocol TCP -LocalPort $Port | Out-Null
    Write-Host "  Opened Windows Firewall TCP port $Port"
}

function Backup-InstallSqlite([string]$InstallPath) {
    $dbDir = Join-Path $InstallPath 'database'
    $names = @(
        'reports-users.sqlite',
        'deliveries-local.sqlite',
        'damages-local.sqlite',
        'operations-tasks.sqlite',
        'accounting-local.sqlite',
        'promotions-local.sqlite',
        'face-id-local.sqlite'
    )
    $timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backupDir = Join-Path $InstallPath "storage\app\sqlite-backups\pre-install-$timestamp"
    $copied = 0
    foreach ($name in $names) {
        $src = Join-Path $dbDir $name
        if (-not (Test-Path $src)) { continue }
        if (-not (Test-Path $backupDir)) {
            New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        }
        Copy-Item $src (Join-Path $backupDir $name) -Force
        $copied++
    }
    if ($copied -gt 0) {
        Write-Host "  Backed up $copied SQLite file(s) to $backupDir" -ForegroundColor DarkGray
    }
}

function Show-BundledSqliteStatus([string]$InstallPath) {
    $manifestPath = Join-Path $InstallPath 'installer\BUNDLED-SQLITE.json'
    $dbDir = Join-Path $InstallPath 'database'
    $expected = @(
        @{ key = 'reports-users.sqlite'; label = 'Users & permissions' },
        @{ key = 'deliveries-local.sqlite'; label = 'Deliveries, governorates & holidays' },
        @{ key = 'damages-local.sqlite'; label = 'Damages entries' },
        @{ key = 'operations-tasks.sqlite'; label = 'Operations tasks' },
        @{ key = 'accounting-local.sqlite'; label = 'Accounting cash & transfers' },
        @{ key = 'promotions-local.sqlite'; label = 'Promotions promoters & schedules' },
        @{ key = 'face-id-local.sqlite'; label = 'Face ID employees & attendance' }
    )

    Write-Step 'Local SQLite databases'
    if (Test-Path $manifestPath) {
        Write-Host "  Release manifest: $manifestPath"
    }
    foreach ($db in $expected) {
        $path = Join-Path $dbDir $db.key
        if (Test-Path $path) {
            $size = (Get-Item $path).Length
            Write-Host ("  OK  {0} ({1:N0} bytes) - {2}" -f $db.key, $size, $db.label) -ForegroundColor Green
        } else {
            Write-Host ("  --  {0} missing - {1} (created on first use)" -f $db.key, $db.label) -ForegroundColor Yellow
        }
    }
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
FACE_ID_SQLITE_DATABASE="$dbPath/database/face-id-local.sqlite"

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

# --- Main ---

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$PackageRoot = Split-Path -Parent $ScriptDir
if (-not (Test-Path (Join-Path $PackageRoot 'artisan'))) {
    $PackageRoot = Get-Location
}
if (-not (Test-Path (Join-Path $PackageRoot 'artisan'))) {
    throw "Cannot find Laravel app (artisan). Run from the release package root."
}
if (-not (Test-Path (Join-Path $PackageRoot 'vendor'))) {
    throw "vendor\ missing. Build the release package first: scripts\build-release.ps1"
}
if (-not (Test-Path (Join-Path $PackageRoot 'public\build'))) {
    throw "public\build\ missing. Build assets first: scripts\build-release.ps1"
}

if ([string]::IsNullOrWhiteSpace($InstallPath)) {
    $InstallPath = $PackageRoot
}

$AssetsRoot = Join-Path $ScriptDir 'assets'
if (-not (Test-Path $AssetsRoot) -and (Test-Path (Join-Path $PackageRoot 'installer\assets'))) {
    $AssetsRoot = Join-Path $PackageRoot 'installer\assets'
}

if (-not [string]::IsNullOrWhiteSpace($ConfigFile)) {
    Write-Step "Loading configuration from $ConfigFile"
    $cfg = Read-InstallConfig -Path $ConfigFile
    if ($cfg.ContainsKey('InstallPath') -and -not [string]::IsNullOrWhiteSpace($cfg['InstallPath'])) {
        $InstallPath = $cfg['InstallPath']
    }
    if ($cfg.ContainsKey('SitePort')) { $SitePort = [int]$cfg['SitePort'] }
    if ($cfg.ContainsKey('SqlHost')) { $SqlHost = $cfg['SqlHost'] }
    if ($cfg.ContainsKey('SqlDatabase')) { $SqlDatabase = $cfg['SqlDatabase'] }
    if ($cfg.ContainsKey('SqlUser')) { $SqlUser = $cfg['SqlUser'] }
    if ($cfg.ContainsKey('SqlPassword')) { $SqlPassword = $cfg['SqlPassword'] }
    if ($cfg.ContainsKey('AdminUsername')) { $AdminUsername = $cfg['AdminUsername'] }
    if ($cfg.ContainsKey('AdminPassword')) { $AdminPassword = $cfg['AdminPassword'] }
    if ($cfg.ContainsKey('AppUrl')) { $AppUrl = $cfg['AppUrl'] }
    $Quiet = $true
}

$logDir = Join-Path $InstallPath 'storage\logs'
New-Item -ItemType Directory -Path $logDir -Force | Out-Null
$installLog = Join-Path $logDir ("install-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))
Start-Transcript -Path $installLog -Force | Out-Null

Write-Host ""
Write-Host "Reporting App - Windows Installer" -ForegroundColor Green
Write-Host "=================================" -ForegroundColor Green
Write-Host "Log file: $installLog" -ForegroundColor DarkGray

$installInPlace = ($InstallPath.TrimEnd('\') -eq $PackageRoot.TrimEnd('\'))

if (-not $SkipIis) {
    Write-Step 'Enabling IIS prerequisites'
    Enable-IisWindowsFeatures
}

$odbcOk = $false
if ($SkipOdbc) {
    $odbcOk = [bool](Get-InstalledSqlOdbcDriver)
    Write-Host "Skipping ODBC install (-SkipOdbc). Driver present: $odbcOk"
} else {
    Write-Step 'Checking / installing Visual C++ x64 runtime'
    try {
        [void](Install-VcRedist -AssetsRoot $AssetsRoot -AllowDownload:$AllowDownload)
    } catch {
        Write-Warning "Visual C++ runtime step error (continuing): $($_.Exception.Message)"
    }

    Write-Step 'Checking / installing ODBC Driver 18'
    try {
        $odbcOk = [bool](Install-OdbcDriver18 -AssetsRoot $AssetsRoot)
    } catch {
        Write-Warning "ODBC step error (continuing): $($_.Exception.Message)"
        $odbcOk = $false
    }
}

if (-not $installInPlace) {
    $sqliteExclude = @(
        'reports-users.sqlite',
        'deliveries-local.sqlite',
        'damages-local.sqlite',
        'operations-tasks.sqlite',
        'accounting-local.sqlite',
        'promotions-local.sqlite',
        'face-id-local.sqlite'
    )
    $envPathForUpgrade = Join-Path $InstallPath '.env'
    $isUpgradeCopy = Test-Path $envPathForUpgrade

    Write-Step "Copying application to $InstallPath"
    if (Test-Path $InstallPath) {
        if ($isUpgradeCopy) {
            Write-Host '  Existing installation detected - upgrading in place.' -ForegroundColor Yellow
            Write-Host '  Preserving: .env, database\*.sqlite, storage\app\sqlite-backups\' -ForegroundColor Yellow
            Backup-InstallSqlite -InstallPath $InstallPath
        } elseif (-not $Quiet) {
            $confirm = Read-Host 'Folder exists but no .env was found. Overwrite entire folder? (y/N)'
            if ($confirm -notmatch '^y') { throw 'Installation cancelled.' }
            Remove-Item $InstallPath -Recurse -Force
            New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
        } else {
            throw "Install path exists without .env: $InstallPath. Remove the folder or run interactively."
        }
    } else {
        New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
    }

    $robocopyArgs = @(
        $PackageRoot, $InstallPath,
        '/MIR',
        '/XD', 'node_modules', '.git', 'dist', 'storage\app\sqlite-backups',
        '/XF', '.env', 'sqlite-auto-backup.json', 'pda-auto-sync.json'
    ) + $sqliteExclude + @('/NFL', '/NDL', '/NJH', '/NJS', '/nc', '/ns', '/np')
    & robocopy @robocopyArgs | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "File copy failed (robocopy exit $LASTEXITCODE)" }
} else {
    Write-Step "Configuring application in place at $InstallPath"
}

$RuntimePhpDir = Join-Path $InstallPath 'runtime\php'
$phpExe = Ensure-BundledPhp -AssetsRoot $AssetsRoot -RuntimePhpDir $RuntimePhpDir -AllowDownload:$AllowDownload
Install-SqlPhpDrivers -ExtDir (Join-Path $RuntimePhpDir 'ext') -AllowDownload:$AllowDownload
Initialize-PhpIni -PhpRoot $RuntimePhpDir
$phpCgi = Join-Path $RuntimePhpDir 'php-cgi.exe'

$envPath = Join-Path $InstallPath '.env'
$isUpdateInstall = Test-Path $envPath

Write-Step 'Collecting configuration'
if ([string]::IsNullOrWhiteSpace($SqlHost)) { $SqlHost = Read-Required 'SQL Server host' '10.10.10.250' }
if ($isUpdateInstall) {
    if ([string]::IsNullOrWhiteSpace($SqlPassword)) {
        Write-Host '  Upgrade: keeping SQL credentials from existing .env (installer SQL password left blank).'
    }
    if ([string]::IsNullOrWhiteSpace($AdminPassword)) {
        Write-Host '  Upgrade: keeping admin credentials from existing .env (installer admin password left blank).'
    }
    $envAppUrl = Read-EnvValue -EnvPath $envPath -Key 'APP_URL'
    if (-not [string]::IsNullOrWhiteSpace($envAppUrl)) {
        if ($AppUrl -ne $envAppUrl) {
            Write-Host "  Upgrade: using APP_URL from existing .env ($envAppUrl)."
        }
        $AppUrl = $envAppUrl
        if ($AppUrl -match ':(\d+)') {
            $SitePort = [int]$Matches[1]
        }
    } elseif ([string]::IsNullOrWhiteSpace($AppUrl)) {
        $AppUrl = Read-EnvValue -EnvPath $envPath -Key 'APP_URL'
    }
} else {
    if ([string]::IsNullOrWhiteSpace($SqlPassword)) { $SqlPassword = Read-Secret 'SQL Server password' $SqlPassword }
    if ([string]::IsNullOrWhiteSpace($AdminPassword)) { $AdminPassword = Read-Secret 'Bootstrap admin password' $AdminPassword }
}
if ([string]::IsNullOrWhiteSpace($AppUrl)) { $AppUrl = "http://localhost:$SitePort" }

if ($isUpdateInstall) {
    Write-Step 'Keeping existing .env (upgrade install)'
    $addedEnvKeys = Ensure-EnvSqliteKeys -EnvPath $envPath -InstallPath $InstallPath
    if (Get-CollectionLength $addedEnvKeys -gt 0) {
        Write-Host ("  Added missing .env keys: {0}" -f ($addedEnvKeys -join ', ')) -ForegroundColor Yellow
    }
} else {
    Write-Step 'Writing .env'
    Write-EnvFile -Path $envPath -AppUrl $AppUrl -SqlHost $SqlHost `
        -SqlDatabase $SqlDatabase -SqlUser $SqlUser -SqlPassword $SqlPassword `
        -InstallPath $InstallPath -AdminUsername $AdminUsername -AdminPassword $AdminPassword
    if (-not (Test-Path $envPath)) {
        throw ".env was not created at $envPath. Run installer\create-env.cmd to recover."
    }
}

Write-Step 'Laravel setup'
Push-Location $InstallPath
if ($isUpdateInstall) {
    Write-Host '  Upgrade: clearing cached config/routes/views before rebuild.'
    & $phpExe artisan config:clear
    & $phpExe artisan route:clear
    & $phpExe artisan view:clear
}
$envText = if (Test-Path $envPath) { Get-Content $envPath -Raw } else { '' }
if ($envText -match 'APP_KEY=base64:[A-Za-z0-9+/=]{20,}') {
    Write-Host '  APP_KEY already set - skipping key:generate'
} else {
    & $phpExe artisan key:generate --force
}
& $phpExe artisan storage:link --force
& $phpExe artisan config:cache
& $phpExe artisan route:cache
& $phpExe artisan view:cache
Pop-Location

Write-Step 'Setting folder permissions'
# IIS_IUSRS (well-known SID S-1-5-32-568) contains all application pool identities,
# resolves regardless of locale, and exists before the app pool is created.
$identities = @()
$identities += (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-568'))
$poolSid = Resolve-AppPoolIdentitySid -AppPoolName $AppPoolName
if ($poolSid) { $identities += $poolSid }

# Read & Execute on the WHOLE install tree: under "Program Files" the IIS identity has
# no read access by default, so php-cgi cannot read the app files or runtime\php. Without
# this, IIS returns a blank HTTP 500.
foreach ($id in $identities) {
    try {
        $acl = Get-Acl $InstallPath
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            $id, 'ReadAndExecute', 'ContainerInherit,ObjectInherit', 'None', 'Allow'
        )
        $acl.AddAccessRule($rule)
        Set-Acl $InstallPath $acl
    } catch { Write-Warning "Could not grant read on ${InstallPath}: $($_.Exception.Message)" }
}

# Modify on the writable folders.
foreach ($sub in @('storage', 'bootstrap\cache', 'database')) {
    $full = Join-Path $InstallPath $sub
    New-Item -ItemType Directory -Path $full -Force | Out-Null
    foreach ($id in $identities) {
        try { Set-FolderModifyAcl -Path $full -Identity $id }
        catch { Write-Warning "Could not set ACL on $full for $($id): $($_.Exception.Message)" }
    }
}

Show-BundledSqliteStatus -InstallPath $InstallPath

if (-not $SkipIis) {
    Write-Step 'Configuring IIS'
    try {
        Import-Module WebAdministration -ErrorAction Stop
        Install-UrlRewrite -AssetsRoot $AssetsRoot
    } catch {
        Write-Warning "IIS module not ready yet: $($_.Exception.Message)"
    }
    Install-IisSite -SiteName $SiteName -AppPoolName $AppPoolName -Port $SitePort `
        -PublicPath (Join-Path $InstallPath 'public') -PhpCgi $phpCgi
    try {
        Enable-FirewallPort -Port $SitePort -SiteName $SiteName
    } catch {
        Write-Warning "Could not add firewall rule for port ${SitePort}: $($_.Exception.Message)"
    }
    iisreset /start | Out-Null
    Write-Step 'Verifying IIS + Laravel routing'
    Test-IisWebSetup -SiteName $SiteName -Port $SitePort -InstallPath $InstallPath -AppUrl $AppUrl
}

if (-not $SkipDbHealth) {
    Write-Step 'Testing database connection'
    Push-Location $InstallPath
    & $phpExe artisan reports:db-health
    Pop-Location
} else {
    Write-Host 'Skipped database health check (-SkipDbHealth).'
}

Write-Host ""
Write-Host "Installation complete." -ForegroundColor Green
Write-Host "  URL:      $AppUrl"
Write-Host "  Login:    $AdminUsername"
Write-Host "  App path: $InstallPath"
Write-Host "  .env:     $envPath"
if (-not $odbcOk -and -not (Get-InstalledSqlOdbcDriver)) {
    Write-Host ""
    Write-Host "WARNING: No SQL ODBC driver detected. The site will load, but SQL Server" -ForegroundColor Yellow
    Write-Host "reports will fail until ODBC Driver 18 (or 17) is installed." -ForegroundColor Yellow
    Write-Host "Install it manually from installer\assets\msodbcsql18.msi, then run:" -ForegroundColor Yellow
    Write-Host "  runtime\php\php.exe artisan reports:db-health" -ForegroundColor Yellow
}
if (-not (Test-Path $envPath)) {
    Write-Host ""
    Write-Host "ERROR: .env is missing. Run installer\create-env.cmd from the install folder." -ForegroundColor Red
    exit 1
}
Write-Host ""
Write-Host "If .env is ever missing, run: installer\create-env.cmd" -ForegroundColor DarkGray
Write-Host ""
if (-not $SkipIis) {
    Write-Host "Open $AppUrl/login in your browser." -ForegroundColor Yellow
}
try { Stop-Transcript | Out-Null } catch {}
if ($script:PauseOnExit) { Read-Host 'Press Enter to close' | Out-Null }
