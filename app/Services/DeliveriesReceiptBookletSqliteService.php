<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ReceiptBookletRanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DeliveriesReceiptBookletSqliteService
{
    private const CONNECTION = 'deliveries_sqlite';

    private const TABLE = 'delivery_receipt_booklets';

    private bool $schemaChecked = false;

    /**
     * @return list<object>
     */
    public function listAssignedActive(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, start_number, end_number, assigned_driver, returned_at, created_at
             FROM '.self::TABLE.'
             WHERE assigned_driver IS NOT NULL AND assigned_driver != \'\' AND returned_at IS NULL
             ORDER BY start_number ASC'
        );
    }

    /**
     * @return list<object>
     */
    public function listUnassigned(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, start_number, end_number, assigned_driver, returned_at, created_at
             FROM '.self::TABLE.'
             WHERE (assigned_driver IS NULL OR assigned_driver = \'\') AND returned_at IS NULL
             ORDER BY start_number ASC'
        );
    }

    /**
     * @return list<object>
     */
    public function listReturned(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, start_number, end_number, assigned_driver, returned_at, created_at
             FROM '.self::TABLE.'
             WHERE returned_at IS NOT NULL
             ORDER BY returned_at DESC, start_number ASC'
        );
    }

    /**
     * @return array{added: int, skipped: int}
     */
    public function addBookletsFromRange(int $firstNumber, int $lastNumber): array
    {
        $this->ensureSchema();
        $ranges = ReceiptBookletRanges::split($firstNumber, $lastNumber);
        $added = 0;
        $skipped = 0;
        $now = now()->toDateTimeString();

        foreach ($ranges as $range) {
            $start = (int) $range['start'];
            $end = (int) $range['end'];
            $exists = DB::connection(self::CONNECTION)->selectOne(
                'SELECT id FROM '.self::TABLE.' WHERE start_number = ? LIMIT 1',
                [$start]
            );
            if ($exists !== null) {
                $skipped++;

                continue;
            }

            DB::connection(self::CONNECTION)->insert(
                'INSERT INTO '.self::TABLE.' (start_number, end_number, assigned_driver, returned_at, created_at)
                 VALUES (?, ?, NULL, NULL, ?)',
                [$start, $end, $now]
            );
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function assignByStartNumber(int $startNumber, string $driverName): void
    {
        $this->ensureSchema();
        $driverName = trim($driverName);
        if ($driverName === '') {
            throw new \InvalidArgumentException('Driver name is required.');
        }

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, assigned_driver, returned_at
             FROM '.self::TABLE.'
             WHERE start_number = ?
             LIMIT 1',
            [$startNumber]
        );

        if ($row === null) {
            throw new \InvalidArgumentException('No receipt booklet found with that starting number.');
        }

        if ($row->returned_at !== null && (string) $row->returned_at !== '') {
            throw new \InvalidArgumentException('That receipt booklet was returned and cannot be assigned again.');
        }

        if ($row->assigned_driver !== null && trim((string) $row->assigned_driver) !== '') {
            throw new \InvalidArgumentException('That receipt booklet is already assigned.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.' SET assigned_driver = ? WHERE id = ?',
            [$driverName, (int) $row->id]
        );
    }

    public function markReturned(int $bookletId): void
    {
        $this->ensureSchema();

        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, assigned_driver, returned_at FROM '.self::TABLE.' WHERE id = ? LIMIT 1',
            [$bookletId]
        );

        if ($row === null) {
            throw new \InvalidArgumentException('Receipt booklet not found.');
        }

        if ($row->returned_at !== null && (string) $row->returned_at !== '') {
            throw new \InvalidArgumentException('That receipt booklet is already marked returned.');
        }

        if ($row->assigned_driver === null || trim((string) $row->assigned_driver) === '') {
            throw new \InvalidArgumentException('Only assigned receipt booklets can be returned.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TABLE.' SET returned_at = ? WHERE id = ?',
            [now()->toDateTimeString(), $bookletId]
        );
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
                start_number INTEGER NOT NULL,
                end_number INTEGER NOT NULL,
                assigned_driver TEXT NULL,
                returned_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_delivery_receipt_booklets_start
             ON '.self::TABLE.' (start_number)'
        );

        $this->schemaChecked = true;
    }

    private function ensureDatabaseFileExists(): void
    {
        /** @var mixed $configuredPath */
        $configuredPath = config('database.connections.'.self::CONNECTION.'.database');
        $path = trim((string) $configuredPath);
        if ($path === '') {
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
