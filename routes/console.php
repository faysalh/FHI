<?php

declare(strict_types=1);

use App\Services\PdaAutoSyncService;
use App\Services\SqliteAutoBackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\InputOption;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reports:db-health', function (): int {
    try {
        $startedAt = microtime(true);
        DB::select('SELECT 1');
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        Log::channel('db_health')->info('DB health check success.', [
            'db_connection' => config('database.default'),
            'db_host' => config('database.connections.'.config('database.default').'.host'),
            'duration_ms' => $durationMs,
        ]);

        $this->info('DB health check passed.');

        return self::SUCCESS;
    } catch (Throwable $exception) {
        Log::channel('db_health')->error('DB health check failed.', [
            'db_connection' => config('database.default'),
            'db_host' => config('database.connections.'.config('database.default').'.host'),
            'error_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        $this->error('DB health check failed: '.$exception->getMessage());

        return self::FAILURE;
    }
})->purpose('Check remote DB connectivity for reports module');

Artisan::command('reports:test-run', function (): int {
    $result = Process::path(base_path())->run('php artisan test');
    $output = $result->output().$result->errorOutput();

    file_put_contents(storage_path('logs/test-results.log'), $output.PHP_EOL, FILE_APPEND);

    if ($result->failed()) {
        file_put_contents(storage_path('logs/test-errors.log'), $output.PHP_EOL, FILE_APPEND);
        Log::channel('test_errors')->error('Test run failed.', [
            'exit_code' => $result->exitCode(),
        ]);
        $this->error('Tests failed. Check storage/logs/test-results.log and storage/logs/test-errors.log');

        return self::FAILURE;
    }

    Log::channel('test_results')->info('Test run passed.', [
        'exit_code' => $result->exitCode(),
    ]);
    $this->info('Tests passed. Output saved to storage/logs/test-results.log');

    return self::SUCCESS;
})->purpose('Run tests and persist output/error logs');

Artisan::command('reports:clear-invoice-last-print {invoiceId}', function (string $invoiceId): int {
    $invoiceId = trim($invoiceId);
    if ($invoiceId === '') {
        $this->error('invoiceId is required.');

        return self::FAILURE;
    }

    if (DB::getDriverName() !== 'sqlsrv') {
        $this->error('This command requires the default DB connection to be sqlsrv.');

        return self::FAILURE;
    }

    if (! app()->environment('local', 'testing') && ! $this->option('force')) {
        $this->error('Refusing to run outside local/testing without --force.');

        return self::FAILURE;
    }

    try {
        $updated = DB::update(
            'UPDATE dbo.tbl_store_document_titles
             SET fld_last_print_date = NULL
             WHERE fld_store_document_title_id = ?
               AND ISNULL(fld_is_cancelled, 0) = 0',
            [$invoiceId]
        );
    } catch (Throwable $e) {
        $this->error('Update failed: '.$e->getMessage());

        return self::FAILURE;
    }

    $this->info('Rows updated: '.$updated);
    if ($updated < 1) {
        $this->warn('No matching title row (check internal invoice id and cancellation flag).');
    } else {
        $this->comment('Tip: on /reports/invoices, uncheck Pick for this invoice before re-testing first-print logic.');
    }

    return self::SUCCESS;
})->purpose('Clear fld_last_print_date on dbo.tbl_store_document_titles for one internal invoice id (retest first print)')
    ->addOption('force', null, InputOption::VALUE_NONE, 'Allow outside local/testing');

Artisan::command('reports:sqlite-auto-backup', function (SqliteAutoBackupService $autoBackup): int {
    try {
        if ($this->option('force')) {
            $result = $autoBackup->runNow();
            $this->info('SQLite auto backup saved: '.$result['filename'].' ('.$result['label'].').');

            return self::SUCCESS;
        }

        if (! $autoBackup->runIfDue()) {
            return self::SUCCESS;
        }

        $settings = $autoBackup->settings();
        $filename = (string) ($settings['last_run_filename'] ?? '');
        $this->info($filename !== ''
            ? 'SQLite auto backup saved: '.$filename
            : 'SQLite auto backup completed.');

        return self::SUCCESS;
    } catch (Throwable $e) {
        Log::error('sqlite_auto_backup.command_failed', ['message' => $e->getMessage()]);
        $this->error('SQLite auto backup failed: '.$e->getMessage());

        return self::FAILURE;
    }
})->purpose('Run scheduled SQLite backup when daily time is due')
    ->addOption('force', null, InputOption::VALUE_NONE, 'Run immediately using the saved backup folder');

Artisan::command('reports:pda-auto-sync', function (PdaAutoSyncService $autoSync): int {
    try {
        if ($this->option('force')) {
            $ran = $autoSync->runNow();
            $this->info($ran
                ? 'PDA auto sync completed.'
                : 'No pending PDA work — sync was skipped.');

            return self::SUCCESS;
        }

        if (! $autoSync->runIfDue()) {
            return self::SUCCESS;
        }

        $this->info('PDA auto sync completed.');

        return self::SUCCESS;
    } catch (Throwable $e) {
        Log::error('pda_auto_sync.command_failed', ['message' => $e->getMessage()]);
        $this->error('PDA auto sync failed: '.$e->getMessage());

        return self::FAILURE;
    }
})->purpose('Run scheduled PDA sync when interval is due and work is pending')
    ->addOption('force', null, InputOption::VALUE_NONE, 'Run immediately if pending PDA work exists');
