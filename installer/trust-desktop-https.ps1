#Requires -Version 5.1
<#
.SYNOPSIS
    Trusts the Reporting App HTTPS certificate on a Windows PC.

.DESCRIPTION
    Installs the public Reporting App Local CA into the Windows trust store.
    Chrome and Edge then accept https://10.10.10.250 without a warning.
    The file is a public certificate only. It does not contain a private key,
    and it does not change the server, SQLite, or SQL Server.

.EXAMPLE
    .\trust-desktop-https.ps1
#>
param(
    [string]$CerPath = '',
    [string]$HostName = '10.10.10.250',
    [int]$Port = 443,
    [switch]$NoPause,
    [switch]$NoElevate
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-IsCertificateAuthority([System.Security.Cryptography.X509Certificates.X509Certificate2]$Certificate) {
    foreach ($ext in $Certificate.Extensions) {
        if ($ext.Oid.Value -eq '2.5.29.19' -and $ext.Format($false) -match 'Subject Type\s*=\s*CA') {
            return $true
        }
    }
    return $false
}

function Get-CertificateSanText([System.Security.Cryptography.X509Certificates.X509Certificate2]$Certificate) {
    foreach ($ext in $Certificate.Extensions) {
        if ($ext.Oid.Value -eq '2.5.29.17') {
            return $ext.Format($false)
        }
    }
    return ''
}

if (-not (Test-IsAdministrator) -and -not $NoElevate) {
    Write-Host 'Requesting Administrator permission...'
    $argumentLine = '-NoProfile -ExecutionPolicy Bypass -File "{0}" -HostName "{1}" -Port {2}' -f $PSCommandPath, $HostName, $Port
    if (-not [string]::IsNullOrWhiteSpace($CerPath)) {
        $argumentLine += ' -CerPath "{0}"' -f $CerPath
    }
    try {
        Start-Process -FilePath "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Verb RunAs -ArgumentList $argumentLine | Out-Null
    } catch {
        Write-Host $_.Exception.Message -ForegroundColor Red
        if (-not $NoPause) { Read-Host 'Press Enter to close' }
        exit 1
    }
    exit 0
}

trap {
    Write-Host $_.Exception.Message -ForegroundColor Red
    if (-not $NoPause) { Read-Host 'Press Enter to close' }
    exit 1
}

if ([string]::IsNullOrWhiteSpace($CerPath)) {
    $CerPath = Join-Path $PSScriptRoot 'ReportingApp-RootCA.cer'
}
if (-not (Test-Path -LiteralPath $CerPath)) {
    throw "Certificate file not found: $CerPath"
}

Write-Host ''
Write-Host 'Reporting App - trust HTTPS on this PC' -ForegroundColor Green
Write-Host "Certificate: $CerPath"
Write-Host "Site:        https://${HostName}/"

Write-Step 'Check certificate'
$ca = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($CerPath)
if ($ca.HasPrivateKey) {
    throw 'This file contains a private key. Desktop PCs must install the public CA file only.'
}
if ($ca.Subject -ne 'CN=Reporting App Local CA') {
    throw "Unexpected certificate subject: $($ca.Subject)"
}
if (-not (Test-IsCertificateAuthority $ca)) {
    throw 'This certificate is not a certificate authority, so Windows cannot use it to trust the site.'
}
if ($ca.NotAfter -lt (Get-Date)) {
    throw "This CA expired on $($ca.NotAfter)."
}
Write-Host "  Subject:    $($ca.Subject)"
Write-Host "  Thumbprint: $($ca.Thumbprint)"
Write-Host "  Expires:    $($ca.NotAfter)"

$isAdmin = Test-IsAdministrator
if ($isAdmin) {
    $storeLocation = 'LocalMachine'
    $scopeLabel = 'every user on this PC'
} else {
    $storeLocation = 'CurrentUser'
    $scopeLabel = 'the current Windows user only'
}

Write-Step "Install for $scopeLabel"
$store = New-Object System.Security.Cryptography.X509Certificates.X509Store('Root', $storeLocation)
$store.Open('ReadWrite')
try {
    $already = @($store.Certificates | Where-Object { $_.Thumbprint -eq $ca.Thumbprint })
    if ($already.Count -gt 0) {
        Write-Host '  Already trusted. No change made.'
    } else {
        $store.Add($ca)
        Write-Host "  Added to $storeLocation\Root"
    }
} finally {
    $store.Close()
}

Write-Step "Check https://$HostName"
$tcp = New-Object System.Net.Sockets.TcpClient
try {
    $tcp.Connect($HostName, $Port)
    $ssl = New-Object System.Net.Security.SslStream($tcp.GetStream(), $false, { param($sender, $certificate, $chain, $errors) $true })
    $ssl.AuthenticateAsClient($HostName)
    $leaf = New-Object System.Security.Cryptography.X509Certificates.X509Certificate2($ssl.RemoteCertificate)
} finally {
    if ($tcp.Connected) { $tcp.Close() }
}

$chain = New-Object System.Security.Cryptography.X509Certificates.X509Chain
$chain.ChainPolicy.RevocationMode = [System.Security.Cryptography.X509Certificates.X509RevocationMode]::NoCheck
$trusted = $chain.Build($leaf)
$san = Get-CertificateSanText $leaf
$nameOk = $san -match [regex]::Escape($HostName)
$issuedByUs = $leaf.Issuer -eq $ca.Subject

Write-Host "  Presented: $($leaf.Subject)"
Write-Host "  Issued by: $($leaf.Issuer)"
if (-not $issuedByUs) {
    throw 'The site is using a certificate from a different issuer. This CA will not make that connection trusted.'
}
if (-not $nameOk) {
    throw "The site certificate does not include $HostName."
}
if (-not $trusted) {
    $flags = @($chain.ChainStatus | ForEach-Object { $_.Status }) -join ', '
    throw "Windows still does not trust the connection ($flags)."
}

Write-Host ''
Write-Host "Trusted. Open https://$HostName/ in Chrome or Edge." -ForegroundColor Green
if (-not $isAdmin) {
    Write-Host 'This was installed for the current user. Run this script as Administrator so every account on the PC trusts the site.' -ForegroundColor Yellow
}
Write-Host 'Firefox keeps its own certificate store. Use Chrome or Edge, or import the same CA file in Firefox settings.' -ForegroundColor Yellow
Write-Host ''
if (-not $NoPause) {
    Read-Host 'Press Enter to close'
}
