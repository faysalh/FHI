# AGENTS.md

## Cursor Cloud specific instructions

### Overview

This is a Laravel 13 reporting application for a poultry/chicken distribution business. It connects to an external Microsoft SQL Server (read-only) for report data and uses SQLite locally for app state (delivery teams, governorate mappings). There is no authentication system.

### System requirements

- **PHP 8.4+** (the lock file requires Symfony 8.x which needs PHP >=8.4)
- **Node.js 22+** with npm
- **Composer 2.x**
- **SQLite3** (for local database)
- SQL Server + `pdo_sqlsrv` extension only needed for end-to-end data (tests use SQLite in-memory)

### Key commands

| Task | Command |
|------|---------|
| Install PHP deps | `composer install` |
| Install JS deps | `npm install` |
| Full dev environment | `composer dev` (starts PHP server, queue, logs, and Vite concurrently) |
| PHP server only | `php artisan serve` |
| Vite dev only | `npm run dev` |
| Run tests | `php artisan test` |
| Lint check | `./vendor/bin/pint --test` |
| Lint fix | `./vendor/bin/pint` |
| Build frontend | `npm run build` |
| Migrations | `php artisan migrate` |

### Non-obvious notes

- The `composer.json` `"require"` says `"php": "^8.3"` but the lock file resolves Symfony 8.x packages that actually require PHP 8.4+. Always use PHP 8.4.
- Tests run entirely on SQLite in-memory (see `phpunit.xml`) and do not require SQL Server. All 27 tests pass without SQL Server.
- Most report repositories check `DB::getDriverName() !== 'sqlsrv'` and return empty/throw on non-SQL Server connections. Report pages will render but show "Unable to load" messages when running locally with SQLite—this is expected.
- The `composer dev` script uses `npx concurrently` to start 4 services simultaneously (PHP server, queue listener, Pail logs, Vite). It works but you can also start them individually.
- The app uses two SQLite databases: `database/database.sqlite` (main) and `database/deliveries-local.sqlite` (delivery teams). Both are auto-created.
- No `.env` file is committed; copy `.env.example` to `.env` and run `php artisan key:generate` on first setup.
- Frontend uses Vite 8 + Tailwind CSS 4. The Vite dev server serves on port 5173 by default.
- PHP dev server runs on port 8000 by default.
