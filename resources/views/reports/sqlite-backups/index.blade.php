@extends('reports.layouts.app')
@section('title', 'SQLite backups')

@section('content')
<header class="page-header">
    <h1>SQLite backups</h1>
</header>
<p class="hint">
        Back up and restore the local SQLite files used by this app (users, deliveries setup, governorates, holidays, damages, and tasks).
        SQL Server reporting data is <strong>not</strong> included — only app settings stored on this server.
        Restoring replaces the live database file; the current file is copied into the backup folder first.
    </p>

@if (session('status'))
    <div class="alert alert--success">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="alert alert--error">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert--error">
        <ul class="error-list-plain">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@php
    $formatBytes = $formatBytes ?? static fn (int $bytes): string => number_format($bytes).' B';
    $autoBackup = $autoBackup ?? [
        'enabled' => false,
        'directory' => '',
        'time' => '02:00',
        'last_run_at' => null,
        'last_run_filename' => null,
        'last_error' => null,
    ];
@endphp

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Daily auto backup</h2>
    <p class="hint sqlite-card__hint">
        Save a full ZIP of all local SQLite databases every day at the time you choose.
        Pick a shared folder on this server (for example <code>C:\Backups\ReportingApp</code>).
        Do <strong>not</strong> use your Desktop or Documents — the app runs as <code>IIS AppPool\ReportingApp</code>, not as the logged-in Windows user.
        Grant <strong>Modify</strong> on that folder to <code>IIS_IUSRS</code>.
    </p>
    <form method="POST" action="{{ route('reports.sqlite-backups.auto-settings') }}" class="sqlite-auto-form">
        @csrf
        <label class="sqlite-auto-form__toggle">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $autoBackup['enabled']))>
            Enable daily auto backup
        </label>
        <div class="sqlite-auto-form__grid">
            <div>
                <label for="auto_backup_directory">Backup folder on this server</label>
                <input
                    type="text"
                    id="auto_backup_directory"
                    name="directory"
                    value="{{ old('directory', $autoBackup['directory']) }}"
                    placeholder="D:\Backups\ReportingApp"
                    class="sqlite-auto-form__path"
                >
            </div>
            <div>
                <label for="auto_backup_time">Daily time (24-hour)</label>
                <input
                    type="time"
                    id="auto_backup_time"
                    name="time"
                    value="{{ old('time', $autoBackup['time']) }}"
                    required
                >
            </div>
            <div class="sqlite-auto-form__action">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Save auto backup'])
            </div>
        </div>
    </form>
    <div class="sqlite-auto-status">
        @if ($autoBackup['last_run_at'])
            <p class="muted">Last auto backup: <strong>{{ $autoBackup['last_run_at'] }}</strong>
                @if (! empty($autoBackup['last_run_filename']))
                    — <code>{{ $autoBackup['last_run_filename'] }}</code>
                @endif
            </p>
        @else
            <p class="muted">No automatic backup has run yet.</p>
        @endif
        @if (! empty($autoBackup['directory']) && empty($autoBackupDirectoryActive))
            <p class="sqlite-auto-status__error">
                Saved folder <code>{{ $autoBackup['directory'] }}</code> is not active yet.
                Click <strong>Save auto backup</strong> again after granting permissions (see below).
                Until then backups use <code>{{ $defaultBackupDirectory ?? $backupDirectory }}</code>.
            </p>
        @endif
        @if (! empty($autoBackup['last_error']))
            <p class="sqlite-auto-status__error">Last error: {{ $autoBackup['last_error'] }}</p>
        @endif
        <form method="POST" action="{{ route('reports.sqlite-backups.auto-run') }}" class="sqlite-auto-run">
            @csrf
            <button type="submit" class="btn btn--secondary btn--sm">Run auto backup now</button>
        </form>
    </div>
    <details class="sqlite-auto-scheduler">
        <summary>Windows server: enable the scheduler</summary>
        <p class="hint">
            Auto backup uses Laravel’s scheduler. On the server, open <strong>PowerShell as Administrator</strong> and run:
        </p>
        <pre class="sqlite-auto-scheduler__cmd">powershell -ExecutionPolicy Bypass -File "C:\Program Files\ReportingApp\scripts\register-reporting-scheduler.ps1"</pre>
        <p class="hint muted">If you are already in an elevated PowerShell window, run <code>&amp; "C:\Program Files\ReportingApp\scripts\register-reporting-scheduler.ps1"</code> instead. Adjust the path if Reporting App is installed elsewhere. The task runs as SYSTEM — pick a backup folder that account can write to.</p>
        <p class="hint muted">First-time folder setup (Administrator PowerShell): <code>icacls "C:\Backups\ReportingApp" /grant "IIS_IUSRS:(OI)(CI)M" "IIS AppPool\ReportingApp:(OI)(CI)M" "SYSTEM:(OI)(CI)M"</code></p>
    </details>
</section>

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Active backup folder</h2>
    <p class="muted sqlite-path"><code>{{ $backupDirectory }}</code></p>
    <p class="hint sqlite-card__hint">Manual backups and stored backup list use this folder right now.</p>
    <form method="POST" action="{{ route('reports.sqlite-backups.store') }}" class="sqlite-actions">
        @csrf
        <input type="hidden" name="database_key" value="all">
        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Backup all databases (ZIP)'])
    </form>
</section>

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Local databases</h2>
    <table class="sqlite-table">
        <thead>
        <tr>
            <th>Database</th>
            <th>File</th>
            <th>Size</th>
            <th>Last modified</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($databases as $database)
            <tr>
                <td>{{ $database['label'] }}</td>
                <td><code class="sqlite-file">{{ $database['path'] !== '' ? $database['path'] : '—' }}</code></td>
                <td>{{ $database['exists'] ? $formatBytes((int) $database['size']) : '—' }}</td>
                <td>{{ $database['modified_at'] ?? '—' }}</td>
                <td class="sqlite-table__actions">
                    @if ($database['exists'])
                        <form method="POST" action="{{ route('reports.sqlite-backups.store') }}">
                            @csrf
                            <input type="hidden" name="database_key" value="{{ $database['key'] }}">
                            <button type="submit" class="btn btn--secondary btn--sm">Backup</button>
                        </form>
                    @else
                        <span class="muted">Not created yet</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</section>

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Upload previous backup</h2>
    <p class="hint sqlite-card__hint">Upload a <code>.sqlite</code> file from another server or an older backup. Choose which database it should replace.</p>
    <form method="POST" action="{{ route('reports.sqlite-backups.restore-upload') }}" enctype="multipart/form-data" class="sqlite-restore-form">
        @csrf
        <div class="sqlite-restore-form__grid">
            <div>
                <label for="restore_database_key">Restore into</label>
                <select id="restore_database_key" name="database_key" required>
                    @foreach ($databaseOptions as $option)
                        <option value="{{ $option['key'] }}" @selected(old('database_key') === $option['key'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="backup_file">SQLite file</label>
                <input type="file" id="backup_file" name="backup_file" accept=".sqlite,.db,application/x-sqlite3,application/vnd.sqlite3" required>
            </div>
            <div class="sqlite-restore-form__action">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Upload & restore'])
            </div>
        </div>
    </form>
</section>

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Stored backups ({{ count($backups) }})</h2>
    @if ($backups === [])
        <p class="muted">No backups saved yet. Use <strong>Backup all databases</strong> or per-database <strong>Backup</strong> above.</p>
    @else
        <table class="sqlite-table">
            <thead>
            <tr>
                <th>File</th>
                <th>Type</th>
                <th>Size</th>
                <th>Created</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($backups as $backup)
                <tr>
                    <td><code>{{ $backup['filename'] }}</code></td>
                    <td>{{ $backup['kind'] === 'archive' ? 'All (ZIP)' : ($backup['database_key'] ?? 'Database') }}</td>
                    <td>{{ $formatBytes((int) $backup['size']) }}</td>
                    <td>{{ $backup['modified_at'] }}</td>
                    <td class="sqlite-table__actions">
                        <a class="btn btn--secondary btn--sm" href="{{ route('reports.sqlite-backups.download', ['filename' => $backup['filename']]) }}">Download</a>
                        <form method="POST" action="{{ route('reports.sqlite-backups.restore-stored') }}" class="sqlite-inline-form" onsubmit="return confirm('Restore this backup? Current data will be replaced (a pre-restore copy is saved first).');">
                            @csrf
                            <input type="hidden" name="filename" value="{{ $backup['filename'] }}">
                            @if ($backup['kind'] === 'database' && ! empty($backup['database_key']))
                                <input type="hidden" name="database_key" value="{{ $backup['database_key'] }}">
                            @else
                                <select name="database_key" class="sqlite-restore-select" aria-label="Restore target">
                                    <option value="">All databases (ZIP)</option>
                                    @foreach ($databaseOptions as $option)
                                        <option value="{{ $option['key'] }}">{{ $option['label'] }} only</option>
                                    @endforeach
                                </select>
                            @endif
                            <button type="submit" class="btn btn--secondary btn--sm">Restore</button>
                        </form>
                        <form method="POST" action="{{ route('reports.sqlite-backups.destroy', ['filename' => $backup['filename']]) }}" class="sqlite-inline-form" onsubmit="return confirm('Delete this backup file?');">
                            @csrf
                            @method('DELETE')
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete backup'])
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection

@push('styles')
<style>
    .sqlite-card {
        background: #fff;
        border: 1px solid var(--border, #e2e8f0);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }
    .sqlite-card__title { margin: 0 0 0.75rem; font-size: 1.05rem; }
    .sqlite-card__hint { margin: 0 0 1rem; }
    .sqlite-path code { word-break: break-all; }
    .sqlite-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .sqlite-table th, .sqlite-table td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; vertical-align: top; }
    .sqlite-table th { background: #f9fafb; }
    .sqlite-file { word-break: break-all; font-size: 12px; }
    .sqlite-table__actions { white-space: nowrap; }
    .sqlite-table__actions form, .sqlite-inline-form { display: inline-flex; gap: 0.35rem; align-items: center; margin-left: 0.35rem; }
    .sqlite-restore-form__grid {
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(220px, 1.2fr) auto;
        gap: 0.75rem 1rem;
        align-items: end;
    }
    .sqlite-restore-select { max-width: 220px; font-size: 13px; }
    .sqlite-actions { margin-top: 0.75rem; }
    .sqlite-auto-form__toggle { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; font-weight: 600; }
    .sqlite-auto-form__grid {
        display: grid;
        grid-template-columns: minmax(220px, 1.4fr) minmax(140px, 0.6fr) auto;
        gap: 0.75rem 1rem;
        align-items: end;
    }
    .sqlite-auto-form__path { width: 100%; font-family: ui-monospace, monospace; font-size: 13px; }
    .sqlite-auto-status { margin-top: 0.75rem; display: flex; flex-wrap: wrap; gap: 0.75rem 1rem; align-items: center; }
    .sqlite-auto-status p { margin: 0; }
    .sqlite-auto-status__error { color: #b91c1c; }
    .sqlite-auto-scheduler { margin-top: 1rem; font-size: 14px; }
    .sqlite-auto-scheduler__cmd {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.75rem;
        overflow-x: auto;
        font-size: 12px;
    }
    @media (max-width: 900px) {
        .sqlite-restore-form__grid,
        .sqlite-auto-form__grid { grid-template-columns: 1fr; }
    }
</style>
@endpush
