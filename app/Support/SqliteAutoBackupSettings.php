<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

final class SqliteAutoBackupSettings
{
    private const SETTINGS_RELATIVE = 'app/sqlite-auto-backup.json';

    public static function settingsPath(): string
    {
        return storage_path(self::SETTINGS_RELATIVE);
    }

    public static function configuredDirectory(): string
    {
        $path = self::settingsPath();
        if (! File::exists($path)) {
            return '';
        }

        $raw = json_decode((string) File::get($path), true);
        if (! is_array($raw)) {
            return '';
        }

        return self::normalizeDirectory((string) ($raw['directory'] ?? ''));
    }

    public static function normalizeDirectory(string $directory): string
    {
        $directory = trim(str_replace('/', '\\', $directory));

        return rtrim($directory, '\\');
    }
}
