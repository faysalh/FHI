<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class ManufacturingSqliteService
{
    private const CONNECTION = 'manufacturing_sqlite';

    private const ITEMS_TABLE = 'manufacturing_items';

    private const PURCHASES_TABLE = 'manufacturing_purchases';

    private const EXPORTS_TABLE = 'manufacturing_exports';

    private bool $schemaChecked = false;

    public function ensureReady(): void
    {
        $this->ensureSchema();
    }

    /**
     * @return list<object>
     */
    public function listItems(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT id, name, code, unit, created_at, updated_at
             FROM '.self::ITEMS_TABLE.'
             ORDER BY name COLLATE NOCASE ASC, id ASC'
        );
    }

    public function findItem(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT id, name, code, unit, created_at, updated_at
             FROM '.self::ITEMS_TABLE.'
             WHERE id = ?
             LIMIT 1',
            [$id]
        );
    }

    public function addItem(string $name, string $unit, ?string $code = null): int
    {
        $this->ensureReady();
        $name = trim($name);
        $unit = trim($unit);
        $code = $this->normalizeCode($code);
        if ($name === '' || $unit === '') {
            throw new InvalidArgumentException('Item name and unit are required.');
        }

        $existing = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::ITEMS_TABLE.' WHERE lower(name) = lower(?) LIMIT 1',
            [$name]
        );
        if ($existing !== null) {
            throw new InvalidArgumentException('An item with this name already exists.');
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::ITEMS_TABLE.' (name, code, unit, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)',
            [$name, $code, $unit, $now, $now]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateItem(int $id, string $name, string $unit, ?string $code = null): void
    {
        $this->ensureReady();
        $name = trim($name);
        $unit = trim($unit);
        $code = $this->normalizeCode($code);
        if ($name === '' || $unit === '') {
            throw new InvalidArgumentException('Item name and unit are required.');
        }

        $item = $this->findItem($id);
        if ($item === null) {
            throw new InvalidArgumentException('Item not found.');
        }

        $dup = DB::connection(self::CONNECTION)->selectOne(
            'SELECT id FROM '.self::ITEMS_TABLE.' WHERE lower(name) = lower(?) AND id <> ? LIMIT 1',
            [$name, $id]
        );
        if ($dup !== null) {
            throw new InvalidArgumentException('An item with this name already exists.');
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::ITEMS_TABLE.'
             SET name = ?, code = ?, unit = ?, updated_at = ?
             WHERE id = ?',
            [$name, $code, $unit, now()->toDateTimeString(), $id]
        );
    }

    public function deleteItem(int $id): void
    {
        $this->ensureReady();
        $item = $this->findItem($id);
        if ($item === null) {
            throw new InvalidArgumentException('Item not found.');
        }

        $purchaseCount = (int) (DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::PURCHASES_TABLE.' WHERE item_id = ?',
            [$id]
        )->c ?? 0);
        $exportCount = (int) (DB::connection(self::CONNECTION)->selectOne(
            'SELECT COUNT(*) AS c FROM '.self::EXPORTS_TABLE.' WHERE item_id = ?',
            [$id]
        )->c ?? 0);

        if ($purchaseCount > 0 || $exportCount > 0) {
            throw new InvalidArgumentException('Cannot delete an item that has purchases or exports. Remove those rows first.');
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::ITEMS_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    /**
     * Import items from a CSV file. When $updateExisting is false, duplicate names
     * (case-insensitive) vs existing SQLite rows or earlier CSV rows are skipped.
     * When true, existing items are updated (unit and optional code) by name match.
     *
     * Expected header columns (order flexible): name, code (optional), unit.
     *
     * @return array{added: int, updated: int, skipped_duplicates: int, skipped_invalid: int}
     */
    public function importItemsFromCsv(string $path, bool $updateExisting = false): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('The uploaded CSV could not be read.');
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new InvalidArgumentException('The CSV file is empty.');
            }

            $map = $this->csvHeaderMap($header);
            if (! isset($map['name']) || ! isset($map['unit'])) {
                throw new InvalidArgumentException(
                    'CSV must include a header row with name and unit columns (code is optional). Example: name,code,unit'
                );
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                if ($this->csvRowIsEmpty($row)) {
                    continue;
                }

                $rows[] = [
                    'name' => trim((string) ($row[$map['name']] ?? '')),
                    'unit' => trim((string) ($row[$map['unit']] ?? '')),
                    'code' => isset($map['code']) ? trim((string) ($row[$map['code']] ?? '')) : '',
                ];
            }
        } finally {
            fclose($handle);
        }

        return $this->importItemRows($rows, $updateExisting);
    }

    /**
     * Import items from pasted text (one item per line).
     * Each line: name, unit  or  name, code, unit
     *
     * @return array{added: int, updated: int, skipped_duplicates: int, skipped_invalid: int}
     */
    public function importItemsFromText(string $text, bool $updateExisting = false): array
    {
        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map(static fn (string $part): string => trim($part), str_getcsv($line));
            if (count($parts) === 2) {
                $rows[] = ['name' => $parts[0], 'code' => '', 'unit' => $parts[1]];
            } elseif (count($parts) >= 3) {
                $rows[] = ['name' => $parts[0], 'code' => $parts[1], 'unit' => $parts[2]];
            } else {
                $rows[] = ['name' => $parts[0] ?? '', 'code' => '', 'unit' => ''];
            }
        }

        return $this->importItemRows($rows, $updateExisting);
    }

    /**
     * @param  list<array{name: string, code: string, unit: string}>  $rows
     * @return array{added: int, updated: int, skipped_duplicates: int, skipped_invalid: int}
     */
    public function importItemRows(array $rows, bool $updateExisting = false): array
    {
        $this->ensureReady();

        $added = 0;
        $updated = 0;
        $skippedDuplicates = 0;
        $skippedInvalid = 0;
        $seenInFile = [];
        $existing = $this->existingItemsByNameLower();

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? ''));
            $code = trim((string) ($row['code'] ?? ''));

            if ($name === '' || $unit === '') {
                $skippedInvalid++;

                continue;
            }
            if (mb_strlen($name) > 500 || mb_strlen($unit) > 100 || mb_strlen($code) > 100) {
                $skippedInvalid++;

                continue;
            }

            $key = mb_strtolower($name);
            if (isset($seenInFile[$key])) {
                $skippedDuplicates++;

                continue;
            }
            $seenInFile[$key] = true;

            if (isset($existing[$key])) {
                if (! $updateExisting) {
                    $skippedDuplicates++;

                    continue;
                }

                $now = now()->toDateTimeString();
                DB::connection(self::CONNECTION)->update(
                    'UPDATE '.self::ITEMS_TABLE.' SET unit = ?, code = ?, updated_at = ? WHERE id = ?',
                    [$unit, $this->normalizeCode($code), $now, $existing[$key]]
                );
                $updated++;

                continue;
            }

            $now = now()->toDateTimeString();
            DB::connection(self::CONNECTION)->insert(
                'INSERT INTO '.self::ITEMS_TABLE.' (name, code, unit, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$name, $this->normalizeCode($code), $unit, $now, $now]
            );
            $existing[$key] = (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
            $added++;
        }

        return [
            'added' => $added,
            'updated' => $updated,
            'skipped_duplicates' => $skippedDuplicates,
            'skipped_invalid' => $skippedInvalid,
        ];
    }

    /**
     * @return list<object{id:int,name:string,unit:string,purchased_qty:float,exported_qty:float,balance:float}>
     */
    public function stockBalances(): array
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->select(
            'SELECT i.id, i.name, i.unit,
                    COALESCE(p.purchased_qty, 0) AS purchased_qty,
                    COALESCE(e.exported_qty, 0) AS exported_qty,
                    COALESCE(p.purchased_qty, 0) - COALESCE(e.exported_qty, 0) AS balance
             FROM '.self::ITEMS_TABLE.' i
             LEFT JOIN (
                 SELECT item_id, SUM(quantity) AS purchased_qty
                 FROM '.self::PURCHASES_TABLE.'
                 GROUP BY item_id
             ) p ON p.item_id = i.id
             LEFT JOIN (
                 SELECT item_id, SUM(quantity) AS exported_qty
                 FROM '.self::EXPORTS_TABLE.'
                 GROUP BY item_id
             ) e ON e.item_id = i.id
             ORDER BY i.name COLLATE NOCASE ASC, i.id ASC'
        );
    }

    public function itemBalance(int $itemId): float
    {
        $this->ensureReady();
        $row = DB::connection(self::CONNECTION)->selectOne(
            'SELECT
                COALESCE((SELECT SUM(quantity) FROM '.self::PURCHASES_TABLE.' WHERE item_id = ?), 0)
              - COALESCE((SELECT SUM(quantity) FROM '.self::EXPORTS_TABLE.' WHERE item_id = ?), 0)
                AS balance',
            [$itemId, $itemId]
        );

        return (float) ($row->balance ?? 0);
    }

    /**
     * @return list<object>
     */
    public function listPurchases(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->ensureReady();
        $sql = 'SELECT p.id, p.item_id, p.purchase_date, p.quantity, p.cost_amount, p.currency, p.usd_rate,
                       p.supplier_name, p.note, p.created_at, p.updated_at,
                       i.name AS item_name, i.unit AS item_unit
                FROM '.self::PURCHASES_TABLE.' p
                INNER JOIN '.self::ITEMS_TABLE.' i ON i.id = p.item_id';
        $bindings = [];
        $where = [];

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'p.purchase_date >= ?';
            $bindings[] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'p.purchase_date <= ?';
            $bindings[] = $dateTo;
        }
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.purchase_date DESC, p.id DESC';

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function findPurchase(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT p.id, p.item_id, p.purchase_date, p.quantity, p.cost_amount, p.currency, p.usd_rate,
                    p.supplier_name, p.note, p.created_at, p.updated_at,
                    i.name AS item_name, i.unit AS item_unit
             FROM '.self::PURCHASES_TABLE.' p
             INNER JOIN '.self::ITEMS_TABLE.' i ON i.id = p.item_id
             WHERE p.id = ?
             LIMIT 1',
            [$id]
        );
    }

    public function addPurchase(
        int $itemId,
        string $purchaseDate,
        float $quantity,
        float $costAmount,
        string $currency,
        string $supplierName,
        string $note
    ): int {
        $this->ensureReady();
        $this->assertItemExists($itemId);
        $this->assertPositiveQuantity($quantity);
        $currency = strtoupper(trim($currency));
        $this->assertCurrency($currency);

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::PURCHASES_TABLE.'
                (item_id, purchase_date, quantity, cost_amount, currency, usd_rate, supplier_name, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
            [
                $itemId,
                $purchaseDate,
                $quantity,
                $costAmount,
                $currency,
                trim($supplierName),
                trim($note) !== '' ? trim($note) : null,
                $now,
                $now,
            ]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updatePurchase(
        int $id,
        int $itemId,
        string $purchaseDate,
        float $quantity,
        float $costAmount,
        string $currency,
        string $supplierName,
        string $note
    ): void {
        $this->ensureReady();
        $existing = $this->findPurchase($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Purchase row not found.');
        }

        $this->assertItemExists($itemId);
        $this->assertPositiveQuantity($quantity);
        $currency = strtoupper(trim($currency));
        $this->assertCurrency($currency);

        // Recompute balance excluding this purchase, then apply new quantity as if it were a new purchase,
        // and ensure exports still fit after the change when item stays the same or moves.
        $this->assertPurchaseUpdateKeepsStockNonNegative($id, $itemId, $quantity);

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::PURCHASES_TABLE.'
             SET item_id = ?, purchase_date = ?, quantity = ?, cost_amount = ?, currency = ?, usd_rate = NULL,
                 supplier_name = ?, note = ?, updated_at = ?
             WHERE id = ?',
            [
                $itemId,
                $purchaseDate,
                $quantity,
                $costAmount,
                $currency,
                trim($supplierName),
                trim($note) !== '' ? trim($note) : null,
                now()->toDateTimeString(),
                $id,
            ]
        );
    }

    public function deletePurchase(int $id): void
    {
        $this->ensureReady();
        $existing = $this->findPurchase($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Purchase row not found.');
        }

        $itemId = (int) $existing->item_id;
        $qty = (float) $existing->quantity;
        $balanceWithout = $this->itemBalance($itemId) - $qty;
        if ($balanceWithout < -0.00001) {
            throw new InvalidArgumentException(
                'Cannot delete this purchase: exports to manufacturing have already used this quantity. Remove or reduce those exports first.'
            );
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::PURCHASES_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return list<object>
     */
    public function listExports(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $this->ensureReady();
        $sql = 'SELECT e.id, e.item_id, e.export_date, e.quantity, e.note, e.created_at, e.updated_at,
                       i.name AS item_name, i.unit AS item_unit
                FROM '.self::EXPORTS_TABLE.' e
                INNER JOIN '.self::ITEMS_TABLE.' i ON i.id = e.item_id';
        $bindings = [];
        $where = [];

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'e.export_date >= ?';
            $bindings[] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'e.export_date <= ?';
            $bindings[] = $dateTo;
        }
        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.export_date DESC, e.id DESC';

        return DB::connection(self::CONNECTION)->select($sql, $bindings);
    }

    public function findExport(int $id): ?object
    {
        $this->ensureReady();

        return DB::connection(self::CONNECTION)->selectOne(
            'SELECT e.id, e.item_id, e.export_date, e.quantity, e.note, e.created_at, e.updated_at,
                    i.name AS item_name, i.unit AS item_unit
             FROM '.self::EXPORTS_TABLE.' e
             INNER JOIN '.self::ITEMS_TABLE.' i ON i.id = e.item_id
             WHERE e.id = ?
             LIMIT 1',
            [$id]
        );
    }

    public function addExport(int $itemId, string $exportDate, float $quantity, string $note): int
    {
        $this->ensureReady();
        $this->assertItemExists($itemId);
        $this->assertPositiveQuantity($quantity);

        $balance = $this->itemBalance($itemId);
        if ($quantity > $balance + 0.00001) {
            throw new InvalidArgumentException(
                'Not enough stock to export. Available: '.rtrim(rtrim(number_format($balance, 4, '.', ''), '0'), '.')
            );
        }

        $now = now()->toDateTimeString();
        DB::connection(self::CONNECTION)->insert(
            'INSERT INTO '.self::EXPORTS_TABLE.' (item_id, export_date, quantity, note, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $itemId,
                $exportDate,
                $quantity,
                trim($note) !== '' ? trim($note) : null,
                $now,
                $now,
            ]
        );

        return (int) DB::connection(self::CONNECTION)->getPdo()->lastInsertId();
    }

    public function updateExport(int $id, int $itemId, string $exportDate, float $quantity, string $note): void
    {
        $this->ensureReady();
        $existing = $this->findExport($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Export row not found.');
        }

        $this->assertItemExists($itemId);
        $this->assertPositiveQuantity($quantity);

        $oldItemId = (int) $existing->item_id;
        $oldQty = (float) $existing->quantity;

        if ($itemId === $oldItemId) {
            $available = $this->itemBalance($itemId) + $oldQty;
        } else {
            $available = $this->itemBalance($itemId);
        }

        if ($quantity > $available + 0.00001) {
            throw new InvalidArgumentException(
                'Not enough stock to export. Available: '.rtrim(rtrim(number_format($available, 4, '.', ''), '0'), '.')
            );
        }

        DB::connection(self::CONNECTION)->update(
            'UPDATE '.self::EXPORTS_TABLE.'
             SET item_id = ?, export_date = ?, quantity = ?, note = ?, updated_at = ?
             WHERE id = ?',
            [
                $itemId,
                $exportDate,
                $quantity,
                trim($note) !== '' ? trim($note) : null,
                now()->toDateTimeString(),
                $id,
            ]
        );
    }

    public function deleteExport(int $id): void
    {
        $this->ensureReady();
        $existing = $this->findExport($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Export row not found.');
        }

        DB::connection(self::CONNECTION)->delete(
            'DELETE FROM '.self::EXPORTS_TABLE.' WHERE id = ?',
            [$id]
        );
    }

    public function purchaseIqdEquivalent(object $row): float
    {
        return (float) ($row->cost_amount ?? 0);
    }

    private function assertItemExists(int $itemId): void
    {
        if ($this->findItem($itemId) === null) {
            throw new InvalidArgumentException('Item not found.');
        }
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
    }

    private function assertCurrency(string $currency): void
    {
        if (! in_array($currency, ['IQD', 'USD'], true)) {
            throw new InvalidArgumentException('Currency must be IQD or USD.');
        }
    }

    private function assertPurchaseUpdateKeepsStockNonNegative(int $purchaseId, int $newItemId, float $newQty): void
    {
        $existing = $this->findPurchase($purchaseId);
        if ($existing === null) {
            throw new RuntimeException('Purchase missing during stock check.');
        }

        $oldItemId = (int) $existing->item_id;
        $oldQty = (float) $existing->quantity;

        if ($oldItemId === $newItemId) {
            $balanceWithout = $this->itemBalance($oldItemId) - $oldQty;
            if ($balanceWithout + $newQty < -0.00001) {
                throw new InvalidArgumentException(
                    'Cannot update purchase: stock would go negative for this item.'
                );
            }

            return;
        }

        $oldBalanceWithout = $this->itemBalance($oldItemId) - $oldQty;
        if ($oldBalanceWithout < -0.00001) {
            throw new InvalidArgumentException(
                'Cannot move this purchase to another item: original item stock would go negative.'
            );
        }
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }

        $this->ensureDatabaseFileExists();

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::ITEMS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL COLLATE NOCASE UNIQUE,
                code TEXT,
                unit TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $this->ensureItemsCodeColumn();

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::PURCHASES_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id INTEGER NOT NULL,
                purchase_date TEXT NOT NULL,
                quantity REAL NOT NULL,
                cost_amount REAL NOT NULL,
                currency TEXT NOT NULL DEFAULT \'IQD\',
                usd_rate REAL,
                supplier_name TEXT NOT NULL,
                note TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (item_id) REFERENCES '.self::ITEMS_TABLE.'(id)
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_mfg_purchases_date ON '.self::PURCHASES_TABLE.' (purchase_date)'
        );
        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_mfg_purchases_item ON '.self::PURCHASES_TABLE.' (item_id)'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE TABLE IF NOT EXISTS '.self::EXPORTS_TABLE.' (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id INTEGER NOT NULL,
                export_date TEXT NOT NULL,
                quantity REAL NOT NULL,
                note TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (item_id) REFERENCES '.self::ITEMS_TABLE.'(id)
            )'
        );

        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_mfg_exports_date ON '.self::EXPORTS_TABLE.' (export_date)'
        );
        DB::connection(self::CONNECTION)->statement(
            'CREATE INDEX IF NOT EXISTS idx_mfg_exports_item ON '.self::EXPORTS_TABLE.' (item_id)'
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

    private function ensureItemsCodeColumn(): void
    {
        $columns = DB::connection(self::CONNECTION)->select('PRAGMA table_info('.self::ITEMS_TABLE.')');
        foreach ($columns as $column) {
            if (strcasecmp((string) ($column->name ?? ''), 'code') === 0) {
                return;
            }
        }

        DB::connection(self::CONNECTION)->statement(
            'ALTER TABLE '.self::ITEMS_TABLE.' ADD COLUMN code TEXT'
        );
    }

    private function normalizeCode(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : $code;
    }

    /**
     * @return array<string, int>
     */
    private function existingItemsByNameLower(): array
    {
        $rows = DB::connection(self::CONNECTION)->select(
            'SELECT id, name FROM '.self::ITEMS_TABLE
        );
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->name ?? ''));
            if ($name !== '') {
                $out[mb_strtolower($name)] = (int) $row->id;
            }
        }

        return $out;
    }

    /**
     * @param  list<string|null>  $header
     * @return array{name?: int, code?: int, unit?: int}
     */
    private function csvHeaderMap(array $header): array
    {
        if ($header !== [] && is_string($header[0] ?? null)) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        }

        $map = [];
        foreach ($header as $index => $label) {
            $key = mb_strtolower(trim((string) $label));
            $key = str_replace([' ', '-'], '_', $key);
            if (in_array($key, ['name', 'item', 'item_name'], true)) {
                $map['name'] = (int) $index;
            } elseif (in_array($key, ['code', 'item_code', 'sku'], true)) {
                $map['code'] = (int) $index;
            } elseif (in_array($key, ['unit', 'uom'], true)) {
                $map['unit'] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>|false  $row
     */
    private function csvRowIsEmpty(array|false $row): bool
    {
        if ($row === false || $row === []) {
            return true;
        }
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
