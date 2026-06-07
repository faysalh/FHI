# Reporting App — One-Click Windows Installer (single `.exe`)

Use this path when the **target server should not need PHP, Composer, Node.js, or Git**.

**Deliverable:** one file — `dist\ReportingApp-Setup.exe` (~100 MB). Copy it to the server and run as Administrator. No zip, no extra folders.

The server still needs:

- **Windows Server 2019+** (or Windows 10/11 for testing) with **IIS** available
- **Network access to SQL Server** (read-only login provided by your DBA)
- **Administrator** rights to run the installer

Everything else (PHP, SQL drivers, ODBC 18, URL Rewrite, compiled Laravel app, **SQLite local data**) is embedded inside the setup `.exe`.

---

## Build the single-file installer (on your dev PC)

### 1. One-time: download runtime assets

From the project root, in PowerShell:

```powershell
.\scripts\fetch-installer-assets.ps1
```

This fills `installer\assets\` with PHP 8.3, SQL Server PHP drivers, ODBC 18 MSI, and IIS URL Rewrite MSI.

### 2. One-time: install Inno Setup 6

Download from [jrsoftware.org/isdl.php](https://jrsoftware.org/isdl.php) (free).

### 3. Build `ReportingApp-Setup.exe`

Double-click **`Build-Installable.cmd`**, or:

```powershell
.\scripts\build-installer-exe.ps1
```

Output:

| Artifact | Purpose |
|----------|---------|
| **`dist\ReportingApp-Setup.exe`** | **The only file you ship** — wizard installer with app + PHP + SQLite |
| `dist\ReportingApp-Release\` | Staging folder used during build (not needed on server) |

Optional zip (if you also want a folder deploy):

```powershell
.\scripts\build-setup-exe.ps1 -BundleRuntime
```

**Local SQLite data** (users, deliveries, damages, tasks) from your dev `database\` folder is bundled by default. See `installer\BUNDLED-DATA.md`.

Requires **Inno Setup 6** on the build machine to produce the `.exe`. If Inno Setup is not installed, the script still builds the release folder and zip.

---

## Install on the server

1. Copy `ReportingApp-Setup.exe` to the server.
2. Run **as Administrator**.
3. Follow the wizard:
   - Install folder (default `C:\Program Files\ReportingApp`)
   - SQL Server host, database, username, password
   - Bootstrap admin username/password for `/login`
   - Site port (default **8080**)
4. When finished, open the URL shown on the last wizard page (default port **8090**).

On the **Browser URL** step, enter the address other PCs will use, e.g. `http://10.10.10.250:8090` (not only `localhost` if you need LAN access).

The installer will:

- Enable IIS + CGI features if missing
- Install ODBC Driver 18 and URL Rewrite (from bundle)
- Copy bundled PHP into `{app}\runtime\php`
- Write `.env`, run `artisan key:generate`, cache config/routes/views
- Create the IIS site **ReportingApp** pointing at `{app}\public`
- Run `php artisan reports:db-health`

---

## Alternative: zip + `Install.cmd`

Extract `ReportingApp-Release-*.zip`, then double-click **`installer\Install.cmd`** (Run as administrator). Same result as the wizard, with prompts in the console.

Fully offline zip requires building with `-BundleRuntime` after `fetch-installer-assets.ps1`.

Online fallback (internet on server):

```powershell
powershell -ExecutionPolicy Bypass -File installer\install.ps1 -AllowDownload
```

---

## Troubleshooting

| Issue | Action |
|-------|--------|
| **No `.env` file** | Normal packages do **not** ship `.env` — the wizard creates it at the end. If missing, the post-install step likely failed. Run **`installer\create-env.cmd`** from the install folder (e.g. `C:\Program Files\ReportingApp`), enter SQL + admin passwords, then open the URL shown. |
| **Composer / npm error when starting** | Do **not** use dev shortcuts on the server. Production runs under **IIS**. Open the site in a browser (`http://server:8090/login`) or run **`start-reporting-app.bat`** from the install folder (starts IIS site + opens login). Composer is only needed on your dev PC. |
| Reports empty / DB errors | Run `runtime\php\php.exe artisan reports:db-health` from the install folder. Check SQL password expiry. |
| 404 on all routes | Install **URL Rewrite** (bundled MSI runs automatically; re-run installer or install `installer\assets\rewrite_amd64.msi`). |
| PHP errors in browser | Check `{app}\storage\logs\laravel.log` and IIS site physical path = `{app}\public`. |
| Manual reinstall | `powershell -ExecutionPolicy Bypass -File installer\install.ps1 -InstallPath "C:\Program Files\ReportingApp" -Quiet ...` |

See also [INSTALL-WINDOWS-SERVER.md](INSTALL-WINDOWS-SERVER.md) for manual IIS/PHP setup and SQL prerequisites.
