<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\SqliteAutoBackupSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class LocalSqliteBackupService
{
    private const SQLITE_MAGIC = 'SQLite format 3';

    /**
     * @return list<array{key: string, label: string, connection: string, path: string, exists: bool, size: int, modified_at: ?string}>
     */
    public function managedDatabases(): array
    {
        $definitions = (array) config('reporting.sqlite_databases', []);
        $out = [];

        foreach ($definitions as $key => $definition) {
            $connection = (string) ($definition['connection'] ?? '');
            $path = $this->resolveDatabasePath($connection);
            $exists = $path !== '' && File::exists($path);
            $size = $exists ? (int) File::size($path) : 0;
            $modifiedAt = $exists ? date('Y-m-d H:i:s', (int) File::lastModified($path)) : null;

            $out[] = [
                'key' => (string) $key,
                'label' => (string) ($definition['label'] ?? $key),
                'connection' => $connection,
                'path' => $path,
                'exists' => $exists,
                'size' => $size,
                'modified_at' => $modifiedAt,
            ];
        }

        return $out;
    }

    public function backupDirectory(): string
    {
        $autoDirectory = SqliteAutoBackupSettings::configuredDirectory();
        if ($autoDirectory !== '') {
            return $autoDirectory;
        }

        $configured = trim((string) config('reporting.sqlite_backup_directory', ''));
        if ($configured !== '') {
            return $this->resolveConfiguredPath($configured);
        }

        return storage_path('app/sqlite-backups');
    }

    public function defaultBackupDirectory(): string
    {
        $configured = trim((string) config('reporting.sqlite_backup_directory', ''));
        if ($configured !== '') {
            return $this->resolveConfiguredPath($configured);
        }

        return storage_path('app/sqlite-backups');
    }

    /**
     * @return array{filename: string, path: string, label: string}
     */
    public function createBackup(?string $databaseKey = null, ?string $targetDirectory = null): array
    {
        $directory = $this->resolveBackupDirectory($targetDirectory);
        $this->ensureBackupDirectoryAt($directory);

        if ($databaseKey === null || $databaseKey === '' || $databaseKey === 'all') {
            return $this->createFullBackupArchive($directory);
        }

        $database = $this->findDatabase($databaseKey);
        $timestamp = date('Ymd-His');
        $filename = $databaseKey.'-'.$timestamp.'.sqlite';
        $destination = $this->backupDirectoryPath($filename, $directory);

        if (! $database['exists']) {
            throw new RuntimeException('Database file does not exist yet: '.$database['label'].'.');
        }

        $this->copyDatabaseFile($database['path'], $destination);

        return [
            'filename' => $filename,
            'path' => $destination,
            'label' => $database['label'],
        ];
    }

    /**
     * @return list<array{filename: string, path: string, kind: string, database_key: ?string, size: int, modified_at: string}>
     */
    public function listBackups(): array
    {
        $directory = $this->backupDirectory();
        if (! File::isDirectory($directory)) {
            return [];
        }

        $keys = array_keys((array) config('reporting.sqlite_databases', []));
        $files = File::files($directory);
        $rows = [];

        foreach ($files as $file) {
            $filename = $file->getFilename();
            if (! $this->isAllowedBackupFilename($filename)) {
                continue;
            }

            $kind = str_ends_with(strtolower($filename), '.zip') ? 'archive' : 'database';
            $databaseKey = null;
            if ($kind === 'database') {
                foreach ($keys as $key) {
                    if (str_starts_with($filename, $key.'-')) {
                        $databaseKey = $key;
                        break;
                    }
                }
            }

            $rows[] = [
                'filename' => $filename,
                'path' => $file->getPathname(),
                'kind' => $kind,
                'database_key' => $databaseKey,
                'size' => (int) $file->getSize(),
                'modified_at' => date('Y-m-d H:i:s', (int) $file->getMTime()),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['modified_at'], $a['modified_at']));

        return $rows;
    }

    public function resolveStoredBackupPath(string $filename): string
    {
        $safeName = $this->sanitizeBackupFilename($filename);
        $path = $this->backupDirectoryPath($safeName);
        if (! File::exists($path)) {
            throw new RuntimeException('Backup file not found.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    public function restoreFromUpload(UploadedFile $uploadedFile, string $databaseKey): array
    {
        $database = $this->findDatabase($databaseKey);
        $tempPath = $uploadedFile->getRealPath();
        if ($tempPath === false || ! File::exists($tempPath)) {
            throw new RuntimeException('Uploaded file could not be read.');
        }

        $this->assertValidSqliteFile($tempPath);

        return [$this->restoreDatabaseFromPath($database, $tempPath)];
    }

    /**
     * @return list<string>
     */
    public function restoreFromStoredBackup(string $filename, ?string $databaseKey = null): array
    {
        $path = $this->resolveStoredBackupPath($filename);
        $lower = strtolower($filename);

        if (str_ends_with($lower, '.zip')) {
            return $this->restoreFromArchivePath($path, $databaseKey);
        }

        if ($databaseKey === null || $databaseKey === '') {
            throw new RuntimeException('Choose which database to restore into.');
        }

        $database = $this->findDatabase($databaseKey);
        $this->assertValidSqliteFile($path);

        return [$this->restoreDatabaseFromPath($database, $path)];
    }

    public function deleteStoredBackup(string $filename): void
    {
        $path = $this->resolveStoredBackupPath($filename);
        File::delete($path);
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / (1024 * 1024), 2).' MB';
    }

    /**
     * @return array{filename: string, path: string, label: string}
     */
    private function createFullBackupArchive(string $directory): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available in PHP. Enable the php_zip extension.');
        }

        $timestamp = date('Ymd-His');
        $filename = 'all-'.$timestamp.'.zip';
        $destination = $this->backupDirectoryPath($filename, $directory);
        $zip = new ZipArchive;
        $opened = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new RuntimeException($this->zipOpenFailureMessage($destination, $zip, is_int($opened) ? $opened : null));
        }

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'app' => (string) config('app.name', 'Reporting'),
            'databases' => [],
        ];

        $tempDir = storage_path('app/temp/sqlite-backup-'.uniqid('', true));
        File::makeDirectory($tempDir, 0755, true);

        try {
            foreach ($this->managedDatabases() as $database) {
                if (! $database['exists']) {
                    continue;
                }

                $entryName = $database['key'].'.sqlite';
                $tempCopy = $tempDir.DIRECTORY_SEPARATOR.$entryName;
                $this->copyDatabaseFile($database['path'], $tempCopy);

                if (! $zip->addFile($tempCopy, $entryName)) {
                    throw new RuntimeException('Could not add '.$database['label'].' to the backup archive.');
                }

                $manifest['databases'][$database['key']] = $entryName;
            }

            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException('Could not finalize backup archive in '.$directory.'.');
            }
        } finally {
            File::deleteDirectory($tempDir);
        }

        if ($manifest['databases'] === []) {
            File::delete($destination);
            throw new RuntimeException('No local SQLite database files exist yet to back up.');
        }

        return [
            'filename' => $filename,
            'path' => $destination,
            'label' => 'All local databases',
        ];
    }

    /**
     * @return list<string>
     */
    private function restoreFromArchivePath(string $archivePath, ?string $databaseKey): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available in PHP.');
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('Could not open backup archive.');
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        $entries = is_array($manifest['databases'] ?? null) ? $manifest['databases'] : [];

        if ($entries === []) {
            $zip->close();
            throw new RuntimeException('Backup archive is missing database manifest.');
        }

        $targets = $databaseKey !== null && $databaseKey !== ''
            ? [$databaseKey => ($entries[$databaseKey] ?? null)]
            : $entries;

        $restored = [];
        $tempDir = storage_path('app/temp/sqlite-restore-'.uniqid('', true));
        File::makeDirectory($tempDir, 0755, true);

        try {
            foreach ($targets as $key => $entryName) {
                if (! is_string($entryName) || $entryName === '') {
                    $zip->close();
                    throw new RuntimeException('Backup archive does not contain the selected database.');
                }

                $extractPath = $tempDir.DIRECTORY_SEPARATOR.basename($entryName);
                $contents = $zip->getFromName($entryName);
                if (! is_string($contents) || $contents === '') {
                    $zip->close();
                    throw new RuntimeException('Could not read '.$entryName.' from backup archive.');
                }
                File::put($extractPath, $contents);
                $this->assertValidSqliteFile($extractPath);

                $database = $this->findDatabase((string) $key);
                $restored[] = $this->restoreDatabaseFromPath($database, $extractPath);
            }
        } finally {
            $zip->close();
            File::deleteDirectory($tempDir);
        }

        return $restored;
    }

    /**
     * @param  array{key: string, label: string, connection: string, path: string, exists: bool, size: int, modified_at: ?string}  $database
     */
    private function restoreDatabaseFromPath(array $database, string $sourcePath): string
    {
        $this->ensureDatabaseDirectory($database['path']);

        if ($database['exists']) {
            $preRestoreName = $database['key'].'-pre-restore-'.date('Ymd-His').'.sqlite';
            $this->copyDatabaseFile($database['path'], $this->backupDirectoryPath($preRestoreName));
        }

        $this->disconnectConnection($database['connection']);
        $this->copyDatabaseFile($sourcePath, $database['path']);
        $this->disconnectConnection($database['connection']);

        return $database['label'];
    }

    /**
     * @return array{key: string, label: string, connection: string, path: string, exists: bool, size: int, modified_at: ?string}
     */
    private function findDatabase(string $databaseKey): array
    {
        foreach ($this->managedDatabases() as $database) {
            if ($database['key'] === $databaseKey) {
                return $database;
            }
        }

        throw new RuntimeException('Unknown database selection.');
    }

    private function resolveDatabasePath(string $connection): string
    {
        if ($connection === '') {
            return '';
        }

        return trim((string) config('database.connections.'.$connection.'.database', ''));
    }

    private function resolveBackupDirectory(?string $targetDirectory): string
    {
        $targetDirectory = SqliteAutoBackupSettings::normalizeDirectory((string) $targetDirectory);

        return $targetDirectory !== '' ? $targetDirectory : $this->backupDirectory();
    }

    private function resolveConfiguredPath(string $path): string
    {
        $path = SqliteAutoBackupSettings::normalizeDirectory($path);
        if ($path === '') {
            return storage_path('app/sqlite-backups');
        }

        if (preg_match('/^[a-zA-Z]:\\\\|^\\\\/u', $path) === 1) {
            return $path;
        }

        return storage_path(str_replace('\\', '/', $path));
    }

    private function ensureBackupDirectoryAt(string $directory): void
    {
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    private function backupDirectoryPath(string $filename, ?string $directory = null): string
    {
        $directory ??= $this->backupDirectory();

        return $directory.DIRECTORY_SEPARATOR.$this->sanitizeBackupFilename($filename);
    }

    private function zipOpenFailureMessage(string $destination, ZipArchive $zip, ?int $statusCode): string
    {
        $directory = dirname($destination);
        $statusText = trim($zip->getStatusString());
        $message = 'Could not create backup archive at '.$destination.'.';

        if ($statusText !== '') {
            $message .= ' '.$statusText;
        } elseif ($statusCode !== null) {
            $message .= ' Zip error code: '.$statusCode;
        }

        return $message.' Check that '.$directory.' is writable by IIS AppPool\\ReportingApp.';
    }

    private function sanitizeBackupFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        if ($basename === '' || $basename === '.' || $basename === '..') {
            throw new RuntimeException('Invalid backup filename.');
        }
        if (! $this->isAllowedBackupFilename($basename)) {
            throw new RuntimeException('Backup filename is not allowed.');
        }

        return $basename;
    }

    private function isAllowedBackupFilename(string $filename): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]+\.(sqlite|zip)$/i', $filename);
    }

    private function assertValidSqliteFile(string $path): void
    {
        if (! File::exists($path) || File::size($path) < 16) {
            throw new RuntimeException('File is not a valid SQLite database.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('File could not be opened for validation.');
        }

        $header = fread($handle, 16);
        fclose($handle);

        if (! is_string($header) || ! str_starts_with($header, self::SQLITE_MAGIC)) {
            throw new RuntimeException('File is not a valid SQLite database.');
        }
    }

    private function copyDatabaseFile(string $source, string $destination): void
    {
        $directory = dirname($destination);
        if ($directory !== '' && $directory !== '.' && ! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! @copy($source, $destination)) {
            $lastError = error_get_last();
            $details = is_array($lastError) ? (string) ($lastError['message'] ?? '') : '';

            throw new RuntimeException(
                'Could not copy database file'.($details !== '' ? ': '.$details : '.')
            );
        }
    }

    private function ensureDatabaseDirectory(string $path): void
    {
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && ! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    private function disconnectConnection(string $connection): void
    {
        DB::disconnect($connection);
        DB::purge($connection);
    }
}
