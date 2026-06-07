<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DamagesSqliteService
{
    private const CONNECTION = 'damages_sqlite';

    private const PACKAGING_TABLE = 'damage_item_packaging';

    private const ENTRIES_TABLE = 'damage_entries';

    private bool $schemaChecked = false;

    /**
     * @return list<object>
     */
    public function listPackaging(): array
    {
        $this->ensureSchema();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, main_item_id, item_name, pieces_per_main_unit, created_at, updated_at
             FROM '.self::PACKAGING_TABLE.'
             ORDER BY item_name ASC, id ASC'
        );
    }

    public function getPackagingForMainItem(string $mainItemId): ?object
    {
        $this->ensureSchema();
        $mainItemId = trim($mainItemId);
        if ($mainItemId === '') {
            return null;
        }

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, main_item_id, item_name, pieces_per_main_unit, created_at, updated_at
             FROM '.self::PACKAGING_TABLE.'
             WHERE main_item_id = ?
             LIMIT 1',
            [$mainItemId]
        );
    }

    public function upsertPackaging(string $mainItemId, string $itemName, int $piecesPerMainUnit): void
    {
        $this->ensureSchema();
        $now = now()->toDateTimeString();
        $mainItemId = trim($mainItemId);
        $itemName = trim($itemName);
        $piecesPerMainUnit = max(1, $piecesPerMainUnit);

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::PACKAGING_TABLE.' (main_item_id, item_name, pieces_per_main_unit, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(main_item_id) DO UPDATE SET
                item_name = excluded.item_name,
                pieces_per_main_unit = excluded.pieces_per_main_unit,
                updated_at = excluded.updated_at',
            [$mainItemId, $itemName, $piecesPerMainUnit, $now, $now]
        );
    }

    public function deletePackaging(int $id): void
    {
        $this->ensureSchema();

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::PACKAGING_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    /**
     * @param  array{date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string}  $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function entryWhereSqlAndBindings(array $filters): array
    {
        $where = ['1=1'];
        $bindings = [];
        $where[] = 'occurred_date >= ?';
        $bindings[] = $filters['date_from'];
        $where[] = 'occurred_date <= ?';
        $bindings[] = $filters['date_to'];

        $clientQ = trim((string) ($filters['client_q'] ?? ''));
        if ($clientQ !== '') {
            $where[] = 'instr(lower(client_name_snapshot), lower(?)) > 0';
            $bindings[] = $clientQ;
        }
        $itemQ = trim((string) ($filters['item_q'] ?? ''));
        if ($itemQ !== '') {
            $where[] = 'instr(lower(item_name_snapshot), lower(?)) > 0';
            $bindings[] = $itemQ;
        }

        $salesmanId = trim((string) ($filters['salesman_id'] ?? ''));
        if ($salesmanId !== '') {
            $where[] = 'salesman_id = ?';
            $bindings[] = $salesmanId;
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param  array{date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string}  $filters
     */
    public function aggregateEntryTotals(array $filters): object
    {
        $this->ensureSchema();
        [$whereSql, $bindings] = $this->entryWhereSqlAndBindings($filters);
        $db = DB::connection(self::CONNECTION);

        return $db->selectOne(
            'SELECT
                COALESCE(SUM(damaged_pieces), 0) AS sum_pieces,
                COALESCE(SUM(amount_total), 0) AS sum_amount
             FROM '.self::ENTRIES_TABLE.'
             WHERE '.$whereSql,
            $bindings
        ) ?? (object) ['sum_pieces' => 0, 'sum_amount' => 0.0];
    }

    /**
     * @param  array{date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function paginateEntries(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $this->ensureSchema();
        $perPage = max(1, min(250, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        [$whereSql, $bindings] = $this->entryWhereSqlAndBindings($filters);
        $db = DB::connection(self::CONNECTION);

        $total = (int) ($db->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::ENTRIES_TABLE.' WHERE '.$whereSql,
            $bindings
        )->c ?? 0);

        $rows = $db->select(
            'SELECT id, occurred_date, created_at, main_item_id, item_name_snapshot, client_account_id,
                    client_name_snapshot, salesman_id, salesman_name_snapshot,
                    damaged_pieces, pieces_per_main_unit, carton_price, amount_total, notes
             FROM '.self::ENTRIES_TABLE.'
             WHERE '.$whereSql.'
             ORDER BY occurred_date DESC, id DESC
             LIMIT ? OFFSET ?',
            array_merge($bindings, [$perPage, $offset])
        );

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  array{date_from: string, date_to: string, client_q: string, item_q: string, salesman_id: string}  $filters
     * @return list<object>
     */
    public function listEntriesForExport(array $filters): array
    {
        $this->ensureSchema();

        [$whereSql, $bindings] = $this->entryWhereSqlAndBindings($filters);

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, occurred_date, created_at, main_item_id, item_name_snapshot, client_account_id,
                    client_name_snapshot, salesman_id, salesman_name_snapshot,
                    damaged_pieces, pieces_per_main_unit, carton_price, amount_total, notes
             FROM '.self::ENTRIES_TABLE.'
             WHERE '.$whereSql.'
             ORDER BY occurred_date ASC, id ASC',
            $bindings
        );
    }

    public function insertEntry(
        string $occurredDate,
        string $mainItemId,
        string $itemNameSnapshot,
        string $clientAccountId,
        string $clientNameSnapshot,
        ?string $salesmanId,
        ?string $salesmanNameSnapshot,
        int $damagedPieces,
        int $piecesPerMainUnit,
        float $cartonPrice,
        float $amountTotal,
        ?string $notes
    ): void {
        $this->ensureSchema();
        $now = now()->toDateTimeString();
        $sid = $salesmanId !== null && trim($salesmanId) !== '' ? trim($salesmanId) : null;
        $sname = $salesmanNameSnapshot !== null && trim($salesmanNameSnapshot) !== '' ? trim($salesmanNameSnapshot) : null;

        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::ENTRIES_TABLE.' (
                occurred_date, created_at, main_item_id, item_name_snapshot, client_account_id, client_name_snapshot,
                salesman_id, salesman_name_snapshot,
                damaged_pieces, pieces_per_main_unit, carton_price, amount_total, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $occurredDate,
                $now,
                trim($mainItemId),
                trim($itemNameSnapshot),
                trim($clientAccountId),
                trim($clientNameSnapshot),
                $sid,
                $sname,
                max(1, $damagedPieces),
                max(1, $piecesPerMainUnit),
                $cartonPrice,
                $amountTotal,
                $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]
        );
    }

    public function deleteEntry(int $id): int
    {
        $this->ensureSchema();
        if ($id < 1) {
            return 0;
        }

        return DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::ENTRIES_TABLE.' WHERE id = ?',
            [$id]
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
            'CREATE TABLE IF NOT EXISTS '.self::PACKAGING_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                main_item_id TEXT NOT NULL UNIQUE,
                item_name TEXT NOT NULL,
                pieces_per_main_unit INTEGER NOT NULL CHECK (pieces_per_main_unit >= 1),
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $db->statement(
            'CREATE TABLE IF NOT EXISTS '.self::ENTRIES_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_date TEXT NOT NULL,
                created_at TEXT NOT NULL,
                main_item_id TEXT NOT NULL,
                item_name_snapshot TEXT NOT NULL,
                client_account_id TEXT NOT NULL,
                client_name_snapshot TEXT NOT NULL,
                salesman_id TEXT NULL,
                salesman_name_snapshot TEXT NULL,
                damaged_pieces INTEGER NOT NULL CHECK (damaged_pieces >= 1),
                pieces_per_main_unit INTEGER NOT NULL CHECK (pieces_per_main_unit >= 1),
                carton_price REAL NOT NULL,
                amount_total REAL NOT NULL,
                notes TEXT NULL
            )'
        );
        $this->migrateEntrySalesmanColumnsIfNeeded($db);
        $db->statement('CREATE INDEX IF NOT EXISTS idx_damage_entries_date ON '.self::ENTRIES_TABLE.' (occurred_date)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_damage_entries_client ON '.self::ENTRIES_TABLE.' (client_name_snapshot)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_damage_entries_item ON '.self::ENTRIES_TABLE.' (item_name_snapshot)');
        $db->statement('CREATE INDEX IF NOT EXISTS idx_damage_entries_salesman ON '.self::ENTRIES_TABLE.' (salesman_id)');

        $this->schemaChecked = true;
    }

    private function migrateEntrySalesmanColumnsIfNeeded(Connection $db): void
    {
        if (! $this->sqliteTableHasColumn($db, self::ENTRIES_TABLE, 'salesman_id')) {
            $db->statement('ALTER TABLE '.self::ENTRIES_TABLE.' ADD COLUMN salesman_id TEXT NULL');
        }
        if (! $this->sqliteTableHasColumn($db, self::ENTRIES_TABLE, 'salesman_name_snapshot')) {
            $db->statement('ALTER TABLE '.self::ENTRIES_TABLE.' ADD COLUMN salesman_name_snapshot TEXT NULL');
        }
    }

    private function sqliteTableHasColumn(Connection $db, string $table, string $column): bool
    {
        $rows = $db->select('PRAGMA table_info('.$table.')');
        foreach ($rows as $row) {
            if (($row->name ?? null) === $column) {
                return true;
            }
        }

        return false;
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
