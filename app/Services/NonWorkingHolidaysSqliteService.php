<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NonWorkingHolidaysSqliteService
{
    private const CONNECTION = 'deliveries_sqlite';

    private const TABLE = 'non_working_holidays';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
        $this->bootstrapFromConfigIfEmpty();
    }

    /**
     * @return list<object{id:int,holiday_date:string,label:string,created_at:string}>
     */
    public function listAll(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, holiday_date, label, created_at
             FROM '.self::TABLE.'
             ORDER BY holiday_date ASC, id ASC'
        );
    }

    /**
     * @return list<string> Y-m-d
     */
    public function listDatesForYear(int $year): array
    {
        $this->ensureReady();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT holiday_date
             FROM '.self::TABLE.'
             WHERE holiday_date LIKE ?
             ORDER BY holiday_date ASC',
            [(string) $year.'-%']
        );

        $dates = [];
        foreach ($rows as $row) {
            $d = $this->normalizeDate((string) ($row->holiday_date ?? ''));
            if ($d !== null) {
                $dates[] = $d;
            }
        }

        return $dates;
    }

    /**
     * @return list<string> Y-m-d
     */
    public function listDatesBetween(string $dateFrom, string $dateTo): array
    {
        $this->ensureReady();
        $from = $this->normalizeDate($dateFrom);
        $to = $this->normalizeDate($dateTo);
        if ($from === null || $to === null) {
            return [];
        }

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT holiday_date
             FROM '.self::TABLE.'
             WHERE holiday_date >= ? AND holiday_date <= ?
             ORDER BY holiday_date ASC',
            [$from, $to]
        );

        $dates = [];
        foreach ($rows as $row) {
            $d = $this->normalizeDate((string) ($row->holiday_date ?? ''));
            if ($d !== null) {
                $dates[] = $d;
            }
        }

        return $dates;
    }

    public function addHoliday(string $date, string $label = ''): int
    {
        $this->ensureReady();
        $date = $this->normalizeDate($date);
        if ($date === null) {
            throw new RuntimeException('A valid holiday date is required (Y-m-d).');
        }

        $label = trim($label);
        $now = now()->toDateTimeString();

        $existing = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::TABLE.' WHERE holiday_date = ? LIMIT 1',
            [$date]
        );
        if ($existing !== null) {
            throw new RuntimeException('That date is already marked as a non-working holiday.');
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TABLE.' (holiday_date, label, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [$date, $label, $now, $now]
        );

        $row = DB::connection(self::CONNECTION)->selectOne('SELECT last_insert_rowid() AS id');

        return (int) ($row->id ?? 0);
    }

    public function deleteHoliday(int $id): void
    {
        $this->ensureReady();
        if ($id <= 0) {
            return;
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TABLE.' WHERE id = ?',
            [$id]
        );
    }

    private function bootstrapFromConfigIfEmpty(): void
    {
        $count = (int) (DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::TABLE
        )->c ?? 0);

        if ($count > 0) {
            return;
        }

        $byYear = config('reporting.non_working_holidays', []);
        if (! is_array($byYear)) {
            return;
        }

        $now = now()->toDateTimeString();
        foreach ($byYear as $year => $dates) {
            if (! is_array($dates)) {
                continue;
            }
            foreach ($dates as $date) {
                $normalized = $this->normalizeDate((string) $date);
                if ($normalized === null) {
                    continue;
                }
                $exists = DB::connection(self::CONNECTION)->selectOne(
                    'SELECT id FROM '.self::TABLE.' WHERE holiday_date = ? LIMIT 1',
                    [$normalized]
                );
                if ($exists !== null) {
                    continue;
                }
                DB::connection(self::CONNECTION)->insert(
                    'INSERT INTO '.self::TABLE.' (holiday_date, label, created_at, updated_at)
                     VALUES (?, ?, ?, ?)',
                    [$normalized, 'Imported (Eid/holiday '.$year.')', $now, $now]
                );
            }
        }
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                holiday_date TEXT NOT NULL,
                label TEXT NOT NULL DEFAULT \'\',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        DB::connection(self::CONNECTION)->statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_non_working_holidays_date ON '.self::TABLE.' (holiday_date)'
        );

        $this->schemaChecked = true;
    }

    private function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
