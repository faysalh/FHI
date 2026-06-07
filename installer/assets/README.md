# Installer runtime assets (offline bundle)

For a **fully offline** setup executable, place these files before running `scripts/build-release.ps1 -BundleRuntime`:

## 1. PHP 8.3 NTS x64 (required)

Extract the official ZIP so this path exists:

```
installer/assets/php/php.exe
installer/assets/php/php.ini
```

Download: https://windows.php.net/download/  
Choose **VS16 x64 Non Thread Safe** ZIP for PHP 8.3.x.

After extract, copy `php.ini-production` to `php.ini` and ensure these extensions are enabled:

```
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=gd
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
extension=sqlsrv
extension=pdo_sqlsrv
```

## 2. SQL Server PHP drivers (required)

Copy matching DLLs from [Microsoft PHP drivers](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server) into:

```
installer/assets/php/ext/php_sqlsrv.dll
installer/assets/php/ext/php_pdo_sqlsrv.dll
```

Version must match PHP 8.3 NTS x64.

## 3. ODBC Driver 18 for SQL Server (required)

Place the redistributable MSI here:

```
installer/assets/msodbcsql18.msi
```

Download: https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server

## 4. IIS URL Rewrite 2.x (required for Laravel routes)

Place the MSI here:

```
installer/assets/rewrite_amd64.msi
```

Download: https://www.iis.net/downloads/microsoft/url-rewrite

---

If these folders are empty, the installer will try to **download** PHP, ODBC, and SQL drivers when `-AllowDownload` is used (needs internet on the target server).

For a fully offline **ReportingApp-Setup.exe**, run on your dev PC:

```powershell
.\scripts\fetch-installer-assets.ps1
.\scripts\build-setup-exe.ps1 -BundleRuntime
```

See [docs/INSTALL-EXECUTABLE.md](../docs/INSTALL-EXECUTABLE.md).
