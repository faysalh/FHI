<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SqliteAutoBackupSettingsRequest;
use App\Http\Requests\SqliteBackupCreateRequest;
use App\Http\Requests\SqliteBackupRestoreStoredRequest;
use App\Http\Requests\SqliteBackupRestoreUploadRequest;
use App\Services\LocalSqliteBackupService;
use App\Services\SqliteAutoBackupService;
use App\Support\SqliteAutoBackupSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SqliteBackupSettingsController extends Controller
{
    public function __construct(
        private readonly LocalSqliteBackupService $backups,
        private readonly SqliteAutoBackupService $autoBackup
    ) {}

    public function index(): View
    {
        $autoBackup = $this->autoBackup->settings();
        $activeBackupDirectory = $this->backups->backupDirectory();

        return view('reports.sqlite-backups.index', [
            'databases' => $this->backups->managedDatabases(),
            'backups' => $this->backups->listBackups(),
            'backupDirectory' => $activeBackupDirectory,
            'defaultBackupDirectory' => $this->backups->defaultBackupDirectory(),
            'autoBackup' => $autoBackup,
            'autoBackupDirectoryActive' => $autoBackup['directory'] !== ''
                && $activeBackupDirectory === SqliteAutoBackupSettings::normalizeDirectory($autoBackup['directory']),
            'databaseOptions' => $this->databaseOptions(),
            'formatBytes' => fn (int $bytes): string => $this->backups->formatBytes($bytes),
        ]);
    }

    public function updateAutoBackup(SqliteAutoBackupSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->autoBackup->saveSettings(
                (bool) ($validated['enabled'] ?? false),
                (string) ($validated['directory'] ?? ''),
                (string) ($validated['time'] ?? '02:00')
            );
        } catch (RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_auto_backup.save_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return back()
                ->withInput()
                ->with('error', $e->getMessage() !== '' ? $e->getMessage() : 'Could not save auto backup settings.');
        }

        $savedDirectory = SqliteAutoBackupSettings::configuredDirectory();

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with(
                'status',
                $savedDirectory !== ''
                    ? 'Auto backup settings saved. Backups will use: '.$savedDirectory
                    : 'Auto backup settings saved.'
            );
    }

    public function runAutoBackupNow(): RedirectResponse
    {
        try {
            $result = $this->autoBackup->runNow();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_auto_backup.run_now_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return back()->with('error', $e->getMessage() !== '' ? $e->getMessage() : 'Auto backup failed.');
        }

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with('status', 'Auto backup saved: '.$result['filename'].' ('.$result['label'].').');
    }

    public function store(SqliteBackupCreateRequest $request): RedirectResponse
    {
        $databaseKey = trim((string) ($request->validated('database_key') ?? ''));

        try {
            $result = $this->backups->createBackup($databaseKey !== '' ? $databaseKey : null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_backup.create_failed', ['message' => $e->getMessage()]);

            return back()->with('error', 'Could not create backup. Check logs and try again.');
        }

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with('status', 'Backup saved: '.$result['filename'].' ('.$result['label'].').');
    }

    public function download(string $filename): BinaryFileResponse|RedirectResponse
    {
        try {
            $path = $this->backups->resolveStoredBackupPath($filename);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('reports.sqlite-backups.index')
                ->with('error', $e->getMessage());
        }

        return response()->download($path, basename($path));
    }

    public function restoreUpload(SqliteBackupRestoreUploadRequest $request): RedirectResponse
    {
        $databaseKey = (string) $request->validated('database_key');
        $uploadedFile = $request->file('backup_file');
        if ($uploadedFile === null) {
            return back()->with('error', 'Choose a SQLite backup file to upload.');
        }

        try {
            $restored = $this->backups->restoreFromUpload($uploadedFile, $databaseKey);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_backup.restore_upload_failed', ['message' => $e->getMessage()]);

            return back()->with('error', 'Could not restore backup. Check logs and try again.');
        }

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with('status', 'Restored '.$restored[0].'. A pre-restore copy was saved in the backup folder.');
    }

    public function restoreStored(SqliteBackupRestoreStoredRequest $request): RedirectResponse
    {
        $filename = (string) $request->validated('filename');
        $databaseKey = trim((string) ($request->validated('database_key') ?? ''));

        try {
            $restored = $this->backups->restoreFromStoredBackup(
                $filename,
                $databaseKey !== '' ? $databaseKey : null
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_backup.restore_stored_failed', ['filename' => $filename, 'message' => $e->getMessage()]);

            return back()->with('error', 'Could not restore backup. Check logs and try again.');
        }

        $labels = implode(', ', $restored);

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with('status', 'Restored '.$labels.'. A pre-restore copy was saved when replacing existing data.');
    }

    public function destroy(string $filename): RedirectResponse
    {
        try {
            $this->backups->deleteStoredBackup($filename);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('sqlite_backup.delete_failed', ['filename' => $filename, 'message' => $e->getMessage()]);

            return back()->with('error', 'Could not delete backup file.');
        }

        return redirect()
            ->route('reports.sqlite-backups.index')
            ->with('status', 'Deleted backup '.$filename.'.');
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function databaseOptions(): array
    {
        return array_map(
            static fn (array $database): array => [
                'key' => $database['key'],
                'label' => $database['label'],
            ],
            $this->backups->managedDatabases()
        );
    }
}
