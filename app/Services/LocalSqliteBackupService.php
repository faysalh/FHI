<?php

declare(strict_types=1);

namespace App\Services;

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
        $configured = trim((string) config('reporting.sqlite_backup_directory', ''));

        return $configured !== '' ? $configured : storage_path('app/sqlite-backups');
    }

    /**
     * @return array{filename: string, path: string, label: string}
     */
    public function createBackup(?string $databaseKey = null): array
    {
        $this->ensureBackupDirectory();

        if ($databaseKey === null || $databaseKey === '' || $databaseKey === 'all') {
            return $this->createFullBackupArchive();
        }

        $database = $this->findDatabase($databaseKey);
        $timestamp = date('Ymd-His');
        $filename = $databaseKey.'-'.$timestamp.'.sqlite';
        $destination = $this->backupDirectoryPath($filename);

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
    private function createFullBackupArchive(): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive is not available in PHP.');
        }

        $timestamp = date('Ymd-His');
        $filename = 'all-'.$timestamp.'.zip';
        $destination = $this->backupDirectoryPath($filename);
        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup archive.');
        }

        $manifest = [
            'created_at' => now()->toIso8601String(),
            'app' => (string) config('app.name', 'Reporting'),
            'databases' => [],
        ];

        foreach ($this->managedDatabases() as $database) {
            if (! $database['exists']) {
                continue;
            }
            $entryName = $database['key'].'.sqlite';
            $zip->addFile($database['path'], $entryName);
            $manifest['databases'][$database['key']] = $entryName;
        }

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

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

    private function ensureBackupDirectory(): void
    {
        $directory = $this->backupDirectory();
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    private function backupDirectoryPath(string $filename): string
    {
        return $this->backupDirectory().DIRECTORY_SEPARATOR.$this->sanitizeBackupFilename($filename);
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
            throw new RuntimeException('Could not copy database file.');
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
