<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SqliteAutoBackupSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SqliteAutoBackupService
{
    private const SETTINGS_RELATIVE = 'app/sqlite-auto-backup.json';

    public function __construct(
        private readonly LocalSqliteBackupService $backups
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     directory: string,
     *     time: string,
     *     last_run_at: ?string,
     *     last_run_filename: ?string,
     *     last_error: ?string
     * }
     */
    public function settings(): array
    {
        $defaults = [
            'enabled' => false,
            'directory' => '',
            'time' => '02:00',
            'last_run_at' => null,
            'last_run_filename' => null,
            'last_error' => null,
        ];

        $path = $this->settingsPath();
        if (! File::exists($path)) {
            return $defaults;
        }

        $raw = json_decode((string) File::get($path), true);
        if (! is_array($raw)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'directory' => SqliteAutoBackupSettings::normalizeDirectory((string) ($raw['directory'] ?? '')),
            'time' => $this->normalizeTime((string) ($raw['time'] ?? '02:00')),
            'last_run_at' => isset($raw['last_run_at']) ? (string) $raw['last_run_at'] : null,
            'last_run_filename' => isset($raw['last_run_filename']) ? (string) $raw['last_run_filename'] : null,
            'last_error' => isset($raw['last_error']) ? (string) $raw['last_error'] : null,
        ];
    }

    public function configuredDirectory(): string
    {
        return SqliteAutoBackupSettings::configuredDirectory();
    }

    /**
     * @return array{enabled: bool, directory: string, time: string}
     */
    public function saveSettings(bool $enabled, string $directory, string $time): array
    {
        $directory = SqliteAutoBackupSettings::normalizeDirectory($directory);
        $time = $this->normalizeTime($time);

        if ($enabled && $directory === '') {
            throw new RuntimeException('Choose a backup folder on this server.');
        }

        if ($directory !== '') {
            $this->assertDirectoryUsable($directory);
        }

        $current = $this->settings();
        $payload = [
            'enabled' => $enabled,
            'directory' => $directory,
            'time' => $time,
            'last_run_at' => $current['last_run_at'],
            'last_run_filename' => $current['last_run_filename'],
            'last_error' => null,
        ];

        $this->writeSettings($payload);

        return [
            'enabled' => $enabled,
            'directory' => $directory,
            'time' => $time,
        ];
    }

    /**
     * Run a scheduled backup when enabled and the configured daily time has passed.
     */
    public function runIfDue(?Carbon $now = null): bool
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return false;
        }

        $now ??= now();
        $scheduledToday = $now->copy()->startOfDay()->setTimeFromTimeString($settings['time']);
        if ($now->lt($scheduledToday)) {
            return false;
        }

        if ($this->alreadyRanToday($settings, $scheduledToday)) {
            return false;
        }

        return $this->runBackup($now);
    }

    /**
     * Force an immediate auto backup using the saved folder (ignores enabled flag).
     *
     * @return array{filename: string, label: string}
     */
    public function runNow(): array
    {
        $settings = $this->settings();
        if (trim($settings['directory']) === '') {
            throw new RuntimeException('Set a backup folder before running auto backup.');
        }

        $this->assertDirectoryUsable($settings['directory']);
        $this->runBackup(now());

        $updated = $this->settings();

        return [
            'filename' => (string) ($updated['last_run_filename'] ?? ''),
            'label' => 'All local databases',
        ];
    }

    private function runBackup(Carbon $ranAt): bool
    {
        $settings = $this->settings();

        try {
            $result = $this->backups->createBackup('all', $settings['directory']);
            $this->writeSettings([
                'enabled' => $settings['enabled'],
                'directory' => $settings['directory'],
                'time' => $settings['time'],
                'last_run_at' => $ranAt->toDateTimeString(),
                'last_run_filename' => $result['filename'],
                'last_error' => null,
            ]);

            Log::channel('single')->info('sqlite_auto_backup.completed', [
                'filename' => $result['filename'],
                'directory' => $settings['directory'],
            ]);

            return true;
        } catch (Throwable $e) {
            $settings = $this->settings();
            $this->writeSettings([
                'enabled' => $settings['enabled'],
                'directory' => $settings['directory'],
                'time' => $settings['time'],
                'last_run_at' => $settings['last_run_at'],
                'last_run_filename' => $settings['last_run_filename'],
                'last_error' => $e->getMessage(),
            ]);

            Log::channel('single')->error('sqlite_auto_backup.failed', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array{enabled: bool, directory: string, time: string, last_run_at: ?string, last_run_filename: ?string, last_error: ?string}  $settings
     */
    private function alreadyRanToday(array $settings, Carbon $scheduledToday): bool
    {
        $lastRunAt = trim((string) ($settings['last_run_at'] ?? ''));
        if ($lastRunAt === '') {
            return false;
        }

        try {
            $lastRun = Carbon::parse($lastRunAt);
        } catch (Throwable) {
            return false;
        }

        return $lastRun->isSameDay($scheduledToday) && $lastRun->greaterThanOrEqualTo($scheduledToday);
    }

    private function assertDirectoryUsable(string $directory): void
    {
        if ($directory === '' || preg_match('/^[a-zA-Z]:\\\\|^\\\\/u', $directory) !== 1) {
            throw new RuntimeException('Use a full folder path on this server (for example D:\\Backups\\ReportingApp).');
        }

        if (! File::isDirectory($directory)) {
            try {
                File::makeDirectory($directory, 0755, true);
            } catch (Throwable) {
                throw new RuntimeException('Could not create the backup folder: '.$directory);
            }
        }

        if (! File::isDirectory($directory)) {
            throw new RuntimeException('The backup folder does not exist: '.$directory);
        }

        $probe = $directory.DIRECTORY_SEPARATOR.'.sqlite-backup-write-test-'.uniqid('', true);
        $written = @file_put_contents($probe, 'ok');
        if ($written === false) {
            throw new RuntimeException($this->directoryPermissionMessage($directory));
        }

        @unlink($probe);
    }

    private function directoryPermissionMessage(string $directory): string
    {
        return 'The web server cannot write to '.$directory.'. '
            .'Backups run as IIS AppPool\\ReportingApp (and as SYSTEM for scheduled runs). '
            .'Use a shared folder such as C:\\Backups\\ReportingApp and grant Modify permission to IIS_IUSRS, '
            .'not a user Desktop folder.';
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{1,2}:\d{2}$/', $time) !== 1) {
            throw new RuntimeException('Daily time must use HH:MM format (24-hour).');
        }

        [$hour, $minute] = array_map('intval', explode(':', $time, 2));
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new RuntimeException('Daily time must use HH:MM format (24-hour).');
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @param  array{enabled: bool, directory: string, time: string, last_run_at: ?string, last_run_filename: ?string, last_error: ?string}  $payload
     */
    private function writeSettings(array $payload): void
    {
        $path = $this->settingsPath();
        $directory = dirname($path);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function settingsPath(): string
    {
        return SqliteAutoBackupSettings::settingsPath();
    }
}
