<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

class StorageReportRepository
{
    private const MAX_EXPORT_ROWS = 10000;

    private static bool $storeCityColumnResolved = false;

    private static ?string $storeCityColumn = null;

    /**
     * @return list<string>
     */
    public function getStorageOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $rows = DB::select(
            "SELECT DISTINCT LTRIM(RTRIM(CAST(fld_store_name AS NVARCHAR(500)))) AS store_name
             FROM dbo.tbl_stores
             WHERE fld_store_name IS NOT NULL
               AND LTRIM(RTRIM(CAST(fld_store_name AS NVARCHAR(500)))) <> N''
             ORDER BY store_name"
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->store_name ?? ''),
            $rows
        ));
    }

    /**
     * @return list<string>
     */
    public function getCategoryOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";

        $rows = DB::select(
            "SELECT DISTINCT {$categoryExpr} AS category_name
             FROM {$itemsTable} AS i
             ORDER BY category_name"
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            $rows
        ));
    }

    /**
     * @param  list<string>  $categories  When non-empty, only items in these categories.
     * @return list<stdClass>
     */
    public function getItemOptions(array $categories = []): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemNameExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";

        $bindings = [];
        $categorySql = '';
        $cleanCategories = $this->normalizeStringList($categories);
        if ($cleanCategories !== []) {
            $placeholders = implode(', ', array_fill(0, count($cleanCategories), '?'));
            $categorySql = " AND {$categoryExpr} IN ({$placeholders}) ";
            $bindings = $cleanCategories;
        }

        $rows = DB::select(
            "SELECT DISTINCT
                CAST(i.{$pkCol} AS NVARCHAR(100)) AS item_id,
                {$itemNameExpr} AS item_name,
                {$categoryExpr} AS category_name
             FROM {$itemsTable} AS i
             WHERE 1 = 1
               {$categorySql}
             ORDER BY category_name ASC, item_name ASC",
            $bindings
        );

        return $rows;
    }

    /**
     * Distinct store cities when a city column exists on dbo.tbl_stores.
     *
     * @return list<string>
     */
    public function getStoreCityOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $cityCol = $this->resolveStoreCityColumn();
        if ($cityCol === null) {
            return [];
        }

        $cityExpr = 'LTRIM(RTRIM(CAST(st.'.$this->bracketSqlIdentifier($cityCol).' AS NVARCHAR(500))))';
        $rows = DB::select(
            "SELECT DISTINCT {$cityExpr} AS city_name
             FROM dbo.tbl_stores AS st
             WHERE {$cityExpr} IS NOT NULL AND {$cityExpr} <> N''
             ORDER BY city_name"
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->city_name ?? ''),
            $rows
        ));
    }

    public function hasStoreCityColumn(): bool
    {
        return $this->resolveStoreCityColumn() !== null;
    }

    /**
     * @param  list<string>  $storages
     * @param  list<string>  $categories
     * @param  list<string>  $excludeCategories
     * @param  list<string>  $items  Item ids (GUID strings).
     * @param  list<string>  $excludeItems
     * @param  list<string>  $storeCities
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(
        string $asOfDate,
        array $storages,
        array $categories,
        array $excludeCategories,
        array $items,
        array $excludeItems,
        array $storeCities,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        [$baseFrom, $bindings] = $this->inventoryFromAndBindings(
            $asOfDate,
            $storages,
            $categories,
            $excludeCategories,
            $items,
            $excludeItems,
            $storeCities
        );

        ['perStoreQuantityExpr' => $perStoreQuantityExpr, 'perStoreWeightExpr' => $perStoreWeightExpr] = $this->perStoreAggregateExprs();
        $itemAggregatesSql = $this->itemLevelAggregatesSql($baseFrom, $perStoreQuantityExpr, $perStoreWeightExpr);

        $countSql = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT 1 AS grp
                FROM (
                    {$itemAggregatesSql}
                ) AS non_zero_items
            ) AS grouped_rows
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                items.category_name,
                items.item_code,
                items.item_name,
                items.quantity_total,
                items.weight_total
            FROM (
                {$itemAggregatesSql}
            ) AS items
            ORDER BY items.category_name ASC, items.weight_total DESC
            OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
        ";

        $itemsResult = DB::select($dataSql, $bindings);

        return new LengthAwarePaginator(
            $itemsResult,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  list<string>  $storages
     * @param  list<string>  $categories
     * @param  list<string>  $excludeCategories
     * @param  list<string>  $items
     * @param  list<string>  $excludeItems
     * @param  list<string>  $storeCities
     * @return array{quantity_total: float, weight_total: float}
     */
    public function getReportTotals(
        string $asOfDate,
        array $storages,
        array $categories,
        array $excludeCategories,
        array $items,
        array $excludeItems,
        array $storeCities
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->inventoryFromAndBindings(
            $asOfDate,
            $storages,
            $categories,
            $excludeCategories,
            $items,
            $excludeItems,
            $storeCities
        );

        ['perStoreQuantityExpr' => $perStoreQuantityExpr, 'perStoreWeightExpr' => $perStoreWeightExpr] = $this->perStoreAggregateExprs();
        $itemAggregatesSql = $this->itemLevelAggregatesSql($baseFrom, $perStoreQuantityExpr, $perStoreWeightExpr);

        $sql = "
            SELECT
                COALESCE(SUM(items.quantity_total), 0) AS quantity_total,
                COALESCE(SUM(items.weight_total), 0) AS weight_total
            FROM (
                {$itemAggregatesSql}
            ) AS items
        ";

        $row = DB::selectOne($sql, $bindings);

        return [
            'quantity_total' => (float) ($row->quantity_total ?? 0),
            'weight_total' => (float) ($row->weight_total ?? 0),
        ];
    }

    /**
     * @param  list<string>  $storages
     * @param  list<string>  $categories
     * @param  list<string>  $excludeCategories
     * @param  list<string>  $items
     * @param  list<string>  $excludeItems
     * @param  list<string>  $storeCities
     * @return list<stdClass>
     */
    public function getCategoryTotals(
        string $asOfDate,
        array $storages,
        array $categories,
        array $excludeCategories,
        array $items,
        array $excludeItems,
        array $storeCities
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->inventoryFromAndBindings(
            $asOfDate,
            $storages,
            $categories,
            $excludeCategories,
            $items,
            $excludeItems,
            $storeCities
        );

        ['perStoreQuantityExpr' => $perStoreQuantityExpr, 'perStoreWeightExpr' => $perStoreWeightExpr] = $this->perStoreAggregateExprs();
        $itemAggregatesSql = $this->itemLevelAggregatesSql($baseFrom, $perStoreQuantityExpr, $perStoreWeightExpr);

        $sql = "
            SELECT
                items.category_name,
                SUM(items.quantity_total) AS quantity_total,
                SUM(items.weight_total) AS weight_total
            FROM (
                {$itemAggregatesSql}
            ) AS items
            GROUP BY items.category_name
            ORDER BY items.category_name ASC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $storages
     * @param  list<string>  $categories
     * @param  list<string>  $excludeCategories
     * @param  list<string>  $items
     * @param  list<string>  $excludeItems
     * @param  list<string>  $storeCities
     * @return list<stdClass>
     */
    public function exportRows(
        string $asOfDate,
        array $storages,
        array $categories,
        array $excludeCategories,
        array $items,
        array $excludeItems,
        array $storeCities
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage report requires SQL Server (sqlsrv).');
        }

        $limit = self::MAX_EXPORT_ROWS;

        [$baseFrom, $bindings] = $this->inventoryFromAndBindings(
            $asOfDate,
            $storages,
            $categories,
            $excludeCategories,
            $items,
            $excludeItems,
            $storeCities
        );

        ['perStoreQuantityExpr' => $perStoreQuantityExpr, 'perStoreWeightExpr' => $perStoreWeightExpr] = $this->perStoreAggregateExprs();
        $itemAggregatesSql = $this->itemLevelAggregatesSql($baseFrom, $perStoreQuantityExpr, $perStoreWeightExpr);

        $sql = "
            SELECT
                items.category_name,
                items.item_code,
                items.item_name,
                items.quantity_total,
                items.weight_total
            FROM (
                {$itemAggregatesSql}
            ) AS items
            ORDER BY items.category_name ASC, items.weight_total DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @return array{perStoreQuantityExpr: string, perStoreWeightExpr: string, hasBalanceSource: bool}
     */
    private function perStoreAggregateExprs(): array
    {
        $hasBalanceSource = $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_id_ref', 'fld_item_id']) !== null
            && $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_balance', 'fld_balance', 'fld_qty_balance']) !== null;

        return [
            'hasBalanceSource' => $hasBalanceSource,
            'perStoreQuantityExpr' => $hasBalanceSource
                ? 'MAX(CAST(quantity AS decimal(24, 6)))'
                : 'SUM(CAST(quantity AS decimal(24, 6)))',
            'perStoreWeightExpr' => $hasBalanceSource
                ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_weight AS float))'
                : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_weight AS float))',
        ];
    }

    private function nonZeroQuantityHavingSql(): string
    {
        return ' HAVING SUM(CAST(quantity_total AS decimal(24, 6))) > CAST(0 AS decimal(24, 6)) ';
    }

    private function itemLevelAggregatesSql(string $baseFrom, string $perStoreQuantityExpr, string $perStoreWeightExpr): string
    {
        return "
                SELECT
                    agg.category_name,
                    agg.item_code,
                    agg.item_name,
                    SUM(agg.quantity_total) AS quantity_total,
                    SUM(agg.weight_total) AS weight_total
                FROM (
                    SELECT
                        storage_name,
                        category_name,
                        item_code,
                        item_name,
                        {$perStoreQuantityExpr} AS quantity_total,
                        {$perStoreWeightExpr} AS weight_total
                    {$baseFrom}
                    GROUP BY storage_name, category_name, item_code, item_name
                ) AS agg
                GROUP BY agg.category_name, agg.item_code, agg.item_name
                {$this->nonZeroQuantityHavingSql()}
        ";
    }

    /**
     * @param  list<string>  $storages
     * @param  list<string>  $categories
     * @param  list<string>  $excludeCategories
     * @param  list<string>  $items
     * @param  list<string>  $excludeItems
     * @param  list<string>  $storeCities
     * @return array{0: string, 1: list<mixed>}
     */
    private function inventoryFromAndBindings(
        string $asOfDate,
        array $storages,
        array $categories,
        array $excludeCategories,
        array $items,
        array $excludeItems,
        array $storeCities
    ): array {
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $itemCodeExpr = $this->itemCodeExpr('i');
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemNameExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';
        $itemIdExpr = 'CAST(i.'.$pkCol.' AS NVARCHAR(100))';
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';
        $balanceItemRefCol = $this->resolveColumnName('dbo', 'tbl_store_item_informations', [
            'fld_item_id_ref',
            'fld_item_id',
        ]);
        $balanceValueCol = $this->resolveColumnName('dbo', 'tbl_store_item_informations', [
            'fld_item_balance',
            'fld_balance',
            'fld_qty_balance',
        ]);
        $balanceStoreRefCol = $this->resolveColumnName('dbo', 'tbl_store_item_informations', [
            'fld_store_id_ref',
            'fld_store_id',
        ]);
        $hasBalanceSource = $balanceItemRefCol !== null && $balanceValueCol !== null;

        $balanceJoin = '';
        $quantityExpr = 'd.fld_store_document_quantity';
        if ($hasBalanceSource) {
            $balanceItemExpr = $this->bracketSqlIdentifier($balanceItemRefCol);
            $balanceValueExpr = $this->bracketSqlIdentifier($balanceValueCol);
            if ($balanceStoreRefCol !== null) {
                $balanceStoreExpr = $this->bracketSqlIdentifier($balanceStoreRefCol);
                $balanceSub = "
                    SELECT
                        {$balanceItemExpr} AS item_id_ref,
                        {$balanceStoreExpr} AS store_id_ref,
                        SUM(CAST({$balanceValueExpr} AS decimal(24, 6))) AS fld_item_balance
                    FROM dbo.tbl_store_item_informations
                    GROUP BY {$balanceItemExpr}, {$balanceStoreExpr}
                ";
                $balanceJoin = "
                    LEFT JOIN ({$balanceSub}) AS s
                        ON s.item_id_ref = d.fld_item_id_ref
                       AND s.store_id_ref = t.fld_store_id_ref
                ";
            } else {
                $balanceSub = "
                    SELECT
                        {$balanceItemExpr} AS item_id_ref,
                        SUM(CAST({$balanceValueExpr} AS decimal(24, 6))) AS fld_item_balance
                    FROM dbo.tbl_store_item_informations
                    GROUP BY {$balanceItemExpr}
                ";
                $balanceJoin = "
                    LEFT JOIN ({$balanceSub}) AS s
                        ON s.item_id_ref = d.fld_item_id_ref
                ";
            }

            $quantityExpr = 'COALESCE(s.fld_item_balance, 0)';
        }

        $bindings = [$asOfDate];

        [$storageSql, $storageBindings] = $this->sqlInList(
            "LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500))))",
            $this->normalizeStringList($storages)
        );
        $bindings = array_merge($bindings, $storageBindings);

        [$categorySql, $categoryBindings] = $this->sqlInList($categoryExpr, $this->normalizeStringList($categories));
        $bindings = array_merge($bindings, $categoryBindings);

        [$excludeCategoriesSql, $excludeCatBindings] = $this->sqlNotInList($categoryExpr, $this->normalizeStringList($excludeCategories));
        $bindings = array_merge($bindings, $excludeCatBindings);

        [$itemsSql, $itemsBindings] = $this->sqlInList($itemIdExpr, $this->normalizeStringList($items));
        $bindings = array_merge($bindings, $itemsBindings);

        [$excludeItemsSql, $excludeItemsBindings] = $this->sqlNotInList($itemIdExpr, $this->normalizeStringList($excludeItems));
        $bindings = array_merge($bindings, $excludeItemsBindings);

        [$storeCitySql, $storeCityBindings] = $this->sqlFilterStoreCityEquals('st', $this->normalizeStringList($storeCities));
        $bindings = array_merge($bindings, $storeCityBindings);

        $baseFrom = "
            FROM (
                SELECT
                    LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) AS storage_name,
                    {$categoryExpr} AS category_name,
                    {$itemCodeExpr} AS item_code,
                    {$itemNameExpr} AS item_name,
                    {$quantityExpr} AS quantity,
                    CAST(COALESCE(w.fld_weight, 0) AS float) AS unit_weight
                FROM dbo.tbl_store_document_detail AS d
                INNER JOIN dbo.tbl_store_document_titles AS t
                    ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
                LEFT JOIN dbo.tbl_stores AS st
                    ON st.fld_store_id = t.fld_store_id_ref
                LEFT JOIN {$itemsTable} AS i
                    ON i.{$pkCol} = d.fld_item_id_ref
                LEFT JOIN ({$weightSub}) AS w
                    ON w.fld_item_id_ref = d.fld_item_id_ref
                {$balanceJoin}
                WHERE CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
                  AND ISNULL(t.fld_is_cancelled, 0) = 0
                  AND ISNULL(d.fld_is_cancelled, 0) = 0
                  {$storageSql}
                  {$categorySql}
                  {$excludeCategoriesSql}
                  {$itemsSql}
                  {$excludeItemsSql}
                  {$storeCitySql}
            ) AS source_rows
        ";

        return [$baseFrom, $bindings];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: list<string>}
     */
    private function sqlInList(string $expr, array $values): array
    {
        if ($values === []) {
            return ['', []];
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [" AND {$expr} IN ({$placeholders}) ", $values];
    }

    /**
     * @param  list<string>  $values
     * @return array{0: string, 1: list<string>}
     */
    private function sqlNotInList(string $expr, array $values): array
    {
        if ($values === []) {
            return ['', []];
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [" AND {$expr} NOT IN ({$placeholders}) ", $values];
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<string>}
     */
    private function sqlFilterStoreCityEquals(string $storeAlias, array $cities): array
    {
        if ($cities === []) {
            return ['', []];
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $storeAlias)) {
            throw new \InvalidArgumentException('Invalid SQL table alias for store city filter.');
        }

        $cityCol = $this->resolveStoreCityColumn();
        if ($cityCol === null) {
            Log::warning('storage_report.store_city_filter_skipped', [
                'reason' => 'No city column on dbo.tbl_stores; set REPORTING_STORE_CITY_COLUMN.',
            ]);

            return ['', []];
        }

        $br = $this->bracketSqlIdentifier($cityCol);
        $cityExpr = 'LTRIM(RTRIM(CAST('.$storeAlias.'.'.$br.' AS NVARCHAR(500))))';
        $placeholders = implode(',', array_fill(0, count($cities), '?'));

        return [
            ' AND '.$cityExpr.' IN ('.$placeholders.') ',
            $cities,
        ];
    }

    /**
     * @param  list<string>|null  $values
     * @return list<string>
     */
    public function normalizeStringList(?array $values): array
    {
        if ($values === null || $values === []) {
            return [];
        }
        $out = [];
        foreach ($values as $v) {
            if (! is_string($v) && ! is_numeric($v)) {
                continue;
            }
            $s = trim((string) $v);
            if ($s === '' || mb_strlen($s) > 500) {
                continue;
            }
            $out[$s] = $s;
        }

        return array_values($out);
    }

    private function resolveStoreCityColumn(): ?string
    {
        if (self::$storeCityColumnResolved) {
            return self::$storeCityColumn;
        }

        self::$storeCityColumnResolved = true;
        self::$storeCityColumn = null;

        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $explicit = trim((string) config('reporting.store_city_column', ''));
        if ($explicit !== '' && $this->columnExists('dbo', 'tbl_stores', $explicit)) {
            self::$storeCityColumn = $explicit;

            return self::$storeCityColumn;
        }

        foreach ((array) config('reporting.store_city_column_candidates', []) as $candidate) {
            if ($this->columnExists('dbo', 'tbl_stores', (string) $candidate)) {
                self::$storeCityColumn = (string) $candidate;

                return self::$storeCityColumn;
            }
        }

        $row = DB::selectOne(
            "SELECT TOP 1 COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
               AND COLUMN_NAME LIKE '%city%'
             ORDER BY ORDINAL_POSITION",
            ['dbo', 'tbl_stores']
        );
        if ($row !== null) {
            self::$storeCityColumn = (string) $row->COLUMN_NAME;
        }

        return self::$storeCityColumn;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeItemsTableSchemaAndName(): array
    {
        $full = trim((string) config('reporting.store_items_table', 'dbo.tbl_store_items'));
        $parts = explode('.', $full, 2);
        if (count($parts) === 2) {
            return [trim($parts[0], "[] \t\n\r\0\x0B"), trim($parts[1], "[] \t\n\r\0\x0B")];
        }

        return ['dbo', trim($full, "[] \t\n\r\0\x0B")];
    }

    private function itemCodeExpr(string $alias): string
    {
        [$schema, $table] = $this->storeItemsTableSchemaAndName();
        $col = $this->resolveColumnName($schema, $table, [
            'fld_item_code',
            'fld_barcode',
            'fld_item_barcode',
            'fld_store_item_barcode',
        ]);
        if ($col === null) {
            return "N''";
        }

        return "LTRIM(RTRIM(CAST(COALESCE({$alias}.".$this->bracketSqlIdentifier($col).", N'') AS NVARCHAR(200))))";
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveColumnName(string $schema, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($this->columnExists($schema, $table, $column)) {
                return (string) $column;
            }
        }

        return null;
    }

    private function columnExists(string $schema, string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT TOP 1 COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$schema, $table, $column]
        );

        return $row !== null;
    }

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }
}
