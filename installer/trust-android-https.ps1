#Requires -Version 5.1
#Requires -RunAsAdministrator
<#
.SYNOPSIS
    Issues an Android-trusted HTTPS certificate for the Reporting App server.

.DESCRIPTION
    Xiaomi and other Android phones reject the current self-signed website certificate
    ("private key required") because it is not a certificate authority.

    This script:
      - Creates a local certificate authority (public file only is copied to the phone)
      - Signs a website certificate for the server IP and reporting.local
      - Binds that website certificate in IIS
      - Does not change .env, SQLite, or the SQL Server database

    On the phone, install only the Root CA file:
      http://<ip>:8090/ReportingApp-RootCA.cer
    Settings - search "CA certificate" - Install a certificate - CA certificate.

.EXAMPLE
    .\installer\trust-android-https.ps1 -IpAddress 10.10.10.250
#>
param(
    [string]$InstallPath = 'C:\Program Files\ReportingApp',
    [string]$SiteName = 'ReportingApp',
    [string]$IpAddress = '10.10.10.250',
    [string]$DnsName = 'reporting.local',
    [int]$HttpPort = 8090
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Test-IsCertificateAuthority([System.Security.Cryptography.X509Certificates.X509Certificate2]$Certificate) {
    foreach ($ext in $Certificate.Extensions) {
        if ($ext.Oid.Value -eq '2.5.29.19' -and $ext.Format($false) -match 'Subject Type\s*=\s*CA') {
            return $true
        }
    }
    return $false
}

Write-Host ''
Write-Host 'Reporting App - Android HTTPS trust' -ForegroundColor Green
Write-Host "Install path: $InstallPath"
Write-Host "IIS site:     $SiteName"

Import-Module WebAdministration -ErrorAction Stop
if (-not (Get-Website -Name $SiteName -ErrorAction SilentlyContinue)) {
    throw "IIS site '$SiteName' was not found."
}

$httpsBinding = Get-WebBinding -Name $SiteName -Protocol 'https' -ErrorAction SilentlyContinue |
    Select-Object -First 1
if (-not $httpsBinding) {
    throw "Site '$SiteName' has no HTTPS binding. Run installer\enable-https.ps1 first."
}
if ($httpsBinding.bindingInformation -notmatch ':(\d+):') {
    throw "Could not read the HTTPS port from $($httpsBinding.bindingInformation)"
}
$httpsPort = [int]$Matches[1]
Write-Host "HTTPS port:   $httpsPort"

Write-Step 'Certificate authority'
$caFriendlyName = 'Reporting App Local CA'
$ca = Get-ChildItem Cert:\LocalMachine\My |
    Where-Object { $_.FriendlyName -eq $caFriendlyName -and (Test-IsCertificateAuthority $_) } |
    Sort-Object NotBefore -Descending |
    Select-Object -First 1

if ($ca) {
    Write-Host "  Reusing $($ca.Subject)"
    Write-Host "  Thumbprint: $($ca.Thumbprint)"
} else {
    $ca = New-SelfSignedCertificate `
        -Type Custom `
        -Subject 'CN=Reporting App Local CA' `
        -FriendlyName $caFriendlyName `
        -CertStoreLocation 'Cert:\LocalMachine\My' `
        -KeyAlgorithm RSA `
        -KeyLength 2048 `
        -HashAlgorithm SHA256 `
        -KeyExportPolicy Exportable `
        -KeyUsage CertSign, CRLSign, DigitalSignature `
        -NotAfter (Get-Date).AddYears(10) `
        -TextExtension @('2.5.29.19={critical}{text}CA=true&pathlength=0')
    Write-Host "  Created $($ca.Subject)"
    Write-Host "  Thumbprint: $($ca.Thumbprint)"
}

if (-not (Test-IsCertificateAuthority $ca)) {
    throw 'The certificate authority is missing CA=true, so Android will reject it.'
}

$rootStore = New-Object System.Security.Cryptography.X509Certificates.X509Store('Root', 'LocalMachine')
$rootStore.Open('ReadWrite')
try {
    $alreadyTrusted = $rootStore.Certificates | Where-Object { $_.Thumbprint -eq $ca.Thumbprint }
    if (-not $alreadyTrusted) {
        $rootStore.Add($ca)
        Write-Host '  Added the authority to the server Trusted Root store'
    }
} finally {
    $rootStore.Close()
}

Write-Step 'Website certificate'
$serverCert = New-SelfSignedCertificate `
    -Type Custom `
    -Subject "CN=$DnsName" `
    -FriendlyName "ReportingApp-HTTPS-$IpAddress" `
    -Signer $ca `
    -CertStoreLocation 'Cert:\LocalMachine\My' `
    -KeyAlgorithm RSA `
    -KeyLength 2048 `
    -HashAlgorithm SHA256 `
    -KeyExportPolicy Exportable `
    -KeyUsage DigitalSignature, KeyEncipherment `
    -NotAfter (Get-Date).AddYears(5) `
    -TextExtension @(
        '2.5.29.37={text}1.3.6.1.5.5.7.3.1',
        "2.5.29.17={text}DNS=$DnsName&IPAddress=$IpAddress"
    )
Write-Host "  Created $($serverCert.Subject)"
Write-Host "  Thumbprint: $($serverCert.Thumbprint)"

Write-Step 'IIS binding'
$bindingPath = "IIS:\SslBindings\0.0.0.0!$httpsPort"
if (Test-Path $bindingPath) {
    Remove-Item $bindingPath -Force
}
New-Item -Path $bindingPath -Thumbprint $serverCert.Thumbprint -SSLFlags 0 | Out-Null
Write-Host "  HTTPS port $httpsPort now uses the new website certificate"

Write-Step 'File for the phone'
$cerName = 'ReportingApp-RootCA.cer'
$publicCer = Join-Path $InstallPath "public\$cerName"
$installerCer = Join-Path $InstallPath "installer\$cerName"
Export-Certificate -Cert $ca -FilePath $publicCer -Force | Out-Null
Copy-Item $publicCer $installerCer -Force
Write-Host "  $publicCer"
Write-Host "  $installerCer"

$mime = Get-WebConfigurationProperty `
    -PSPath "IIS:\Sites\$SiteName" `
    -Filter 'system.webServer/staticContent/mimeMap' `
    -Name '.' `
    -ErrorAction SilentlyContinue |
    Where-Object { $_.fileExtension -eq '.cer' }
if (-not $mime) {
    Add-WebConfigurationProperty `
        -PSPath "IIS:\Sites\$SiteName" `
        -Filter 'system.webServer/staticContent' `
        -Name '.' `
        -Value @{ fileExtension = '.cer'; mimeType = 'application/x-x509-ca-cert' }
    Write-Host '  Allowed .cer downloads in IIS'
}

Restart-WebAppPool -Name $SiteName -ErrorAction SilentlyContinue

Write-Host ''
Write-Host 'Ready for the Xiaomi phone.' -ForegroundColor Green
Write-Host "1. On the phone open http://${IpAddress}:$HttpPort/$cerName"
Write-Host '2. Settings - search CA certificate - Install a certificate - CA certificate'
Write-Host '3. Choose ReportingApp-RootCA.cer'
Write-Host "4. Open https://$IpAddress/"
Write-Host ''
Write-Host 'Do not install a VPN, Wi-Fi, or user certificate. Those ask for a private key.' -ForegroundColor Yellow
Write-Host ''
