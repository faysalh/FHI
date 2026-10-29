#Requires -Version 5.1
<#
.SYNOPSIS
    Enables HTTPS for Reporting App on IIS (self-signed or existing cert).

.DESCRIPTION
    - Backs up .env
    - Creates a self-signed certificate (default) or uses -CertificateThumbprint
    - Adds IIS https binding
    - Opens Windows Firewall for the HTTPS port
    - Updates APP_URL and refreshes Laravel config cache

.EXAMPLE
    .\installer\enable-https.ps1 -InstallPath "C:\Program Files\ReportingApp" -IpAddress "10.10.10.250"

.EXAMPLE
    .\installer\enable-https.ps1 -InstallPath "C:\Program Files\ReportingApp" -HttpsPort 8443 -IpAddress "10.10.10.250"
#>
param(
    [string]$InstallPath = 'C:\Program Files\ReportingApp',
    [string]$SiteName = 'ReportingApp',
    [string]$IpAddress = '10.10.10.250',
    [string]$DnsName = '',
    [int]$HttpsPort = 0,
    [string]$CertificateThumbprint = '',
    [switch]$KeepHttpBinding = $true,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Test-PortListening([int]$Port) {
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    return $null -ne $conn -and $conn.Count -gt 0
}

function Enable-FirewallPort([int]$Port, [string]$Label) {
    $ruleName = "Reporting App HTTPS ($Label port $Port)"
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "  Firewall rule already exists: $ruleName"
        return
    }
    New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow `
        -Protocol TCP -LocalPort $Port | Out-Null
    Write-Host "  Opened Windows Firewall TCP port $Port"
}

function Set-EnvAppUrl([string]$EnvPath, [string]$AppUrl) {
    $lines = Get-Content $EnvPath -ErrorAction Stop
    $found = $false
    $out = foreach ($line in $lines) {
        if ($line -match '^\s*APP_URL=') {
            $found = $true
            "APP_URL=$AppUrl"
        } else {
            $line
        }
    }
    if (-not $found) {
        $out += "APP_URL=$AppUrl"
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding $false
    [System.IO.File]::WriteAllLines($EnvPath, [string[]]$out, $utf8NoBom)
}

function Get-AdCsWebEnrollmentUrl {
    try {
        $ca = Get-Service -Name CertSvc -ErrorAction SilentlyContinue
        if ($ca -and $ca.Status -eq 'Running') {
            return $true
        }
    } catch { }
    return $false
}

# --- Admin check ---
$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator
)
if (-not $isAdmin) {
    throw 'Run this script as Administrator.'
}

$InstallPath = (Resolve-Path $InstallPath).Path
$envPath = Join-Path $InstallPath '.env'
$phpExe = Join-Path $InstallPath 'runtime\php\php.exe'

if (-not (Test-Path $envPath)) {
    throw ".env not found: $envPath"
}
if (-not (Test-Path $phpExe)) {
    throw "Bundled PHP not found: $phpExe"
}

Write-Host ''
Write-Host 'Reporting App - Enable HTTPS' -ForegroundColor Green
Write-Host "Install path: $InstallPath"
Write-Host "IIS site:     $SiteName"

Import-Module WebAdministration -ErrorAction Stop

if (-not (Get-Website -Name $SiteName -ErrorAction SilentlyContinue)) {
    throw "IIS site '$SiteName' not found. Check site name with: Get-Website"
}

# --- Pick HTTPS port ---
if ($HttpsPort -le 0) {
    Write-Step 'Checking free HTTPS port'
    if (-not (Test-PortListening -Port 443)) {
        $HttpsPort = 443
        Write-Host '  Using port 443 (not in use for listening)'
    } elseif (-not (Test-PortListening -Port 8443)) {
        $HttpsPort = 8443
        Write-Host '  Port 443 is in use - using 8443'
    } else {
        throw 'Both ports 443 and 8443 appear to be in use. Pass -HttpsPort explicitly.'
    }
} else {
    Write-Host "  Using HTTPS port: $HttpsPort"
}

# --- Backup .env ---
Write-Step 'Backing up .env'
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backupDir = Join-Path $InstallPath "storage\app\sqlite-backups\pre-https-$timestamp"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Copy-Item $envPath (Join-Path $backupDir '.env') -Force
Write-Host "  Saved to $backupDir"

# --- Certificate ---
Write-Step 'Certificate'
$cert = $null

if ($CertificateThumbprint -ne '') {
    $cert = Get-ChildItem "Cert:\LocalMachine\My\$CertificateThumbprint" -ErrorAction SilentlyContinue
    if (-not $cert) {
        throw "Certificate not found in LocalMachine\My: $CertificateThumbprint"
    }
    Write-Host "  Using existing certificate: $($cert.Subject)"
} else {
    $dnsNames = @()
    if ($DnsName -ne '') { $dnsNames += $DnsName }
    if ($IpAddress -ne '') { $dnsNames += $IpAddress }
    if ($dnsNames.Count -eq 0) {
        throw 'Provide -IpAddress and/or -DnsName for the certificate.'
    }

    $existingHttps = Get-WebBinding -Name $SiteName -Protocol 'https' -ErrorAction SilentlyContinue |
        Where-Object { $_.bindingInformation -like "*:${HttpsPort}:*" }
    if ($existingHttps -and -not $Force) {
        $hash = $existingHttps.certificateHash
        if ($hash) {
            $cert = Get-ChildItem Cert:\LocalMachine\My | Where-Object { $_.Thumbprint -eq $hash } | Select-Object -First 1
            if ($cert) {
                Write-Host "  HTTPS binding already exists on port $HttpsPort - reusing cert $($cert.Subject)"
            }
        }
    }

    if (-not $cert) {
        if (Get-AdCsWebEnrollmentUrl) {
            Write-Host '  AD Certificate Services detected. Using self-signed for this run.' -ForegroundColor Yellow
            Write-Host '  For AD CS-issued certs, request via certsrv and re-run with -CertificateThumbprint.' -ForegroundColor Yellow
        }

        $friendlyName = "ReportingApp-HTTPS-$($dnsNames[0])"
        Write-Host "  Creating self-signed certificate for: $($dnsNames -join ', ')"
        $cert = New-SelfSignedCertificate `
            -DnsName $dnsNames `
            -CertStoreLocation 'Cert:\LocalMachine\My' `
            -FriendlyName $friendlyName `
            -KeyExportPolicy Exportable `
            -NotAfter (Get-Date).AddYears(5)
        Write-Host "  Thumbprint: $($cert.Thumbprint)"
    }
}

# --- IIS HTTPS binding ---
Write-Step 'IIS HTTPS binding'
$bindingInfo = "*:${HttpsPort}:"
$existing = Get-WebBinding -Name $SiteName -Protocol 'https' -ErrorAction SilentlyContinue |
    Where-Object { $_.bindingInformation -eq $bindingInfo }

if ($existing -and -not $Force) {
    Write-Host "  HTTPS binding already present: $bindingInfo"
} else {
    if ($existing) {
        Remove-WebBinding -Name $SiteName -Protocol 'https' -BindingInformation $bindingInfo
    }
    New-WebBinding -Name $SiteName -Protocol 'https' -Port $HttpsPort -IPAddress '*' -SslFlags 0
    Write-Host "  Added HTTPS binding: $bindingInfo"
}

$bindingPath = "IIS:\SslBindings\0.0.0.0!$HttpsPort"
if (Test-Path $bindingPath) {
    Remove-Item $bindingPath -Force
}
New-Item -Path $bindingPath -Thumbprint $cert.Thumbprint -SSLFlags 0 | Out-Null
Write-Host '  Bound certificate to SSL port'

if ($KeepHttpBinding) {
    $httpBindings = Get-WebBinding -Name $SiteName -Protocol 'http' -ErrorAction SilentlyContinue
    if ($httpBindings) {
        Write-Host '  Existing HTTP binding(s) kept (bookmarks on port 8090 still work).'
    }
}

# --- Firewall ---
Write-Step 'Firewall'
Enable-FirewallPort -Port $HttpsPort -Label $SiteName

# --- APP_URL ---
Write-Step 'Updating APP_URL'
$appUrl = if ($HttpsPort -eq 443) {
    "https://$IpAddress"
} else {
    "https://${IpAddress}:$HttpsPort"
}
if ($DnsName -ne '') {
    $appUrl = if ($HttpsPort -eq 443) { "https://$DnsName" } else { "https://${DnsName}:$HttpsPort" }
    Write-Host "  Using hostname in APP_URL: $appUrl"
} else {
    Write-Host "  APP_URL=$appUrl"
}
Set-EnvAppUrl -EnvPath $envPath -AppUrl $appUrl

# --- Laravel cache ---
Write-Step 'Refreshing Laravel config'
Push-Location $InstallPath
& $phpExe artisan config:clear
& $phpExe artisan config:cache
Pop-Location

Write-Step 'Restarting IIS'
iisreset /restart | Out-Null

Write-Host ''
Write-Host 'HTTPS enabled successfully.' -ForegroundColor Green
Write-Host ''
Write-Host 'Test URLs (accept certificate warning once if self-signed):' -ForegroundColor Yellow
Write-Host "  Login:    $appUrl/login"
Write-Host "  Face ID:  $appUrl/reports/face-id"
Write-Host ('  Kiosk:    ' + $appUrl + '/attendance/{token}  (copy token from Face ID tab)')
Write-Host ''
Write-Host 'On Android/iPhone: open the login URL, tap Advanced -> Proceed, then use Face ID kiosk link.' -ForegroundColor DarkGray
Write-Host 'See installer\HTTPS-INTERNAL-NETWORK.md for trusted internal CA setup.' -ForegroundColor DarkGray
Write-Host ''
