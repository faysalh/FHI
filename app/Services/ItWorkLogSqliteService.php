<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ItWorkLogSqliteService
{
    private const CONNECTION = 'it_work_log_sqlite';

    private const TABLE = 'it_work_entries';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return list<object>
     */
    public function listEntries(
        int $userId,
        string $statusFilter = 'all',
        string $dateFrom = '',
        string $dateTo = '',
        string $q = ''
    ): array {
        $this->ensureReady();

        $sql = 'SELECT id, user_id, title, notes, started_at, finished_at, created_at, updated_at
             FROM '.self::TABLE;
        $bindings = [];
        $where = ['user_id = ?'];
        $bindings[] = $userId;

        if ($statusFilter === 'in_progress') {
            $where[] = 'finished_at IS NULL';
        } elseif ($statusFilter === 'finished') {
            $where[] = 'finished_at IS NOT NULL';
        }

        $dateFrom = trim($dateFrom);
        $dateTo = trim($dateTo);
        if ($dateFrom !== '') {
            $where[] = 'date(started_at) >= date(?)';
            $bindings[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'date(started_at) <= date(?)';
            $bindings[] = $dateTo;
        }

        $q = trim($q);
        if ($q !== '') {
            $where[] = '(title LIKE ? OR IFNULL(notes, \'\') LIKE ?)';
            $needle = '%'.$q.'%';
            $bindings[] = $needle;
            $bindings[] = $needle;
        }

        $sql .= ' WHERE '.implode(' AND ', $where);
        $sql .= ' ORDER BY CASE WHEN finished_at IS NULL THEN 0 ELSE 1 END ASC, datetime(started_at) DESC, id DESC';

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function findForUser(int $entryId, int $userId): ?object
    {
        $this->ensureReady();

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, user_id, title, notes, started_at, finished_at, created_at, updated_at
             FROM '.self::TABLE.'
             WHERE id = ? AND user_id = ?',
            [$entryId, $userId]
        );

        return $row ?: null;
    }

    public function startEntry(int $userId, string $title, ?string $notes): int
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (
                user_id, title, notes, started_at, finished_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$userId, $title, $notes, $now, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function finishEntry(int $entryId, int $userId): bool
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();

        $affected = DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET finished_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ? AND finished_at IS NULL',
            [$now, $now, $entryId, $userId]
        );

        return $affected > 0;
    }

    public function updateEntry(int $entryId, int $userId, string $title, ?string $notes): bool
    {
        $this->ensureReady();
        $notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;

        $affected = DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET title = ?, notes = ?, updated_at = ?
             WHERE id = ? AND user_id = ?',
            [$title, $notes, now()->toDateTimeString(), $entryId, $userId]
        );

        return $affected > 0;
    }

    public function deleteEntry(int $entryId, int $userId): bool
    {
        $this->ensureReady();

        $affected = DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TABLE.' WHERE id = ? AND user_id = ?',
            [$entryId, $userId]
        );

        return $affected > 0;
    }

    /**
     * Human-readable duration between started_at and finished_at (or now if still open).
     */
    public static function formatDuration(?string $startedAt, ?string $finishedAt): string
    {
        $startedAt = trim((string) $startedAt);
        if ($startedAt === '') {
            return '';
        }

        try {
            $start = \Carbon\Carbon::parse($startedAt);
            $end = trim((string) $finishedAt) !== ''
                ? \Carbon\Carbon::parse((string) $finishedAt)
                : now();
        } catch (\Throwable) {
            return '';
        }

        $totalSeconds = (int) max(0, (int) round($end->diffInSeconds($start, true)));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }
        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();

        $db = DB::connection(self::CONNECTION);
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                notes TEXT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_it_work_entries_user_finished
             ON '.self::TABLE.' (user_id, finished_at)'
        );
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_it_work_entries_user_started
             ON '.self::TABLE.' (user_id, started_at)'
        );

        $this->schemaChecked = true;
    }

    private function ensureDatabaseFileExists(): void
    {
        /** @var mixed $configuredPath */
        $configuredPath = config('database.connections.'.self::CONNECTION.'.database');
        $path = trim((string) $configuredPath);
        if ($path === '' || $path === ':memory:') {
            return;
        }

        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && ! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        if (! File::exists($path)) {
            File::put($path, '');
        }
    }
}
