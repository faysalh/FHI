<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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
    } catch (\Throwable $exception) {
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
