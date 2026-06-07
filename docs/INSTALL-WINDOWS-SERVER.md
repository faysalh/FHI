# Reporting App — Windows Server Installation Guide

> **Prefer a one-click install?** See [INSTALL-EXECUTABLE.md](INSTALL-EXECUTABLE.md) — build `ReportingApp-Setup.exe` on your dev PC; the server needs no PHP, Composer, or Node.js.

This application is a **Laravel 13** reporting portal. It reads live data from your **SQL Server** accounting database (read-only) and stores app-specific settings in local **SQLite** files (users, deliveries teams, governorates, holidays, damages entries).

---

## 1. Architecture overview

| Component | Purpose |
|-----------|---------|
| **SQL Server** (`sqlsrv`) | All report data (invoices, sales, storage, deliveries status, etc.) — **SELECT only** |
| **SQLite — `reports-users.sqlite`** | Login accounts and report permissions |
| **SQLite — `deliveries-local.sqlite`** | Delivery teams, governorates, non-working holidays |
| **SQLite — `damages-local.sqlite`** | Damages report entries (local to this app) |
| **SQLite — `operations-tasks.sqlite`** | Operations task notes |
| **Backup folder** (`storage/app/sqlite-backups` by default) | Saved SQLite backups from Settings → SQLite backups |
| **IIS (recommended)** | Serves the site; document root = `public` |
| **PHP 8.3+** | Runtime with `pdo_sqlsrv`, `pdo_sqlite`, and common Laravel extensions |

The app does **not** create or alter SQL Server tables. A DBA must provide a SQL login with read access to the reporting database.

---

## 2. Server requirements

### Operating system
- Windows Server 2019 or 2022 (64-bit)

### Software

| Software | Version | Notes |
|----------|---------|--------|
| **IIS** | 10+ | With URL Rewrite Module 2.x |
| **PHP** | **8.3 or 8.4** (64-bit) | Non-thread-safe (NTS) build for IIS FastCGI |
| **Microsoft ODBC Driver for SQL Server** | **18** (or 17) | Required for `pdo_sqlsrv` |
| **Microsoft PHP Drivers for SQL Server** | Matching PHP version | `php_sqlsrv` + `php_pdo_sqlsrv` |
| **Composer** | 2.x | PHP dependency manager |
| **Node.js** | 20 LTS or 22 LTS | **Build time only** — to compile front-end assets |
| **Git** | Optional | If you deploy by cloning the repository |

### PHP extensions (enable in `php.ini`)

```
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
extension=gd
extension=pdo_sqlsrv
extension=sqlsrv
```

Optional but recommended: `intl`, `exif`, `sodium`.

Verify from an elevated command prompt:

```bat
php -v
php -m
php -r "echo extension_loaded('pdo_sqlsrv') ? 'pdo_sqlsrv OK' : 'pdo_sqlsrv MISSING';"
```

---

## 3. SQL Server prerequisites

Work with your DBA **before** go-live.

1. **Database** — e.g. `AsanAccounting` on your SQL Server host.
2. **Login** — dedicated read-only user (e.g. `Reporting`).
3. **Permissions** — `SELECT` on reporting tables/views; `EXEC` only if you use optional PDA pricing (`REPORTING_PDA_PRICING_USER_UUID`).
4. **Password policy** — if the login password **expires**, all reports will stop until `.env` is updated. Consider disabling expiration for this service account or setting a renewal reminder.
5. **Network** — the app server must reach SQL Server on port **1433** (or your custom port).

Test from the app server (after PHP is installed):

```bat
cd C:\inetpub\reporting-app
php artisan reports:db-health
```

Or:

```bat
php -r "new PDO('sqlsrv:Server=YOUR_HOST,1433;Database=YOUR_DB;TrustServerCertificate=yes','USER','PASS'); echo 'OK';"
```

---

## 4. Install the application

### 4.1 Copy files

Example path: `C:\inetpub\reporting-app`

Copy or clone the project so this folder contains `artisan`, `composer.json`, `public\`, etc.

**Do not** expose the project root as the IIS site root — only the `public` folder (see §5).

### 4.2 Install PHP dependencies

```bat
cd C:\inetpub\reporting-app
composer install --no-dev --optimize-autoloader
```

On a build machine with dev tools you can use `composer install` without `--no-dev` for testing.

### 4.3 Build front-end assets

Required once per deploy (needs Node.js):

```bat
npm ci
npm run build
```

This creates `public\build\` (Vite manifest). The server does **not** need Node.js at runtime if assets are pre-built.

### 4.4 Environment file

```bat
copy .env.example .env
notepad .env
php artisan key:generate
```

### 4.5 Storage and SQLite paths

```bat
php artisan storage:link
```

Ensure the app can **write** to:

- `storage\` (logs, cache, sessions, uploads)
- `bootstrap\cache\`
- `database\` (SQLite files are created automatically on first use)

Grant **Modify** permission to the IIS app pool identity (e.g. `IIS AppPool\ReportingApp`) on those folders.

### 4.6 Production optimization

```bat
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

After any `.env` change, run:

```bat
php artisan config:clear
php artisan config:cache
```

---

## 5. IIS configuration

### 5.1 Install IIS features

- Web Server (IIS)
- CGI (for FastCGI)
- [URL Rewrite Module](https://www.iis.net/downloads/microsoft/url-rewrite)

### 5.2 Register PHP with FastCGI

In **IIS Manager → Handler Mappings**, add a FastCGI handler for `C:\php\php-cgi.exe` (adjust path).

Or use the [PHP Manager for IIS](https://phpmanager.codeplex.com/) if available in your environment.

### 5.3 Create the site

| Setting | Value |
|---------|--------|
| Site name | Reporting App |
| Physical path | `C:\inetpub\reporting-app\public` |
| Binding | HTTPS recommended (e.g. port 443) |
| App pool | Dedicated pool, **No Managed Code**, identity with folder write access |

### 5.4 `web.config` in `public`

Create or verify `public\web.config`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="Laravel" stopProcessing="true">
          <match url="^(.*)$" ignoreCase="false" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" appendQueryString="true" />
        </rule>
      </rules>
    </rewrite>
    <defaultDocument>
      <files>
        <clear />
        <add value="index.php" />
      </files>
    </defaultDocument>
    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment="vendor" />
          <add segment="storage" />
        </hiddenSegments>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>
```

### 5.5 PHP settings (`php.ini`)

Suggested production values:

```ini
memory_limit = 512M
max_execution_time = 120
upload_max_filesize = 16M
post_max_size = 20M
date.timezone = Asia/Baghdad
```

Adjust timezone to your region.

---

## 6. `.env` configuration (production example)

```env
APP_NAME="Reporting"
APP_ENV=production
APP_KEY=base64:...generated by key:generate...
APP_DEBUG=false
APP_URL=https://reports.yourcompany.local

LOG_CHANNEL=stack
LOG_LEVEL=warning

# SQL Server — reporting database (read-only)
DB_CONNECTION=sqlsrv
DB_HOST=10.10.10.250
DB_PORT=1433
DB_DATABASE=AsanAccounting
DB_USERNAME=Reporting
DB_PASSWORD=your_secure_password
DB_ENCRYPT=optional
DB_TRUST_SERVER_CERTIFICATE=true
DB_READONLY=true

# Local SQLite files (use absolute paths on the server)
DELIVERIES_SQLITE_DATABASE=C:/inetpub/reporting-app/database/deliveries-local.sqlite
DAMAGES_SQLITE_DATABASE=C:/inetpub/reporting-app/database/damages-local.sqlite
REPORTS_USERS_SQLITE_DATABASE=C:/inetpub/reporting-app/database/reports-users.sqlite

# First admin (created only when no users exist yet)
REPORTS_BOOTSTRAP_ADMIN_USERNAME=admin
REPORTS_BOOTSTRAP_ADMIN_PASSWORD=ChangeMeOnFirstLogin!

# Dashboard default governorate (optional)
REPORTING_DASHBOARD_GOVERNORATE_NAME=Erbil
REPORTING_DASHBOARD_GOVERNORATE_ID=0

SESSION_DRIVER=file
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
CACHE_STORE=file
FILESYSTEM_DISK=local
```

Use forward slashes in SQLite paths on Windows to avoid escape issues.

Optional reporting toggles are documented in `.env.example` and `config/reporting.php`.

---

## 7. First login and setup

1. Open `https://your-server/login` (or `/reports/...` after login redirect).
2. Sign in with `REPORTS_BOOTSTRAP_ADMIN_USERNAME` / `REPORTS_BOOTSTRAP_ADMIN_PASSWORD` if no users exist yet.
3. **Change the admin password** under **Settings → Users**.
4. Configure in the app:
   - **Settings → Governorates** — required for dashboard city filters
   - **Settings → Holidays** — Eid/non-working days for dashboard projections
   - **Settings → SQLite backups** — back up local SQLite files before server moves or updates
   - **Deliveries → Setup** — drivers, teams (uses local SQLite)
   - **Invoice branding** — PDF/print headers

---

## 8. Verify installation

| Check | Command / URL |
|-------|----------------|
| Laravel health | `GET /up` → should return 200 |
| SQL connectivity | `php artisan reports:db-health` |
| Schema browser | `/reports/schema` (after login) |
| Logs | `storage\logs\laravel.log` |

---

## 9. Updating the app

```bat
cd C:\inetpub\reporting-app
git pull
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Restart the IIS app pool after updates.

**Back up** before updating:

- `.env`
- `database\*.sqlite`
- `storage\app\` (uploaded logos, etc.)

---

## 10. Development vs production on Windows

| | Development | Production |
|---|-------------|------------|
| Start | `run-dev.cmd` or `composer dev` | IIS site |
| URL | `http://127.0.0.1:8010` | Your IIS binding |
| `APP_DEBUG` | `true` | **`false`** |
| Assets | Vite dev server (hot) | `npm run build` → `public/build` |

Do **not** use `php artisan serve` in production.

---

## 11. Troubleshooting

### Reports show no data / Schema empty
- Run `php artisan reports:db-health`.
- Common error: **Login failed … password expired** — reset SQL login password and update `DB_PASSWORD`.
- Confirm firewall: `Test-NetConnection SQL_HOST -Port 1433`.

### `pdo_sqlsrv` not loaded
- Install [ODBC Driver 18 for SQL Server](https://learn.microsoft.com/en-us/sql/connect/odbc/download-odbc-driver-for-sql-server).
- Install matching [PHP SQLSRV drivers](https://learn.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server).
- Enable extensions in `php.ini` and restart IIS.

### 500 error / blank page
- Set `APP_DEBUG=true` temporarily, reproduce, then set back to `false`.
- Check `storage\logs\laravel.log`.
- Ensure `storage\` and `bootstrap\cache\` are writable by the app pool identity.

### PDF export / Arabic text issues
- Fonts are bundled under `public\fonts\` and `storage\fonts\`.
- Ensure `storage\fonts\` is writable (DomPDF cache).

### Governorates / holidays / deliveries not saving
- Check `DELIVERIES_SQLITE_DATABASE` path and folder permissions.
- SQLite file is created on first use if the directory is writable.

### Permission denied on reports
- Super-admin sees all reports; other users need permissions under **Settings → Users**.

---

## 12. Security checklist

- [ ] `APP_DEBUG=false` in production
- [ ] HTTPS with valid certificate
- [ ] Strong bootstrap admin password changed after first login
- [ ] SQL login is read-only with minimal required access
- [ ] `.env` not under web root (only `public` is served)
- [ ] Restrict site access by VPN / internal network or IIS IP restrictions if needed
- [ ] Regular backups of SQLite and `.env`

---

## 13. Support commands

```bat
php artisan reports:db-health
php artisan config:clear
php artisan cache:clear
php artisan route:list
php artisan about
```

Health endpoint (no auth): `/up`

---

## 14. Quick reference — folder permissions

| Path | IIS app pool |
|------|----------------|
| `storage\` | Modify |
| `bootstrap\cache\` | Modify |
| `database\` | Modify (SQLite) |
| `public\` | Read |
| remainder of app | Read |

Application pool identity needs **Read & execute** on PHP and the project files; **Modify** only where listed above.
