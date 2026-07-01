<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class OperationsTasksSqliteService
{
    private const CONNECTION = 'operations_tasks_sqlite';

    private const TABLE = 'operations_tasks';

    public const MIN_RECURRENCE_MINUTES = 10;

    public const MAX_RECURRENCE_MINUTES = 10080;

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return list<object>
     */
    public function listTasks(
        string $clientFilter = '',
        string $statusFilter = 'all',
        string $sort = 'updated'
    ): array {
        $this->ensureReady();

        $sql = 'SELECT id, client_account_id, client_name, notes, recurrence_minutes, is_active, completed_at, last_notified_at, created_at, updated_at
             FROM '.self::TABLE;
        $bindings = [];
        $where = [];

        $clientFilter = trim($clientFilter);
        if ($clientFilter !== '') {
            $where[] = '(client_name LIKE ? OR client_account_id LIKE ?)';
            $needle = '%'.$clientFilter.'%';
            $bindings[] = $needle;
            $bindings[] = $needle;
        }

        if ($statusFilter === 'active') {
            $where[] = 'is_active = 1';
        } elseif ($statusFilter === 'completed') {
            $where[] = 'is_active = 0';
        }

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $order = match ($sort) {
            'client' => 'client_name COLLATE NOCASE ASC, id DESC',
            'created' => 'datetime(created_at) DESC, id DESC',
            'recurrence' => 'recurrence_minutes ASC, client_name COLLATE NOCASE ASC',
            default => 'is_active DESC, datetime(updated_at) DESC, id DESC',
        };
        $sql .= ' ORDER BY '.$order;

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function createTask(string $clientAccountId, string $clientName, string $notes, int $recurrenceMinutes): int
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $recurrenceMinutes = $this->normalizeRecurrenceMinutes($recurrenceMinutes);

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (
                client_account_id, client_name, notes, recurrence_minutes, is_active, last_notified_at, created_at, updated_at
            ) VALUES (?, ?, ?, ?, 1, NULL, ?, ?)',
            [$clientAccountId, $clientName, $notes, $recurrenceMinutes, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateTask(int $taskId, string $notes, int $recurrenceMinutes, bool $isActive): void
    {
        $this->ensureReady();
        $completedAt = $isActive ? null : now()->toDateTimeString();
        $recurrenceMinutes = $this->normalizeRecurrenceMinutes($recurrenceMinutes);

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET notes = ?, recurrence_minutes = ?, is_active = ?, completed_at = ?, updated_at = ?
             WHERE id = ?',
            [$notes, $recurrenceMinutes, $isActive ? 1 : 0, $completedAt, now()->toDateTimeString(), $taskId]
        );
    }

    public function completeTask(int $taskId): void
    {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET is_active = 0, completed_at = ?, updated_at = ?
             WHERE id = ?',
            [now()->toDateTimeString(), now()->toDateTimeString(), $taskId]
        );
    }

    public function deleteTask(int $taskId): void
    {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TABLE.' WHERE id = ?',
            [$taskId]
        );
    }

    /**
     * @param  array<string, string>  $clientsWithInvoiceToday account_id => client_name
     * @return list<object>
     */
    public function findDueTasks(array $clientsWithInvoiceToday): array
    {
        $this->ensureReady();
        $clientIndex = $this->normalizeClientIndex($clientsWithInvoiceToday);
        if ($clientIndex === []) {
            return [];
        }

        $tasks = DB::connection(self::CONNECTION)->select(
            'SELECT id, client_account_id, client_name, notes, recurrence_minutes, is_active, last_notified_at
             FROM '.self::TABLE.'
             WHERE is_active = 1
             ORDER BY id ASC'
        );

        $now = now();
        $due = [];

        foreach ($tasks as $task) {
            $accountId = $this->normalizeAccountId((string) ($task->client_account_id ?? ''));
            if ($accountId === '' || ! isset($clientIndex[$accountId])) {
                continue;
            }

            if (! $this->taskIsDueForNotification($task, $now)) {
                continue;
            }

            $task->client_name = $clientIndex[$accountId];
            $due[] = $task;
        }

        return $due;
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function markTasksNotified(array $taskIds): void
    {
        $this->ensureReady();
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds), static fn (int $id): bool => $id > 0)));
        if ($taskIds === []) {
            return;
        }

        $marks = implode(',', array_fill(0, count($taskIds), '?'));
        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.'
             SET last_notified_at = ?, updated_at = ?
             WHERE id IN ('.$marks.') AND is_active = 1',
            array_merge([now()->toDateTimeString(), now()->toDateTimeString()], $taskIds)
        );
    }

    public function normalizeAccountId(string $accountId): string
    {
        $accountId = trim($accountId);

        return $accountId === '' ? '' : mb_strtolower($accountId);
    }

    /**
     * @param  array<string, string>  $clientsWithInvoiceToday
     * @return array<string, string>
     */
    public function normalizeClientIndex(array $clientsWithInvoiceToday): array
    {
        $index = [];
        foreach ($clientsWithInvoiceToday as $accountId => $clientName) {
            $key = $this->normalizeAccountId((string) $accountId);
            if ($key === '') {
                continue;
            }
            $index[$key] = trim((string) $clientName) !== '' ? trim((string) $clientName) : $key;
        }

        return $index;
    }

    /**
     * @param  array<string, string>  $clientsWithInvoiceToday account_id => client_name
     * @return list<object>
     *
     * @deprecated Use findDueTasks() and markTasksNotified() so the browser can ack after showing a notification.
     */
    public function claimDueTasks(array $clientsWithInvoiceToday): array
    {
        $due = $this->findDueTasks($clientsWithInvoiceToday);
        $dueIds = array_map(static fn (object $task): int => (int) ($task->id ?? 0), $due);
        $this->markTasksNotified($dueIds);

        return $due;
    }

    private function taskIsDueForNotification(object $task, \Carbon\CarbonInterface $now): bool
    {
        $minutes = $this->normalizeRecurrenceMinutes((int) ($task->recurrence_minutes ?? 60));
        $lastRaw = trim((string) ($task->last_notified_at ?? ''));
        if ($lastRaw === '') {
            return true;
        }

        try {
            return \Carbon\Carbon::parse($lastRaw)->addMinutes($minutes)->lte($now);
        } catch (\Throwable) {
            return true;
        }
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
                client_account_id TEXT NOT NULL,
                client_name TEXT NOT NULL,
                notes TEXT NOT NULL,
                recurrence_minutes INTEGER NOT NULL CHECK (recurrence_minutes >= '.self::MIN_RECURRENCE_MINUTES.' AND recurrence_minutes <= '.self::MAX_RECURRENCE_MINUTES.'),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                completed_at TEXT NULL,
                last_notified_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->migrateRecurrenceMinimumIfNeeded($db);
        if (! $this->tableHasColumn('completed_at')) {
            $db->statement('ALTER TABLE '.self::TABLE.' ADD COLUMN completed_at TEXT NULL');
        }
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_operations_tasks_client_active
             ON '.self::TABLE.' (client_account_id, is_active)'
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

    private function tableHasColumn(string $column): bool
    {
        $columns = DB::connection(self::CONNECTION)->select('PRAGMA table_info('.self::TABLE.')');
        foreach ($columns as $col) {
            if (strcasecmp((string) ($col->name ?? ''), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRecurrenceMinutes(int $recurrenceMinutes): int
    {
        return max(self::MIN_RECURRENCE_MINUTES, min(self::MAX_RECURRENCE_MINUTES, $recurrenceMinutes));
    }

    private function migrateRecurrenceMinimumIfNeeded(\Illuminate\Database\Connection $db): void
    {
        $definition = $db->selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            [self::TABLE]
        );
        $sql = strtolower((string) ($definition->sql ?? ''));
        if ($sql === '' || ! str_contains($sql, 'recurrence_minutes >= 30')) {
            return;
        }

        $db->statement(
            'CREATE TABLE operations_tasks_recurrence_migration (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_account_id TEXT NOT NULL,
                client_name TEXT NOT NULL,
                notes TEXT NOT NULL,
                recurrence_minutes INTEGER NOT NULL CHECK (recurrence_minutes >= '.self::MIN_RECURRENCE_MINUTES.' AND recurrence_minutes <= '.self::MAX_RECURRENCE_MINUTES.'),
                is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
                completed_at TEXT NULL,
                last_notified_at TEXT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'INSERT INTO operations_tasks_recurrence_migration (
                id, client_account_id, client_name, notes, recurrence_minutes, is_active, completed_at, last_notified_at, created_at, updated_at
            )
            SELECT
                id, client_account_id, client_name, notes, recurrence_minutes, is_active, completed_at, last_notified_at, created_at, updated_at
            FROM '.self::TABLE
        );
        $db->statement('DROP TABLE '.self::TABLE);
        $db->statement('ALTER TABLE operations_tasks_recurrence_migration RENAME TO '.self::TABLE);
        $db->statement(
            'CREATE INDEX IF NOT EXISTS idx_operations_tasks_client_active
             ON '.self::TABLE.' (client_account_id, is_active)'
        );
    }
}

