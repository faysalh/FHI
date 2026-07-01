<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use stdClass;

class AccountingSqliteService
{
    private const CONNECTION = 'accounting_sqlite';

    private const CASH_SHEETS_TABLE = 'cash_daily_sheets';

    private const CASH_ROWS_TABLE = 'cash_spend_rows';

    private const TRANSFER_ROWS_TABLE = 'transfer_rows';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    public function getCashSheetForDate(string $sheetDate): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, sheet_date, opening_amount, created_at, updated_at
             FROM '.self::CASH_SHEETS_TABLE.'
             WHERE sheet_date = ?
             LIMIT 1',
            [$sheetDate]
        );
    }

    /**
     * @return array{sheet: ?object, spent: float, remaining: float, rows: list<object>}
     */
    public function cashSheetBundle(string $sheetDate): array
    {
        $sheet = $this->getCashSheetForDate($sheetDate);
        $rows = [];
        $spent = 0.0;

        if ($sheet !== null) {
            $rows = $this->listCashSpendRows((int) $sheet->id);
            foreach ($rows as $row) {
                $spent += (float) ($row->amount ?? 0);
            }
        }

        $opening = $sheet !== null ? (float) ($sheet->opening_amount ?? 0) : 0.0;

        return [
            'sheet' => $sheet,
            'spent' => $spent,
            'remaining' => $opening - $spent,
            'rows' => $rows,
        ];
    }

    public function upsertCashSheet(string $sheetDate, float $openingAmount): void
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $existing = $this->getCashSheetForDate($sheetDate);

        if ($existing !== null) {
            DB::connection(self::CONNECTION)->update(
                'UPDATE '.self::CASH_SHEETS_TABLE.'
                 SET opening_amount = ?, updated_at = ?
                 WHERE id = ?',
                [$openingAmount, $now, (int) $existing->id]
            );

            return;
        }

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::CASH_SHEETS_TABLE.' (sheet_date, opening_amount, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [$sheetDate, $openingAmount, $now, $now]
        );
    }

    /**
     * @return list<object>
     */
    public function listCashSpendRows(int $sheetId): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, sheet_id, amount, paid_to, note, sort_order, created_at, updated_at
             FROM '.self::CASH_ROWS_TABLE.'
             WHERE sheet_id = ?
             ORDER BY sort_order ASC, id ASC',
            [$sheetId]
        );
    }

    public function addCashSpendRow(int $sheetId, float $amount, string $paidTo, string $note): int
    {
        $this->ensureReady();
        $now = now()->toDateTimeString();
        $maxSort = DB::connection(self::CONNECTION)->selectOne(
            'SELECT COALESCE(MAX(sort_order), 0) AS max_sort FROM '.self::CASH_ROWS_TABLE.' WHERE sheet_id = ?',
            [$sheetId]
        );
        $sortOrder = (int) ($maxSort->max_sort ?? 0) + 1;

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::CASH_ROWS_TABLE.' (sheet_id, amount, paid_to, note, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$sheetId, $amount, $paidTo, $note, $sortOrder, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateCashSpendRow(int $rowId, float $amount, string $paidTo, string $note): void
    {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::CASH_ROWS_TABLE.'
             SET amount = ?, paid_to = ?, note = ?, updated_at = ?
             WHERE id = ?',
            [$amount, $paidTo, $note, now()->toDateTimeString(), $rowId]
        );
    }

    public function deleteCashSpendRow(int $rowId): void
    {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::CASH_ROWS_TABLE.' WHERE id = ?',
            [$rowId]
        );
    }

    public function findCashSpendRow(int $rowId): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, sheet_id, amount, paid_to, note, sort_order, created_at, updated_at
             FROM '.self::CASH_ROWS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$rowId]
        );
    }

    public function ensureCashSheetForDate(string $sheetDate): int
    {
        $sheet = $this->getCashSheetForDate($sheetDate);
        if ($sheet !== null) {
            return (int) $sheet->id;
        }

        $this->upsertCashSheet($sheetDate, 0.0);
        $sheet = $this->getCashSheetForDate($sheetDate);

        return (int) ($sheet->id ?? 0);
    }

    /**
     * @return list<object>
     */
    public function listTransferRowsForDate(string $transferDate): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, transfer_date, amount, currency, usd_rate, person_name, note, created_at, updated_at
             FROM '.self::TRANSFER_ROWS_TABLE.'
             WHERE transfer_date = ?
             ORDER BY id ASC',
            [$transferDate]
        );
    }

    public function addTransferRow(
        string $transferDate,
        float $amount,
        string $currency,
        ?float $usdRate,
        string $personName,
        string $note
    ): int {
        $this->ensureReady();
        $now = now()->toDateTimeString();

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::TRANSFER_ROWS_TABLE.' (
                transfer_date, amount, currency, usd_rate, person_name, note, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$transferDate, $amount, $currency, $usdRate, $personName, $note, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateTransferRow(
        int $rowId,
        string $transferDate,
        float $amount,
        string $currency,
        ?float $usdRate,
        string $personName,
        string $note
    ): void {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::TRANSFER_ROWS_TABLE.'
             SET transfer_date = ?, amount = ?, currency = ?, usd_rate = ?, person_name = ?, note = ?, updated_at = ?
             WHERE id = ?',
            [$transferDate, $amount, $currency, $usdRate, $personName, $note, now()->toDateTimeString(), $rowId]
        );
    }

    public function deleteTransferRow(int $rowId): void
    {
        $this->ensureReady();

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::TRANSFER_ROWS_TABLE.' WHERE id = ?',
            [$rowId]
        );
    }

    public function findTransferRow(int $rowId): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, transfer_date, amount, currency, usd_rate, person_name, note, created_at, updated_at
             FROM '.self::TRANSFER_ROWS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$rowId]
        );
    }

    public function transferIqdEquivalent(object $row): float
    {
        $currency = strtoupper(trim((string) ($row->currency ?? 'IQD')));
        $amount = (float) ($row->amount ?? 0);

        if ($currency === 'USD') {
            return $amount * (float) ($row->usd_rate ?? 0);
        }

        return $amount;
    }

    /**
     * @return list<stdClass>
     */
    public function cashSummaryForRange(string $dateFrom, string $dateTo): array
    {
        $this->ensureReady();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT
                s.sheet_date,
                s.opening_amount,
                COALESCE(SUM(r.amount), 0) AS spent_total
             FROM '.self::CASH_SHEETS_TABLE.' AS s
             LEFT JOIN '.self::CASH_ROWS_TABLE.' AS r ON r.sheet_id = s.id
             WHERE s.sheet_date >= ? AND s.sheet_date <= ?
             GROUP BY s.id, s.sheet_date, s.opening_amount
             ORDER BY s.sheet_date ASC',
            [$dateFrom, $dateTo]
        );

        return array_map(function (object $row): stdClass {
            $opening = (float) ($row->opening_amount ?? 0);
            $spent = (float) ($row->spent_total ?? 0);
            $row->remaining_total = $opening - $spent;

            return $row;
        }, $rows);
    }

    /**
     * @return list<stdClass>
     */
    public function transferSummaryForRange(string $dateFrom, string $dateTo): array
    {
        $this->ensureReady();

        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT transfer_date, amount, currency, usd_rate
             FROM '.self::TRANSFER_ROWS_TABLE.'
             WHERE transfer_date >= ? AND transfer_date <= ?
             ORDER BY transfer_date ASC, id ASC',
            [$dateFrom, $dateTo]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) ($row->transfer_date ?? '');
            if ($date === '') {
                continue;
            }
            if (! isset($byDate[$date])) {
                $byDate[$date] = (object) [
                    'transfer_date' => $date,
                    'row_count' => 0,
                    'iqd_total' => 0.0,
                    'usd_row_count' => 0,
                    'usd_amount_total' => 0.0,
                ];
            }
            $byDate[$date]->row_count++;
            $byDate[$date]->iqd_total += $this->transferIqdEquivalent($row);
            if (strtoupper((string) ($row->currency ?? '')) === 'USD') {
                $byDate[$date]->usd_row_count++;
                $byDate[$date]->usd_amount_total += (float) ($row->amount ?? 0);
            }
        }

        return array_values($byDate);
    }

    /**
     * @return list<object>
     */
    public function listTransferRowsForRange(string $dateFrom, string $dateTo): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, transfer_date, amount, currency, usd_rate, person_name, note, created_at, updated_at
             FROM '.self::TRANSFER_ROWS_TABLE.'
             WHERE transfer_date >= ? AND transfer_date <= ?
             ORDER BY transfer_date ASC, id ASC',
            [$dateFrom, $dateTo]
        );
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::CASH_SHEETS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sheet_date TEXT NOT NULL UNIQUE,
                opening_amount REAL NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::CASH_ROWS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sheet_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                paid_to TEXT NOT NULL,
                note TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (sheet_id) REFERENCES '.self::CASH_SHEETS_TABLE.'(id) ON DELETE CASCADE
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_cash_spend_rows_sheet_id ON '.self::CASH_ROWS_TABLE.' (sheet_id)'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::TRANSFER_ROWS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transfer_date TEXT NOT NULL,
                amount REAL NOT NULL,
                currency TEXT NOT NULL DEFAULT \'IQD\',
                usd_rate REAL,
                person_name TEXT NOT NULL,
                note TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_transfer_rows_date ON '.self::TRANSFER_ROWS_TABLE.' (transfer_date)'
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
        if ($directory !== '' && $directory !== '.' && ! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }
    }
}
