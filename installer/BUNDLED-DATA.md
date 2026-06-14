# Bundled SQLite data

The release package includes local SQLite databases from `database\` when you build with the default settings:

| File | Contents |
|------|----------|
| `reports-users.sqlite` | Login accounts and report permissions |
| `deliveries-local.sqlite` | Delivery teams, governorates, non-working holidays |
| `damages-local.sqlite` | Damages report entries |
| `operations-tasks.sqlite` | Operations task notes |

After `scripts\build-release.ps1`, see `installer\BUNDLED-SQLITE.json` in the release folder for file sizes and timestamps.

Test databases (`damages-test-*.sqlite`) and Laravel's default `database.sqlite` are **not** included.

On install, `.env` paths point to `{app}\database\*.sqlite`.

**Upgrades:** `ReportingApp-Setup.exe` never overwrites existing `database\*.sqlite` files. Before copying files, it backs them up under `storage\app\sqlite-backups\pre-install-{timestamp}\`. Fresh installs still receive the bundled seed databases (`onlyifdoesntexist`).

To build without your current SQLite files (fresh empty install on server):

```powershell
.\scripts\build-release.ps1 -BundleRuntime -IncludeSqliteData:$false
```
