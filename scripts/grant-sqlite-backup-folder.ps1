# Grants Modify permission on a backup folder for Reporting App (IIS + scheduled task).
# Run as Administrator.
#
# Example:
#   powershell -ExecutionPolicy Bypass -File "C:\Program Files\ReportingApp\scripts\grant-sqlite-backup-folder.ps1" -Folder "C:\Backups\ReportingApp"

param(
    [Parameter(Mandatory = $true)]
    [string]$Folder,
    [string]$AppPoolName = 'ReportingApp'
)

$ErrorActionPreference = 'Stop'

$Folder = $Folder.Trim().TrimEnd('\')
if ($Folder -eq '') {
    Write-Error 'Folder path is required.'
}

if (-not (Test-Path $Folder)) {
    New-Item -ItemType Directory -Path $Folder -Force | Out-Null
}

$rights = [System.Security.AccessControl.FileSystemRights]::Modify
$inherit = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
$propagation = [System.Security.AccessControl.PropagationFlags]::None

$accounts = @(
    'IIS_IUSRS',
    'SYSTEM',
    "IIS AppPool\$AppPoolName"
)

foreach ($account in $accounts) {
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        $account,
        $rights,
        $inherit,
        $propagation,
        [System.Security.AccessControl.AccessControlType]::Allow
    )
    $acl = Get-Acl $Folder
    $acl.AddAccessRule($rule)
    Set-Acl -Path $Folder -AclObject $acl
    Write-Host "Granted Modify to $account on $Folder"
}

Write-Host ''
Write-Host 'Use this folder in Settings -> SQLite backups -> Daily auto backup.'
