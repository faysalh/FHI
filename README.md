# Reporting App

Laravel reporting portal (SQL Server read-only + local SQLite for app settings).

## Windows deployment (no PHP/Composer/Node on server)

1. On your **build PC**: `.\scripts\fetch-installer-assets.ps1` then `.\scripts\build-setup-exe.ps1 -BundleRuntime`
2. Copy **`dist\ReportingApp-Setup.exe`** to the server and run as Administrator.

Full guide: [docs/INSTALL-EXECUTABLE.md](docs/INSTALL-EXECUTABLE.md)

Manual IIS setup: [docs/INSTALL-WINDOWS-SERVER.md](docs/INSTALL-WINDOWS-SERVER.md)

## Development

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
php artisan serve
```
