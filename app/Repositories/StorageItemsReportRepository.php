<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class StorageItemsReportRepository
{
    private const MAX_EXPORT_ROWS = 10000;

    /**
     * Lazily filled map: normalized item id (no braces, lower hex) → tier → price from PDA SP (null = leave SQL value).
     *
     * @var array<string, array<int, float|null>>|null
     */
    private ?array $pdaTierPriceByItemKeyCache = null;

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
    public function getCategoryOptions(string $asOfDate): array
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
             ORDER BY category_name",
            []
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            $rows
        ));
    }

    /**
     * Storage snapshot merged with outbound document quantities sold in {@see $salesDateFrom} … {@see $salesDateTo}
     * (same item grouping as inventory evaluation): inventory quantity plus sold-period quantity for coverage math.
     *
     * @param  list<string>  $excludeCategories  Category labels to omit from inventory and period sales subqueries.
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getEvaluationReport(
        string $asOfDate,
        ?string $storage,
        ?string $category,
        array $excludeCategories,
        ?string $itemSearch,
        string $salesDateFrom,
        string $salesDateTo,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage items report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $hasBalanceSource = $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_id_ref', 'fld_item_id']) !== null
            && $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_balance', 'fld_balance', 'fld_qty_balance']) !== null;

        [$baseFrom, $bindings] = $this->evaluationFromAndBindings($asOfDate, $storage, $category, $excludeCategories, $itemSearch);

        $offset = max(0, ($page - 1) * $perPage);
        $quantityTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)))';
        $weightTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_weight AS float))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_weight AS float))';
        $amountTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_price AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_price AS decimal(24, 6)))';

        $perStoreQuantityExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)))';
        $perStoreWeightExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_weight AS float))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_weight AS float))';
        $perStoreAmountExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_price AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_price AS decimal(24, 6)))';

        [$soldSubSql, $soldBindings] = $this->soldInPeriodAggregateSubquerySqlAndBindings(
            $salesDateFrom,
            $salesDateTo,
            $storage,
            $category,
            $excludeCategories,
            $itemSearch
        );

        $inventorySubSql = "
            SELECT
                grouped.category_name,
                grouped.item_code,
                grouped.item_name,
                SUM(grouped.quantity_total) AS quantity_total,
                SUM(grouped.weight_total) AS weight_total,
                SUM(grouped.amount_total) AS amount_total
            FROM (
                SELECT
                    storage_name,
                    category_name,
                    item_code,
                    item_name,
                    {$perStoreQuantityExpr} AS quantity_total,
                    {$perStoreWeightExpr} AS weight_total,
                    {$perStoreAmountExpr} AS amount_total
                {$baseFrom}
                GROUP BY storage_name, category_name, item_code, item_name
            ) AS grouped
            GROUP BY grouped.category_name, grouped.item_code, grouped.item_name
        ";

        $visibilitySql = $this->zeroCartonUnlessSoldVisibilitySql();

        $countSql = "
            SELECT COUNT(*) AS c
            FROM (
                {$inventorySubSql}
            ) AS inv
            LEFT JOIN (
                {$soldSubSql}
            ) AS sold
                ON sold.category_name = inv.category_name
               AND sold.item_code = inv.item_code
               AND sold.item_name = inv.item_name
            WHERE 1=1
              {$visibilitySql}
        ";
        $allBindings = array_merge($bindings, $soldBindings);
        $total = (int) (DB::selectOne($countSql, $allBindings)->c ?? 0);

        $dataSql = "
            SELECT
                inv.category_name,
                inv.item_code,
                inv.item_name,
                inv.quantity_total,
                inv.weight_total,
                inv.amount_total,
                COALESCE(sold.sold_quantity_period, CAST(0 AS decimal(24, 6))) AS sold_quantity_period
            FROM (
                {$inventorySubSql}
            ) AS inv
            LEFT JOIN (
                {$soldSubSql}
            ) AS sold
                ON sold.category_name = inv.category_name
               AND sold.item_code = inv.item_code
               AND sold.item_name = inv.item_name
            WHERE 1=1
              {$visibilitySql}
            ORDER BY inv.category_name ASC, inv.amount_total DESC
            OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
        ";

        $items = DB::select($dataSql, $allBindings);

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @param  list<string>  $excludeCategories
     * @return array{quantity_total: float, sold_quantity_period: float}
     */
    public function getEvaluationTotals(
        string $asOfDate,
        ?string $storage,
        ?string $category,
        array $excludeCategories,
        ?string $itemSearch,
        string $salesDateFrom,
        string $salesDateTo
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage items report requires SQL Server (sqlsrv).');
        }

        $hasBalanceSource = $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_id_ref', 'fld_item_id']) !== null
            && $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_balance', 'fld_balance', 'fld_qty_balance']) !== null;

        [$baseFrom, $bindings] = $this->evaluationFromAndBindings($asOfDate, $storage, $category, $excludeCategories, $itemSearch);

        $perStoreQuantityExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)))';

        [$soldSubSql, $soldBindings] = $this->soldInPeriodAggregateSubquerySqlAndBindings(
            $salesDateFrom,
            $salesDateTo,
            $storage,
            $category,
            $excludeCategories,
            $itemSearch
        );

        $inventorySubSql = "
            SELECT
                grouped.category_name,
                grouped.item_code,
                grouped.item_name,
                SUM(grouped.quantity_total) AS quantity_total
            FROM (
                SELECT
                    storage_name,
                    category_name,
                    item_code,
                    item_name,
                    {$perStoreQuantityExpr} AS quantity_total
                {$baseFrom}
                GROUP BY storage_name, category_name, item_code, item_name
            ) AS grouped
            GROUP BY grouped.category_name, grouped.item_code, grouped.item_name
        ";

        $sql = "
            SELECT
                COALESCE(SUM(inv.quantity_total), 0) AS quantity_total,
                COALESCE(SUM(COALESCE(sold.sold_quantity_period, CAST(0 AS decimal(24, 6)))), 0) AS sold_quantity_period
            FROM (
                {$inventorySubSql}
            ) AS inv
            LEFT JOIN (
                {$soldSubSql}
            ) AS sold
                ON sold.category_name = inv.category_name
               AND sold.item_code = inv.item_code
               AND sold.item_name = inv.item_name
            WHERE 1=1
              {$this->zeroCartonUnlessSoldVisibilitySql()}
        ";

        $allBindings = array_merge($bindings, $soldBindings);
        $row = DB::selectOne($sql, $allBindings);

        return [
            'quantity_total' => (float) ($row->quantity_total ?? 0),
            'sold_quantity_period' => (float) ($row->sold_quantity_period ?? 0),
        ];
    }

    /**
     * @return list<stdClass>
     */
    public function exportEvaluationRows(
        string $asOfDate,
        ?string $storage,
        ?string $category,
        array $excludeCategories,
        ?string $itemSearch,
        string $salesDateFrom,
        string $salesDateTo
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Storage items report requires SQL Server (sqlsrv).');
        }

        $limit = self::MAX_EXPORT_ROWS;
        $hasBalanceSource = $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_id_ref', 'fld_item_id']) !== null
            && $this->resolveColumnName('dbo', 'tbl_store_item_informations', ['fld_item_balance', 'fld_balance', 'fld_qty_balance']) !== null;

        [$baseFrom, $bindings] = $this->evaluationFromAndBindings($asOfDate, $storage, $category, $excludeCategories, $itemSearch);

        $quantityTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)))';
        $weightTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_weight AS float))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_weight AS float))';
        $amountTotalExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_price AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_price AS decimal(24, 6)))';

        $perStoreQuantityExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)))';
        $perStoreWeightExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_weight AS float))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_weight AS float))';
        $perStoreAmountExpr = $hasBalanceSource
            ? 'MAX(CAST(quantity AS decimal(24, 6))) * MAX(CAST(unit_price AS decimal(24, 6)))'
            : 'SUM(CAST(quantity AS decimal(24, 6)) * CAST(unit_price AS decimal(24, 6)))';

        [$soldSubSql, $soldBindings] = $this->soldInPeriodAggregateSubquerySqlAndBindings(
            $salesDateFrom,
            $salesDateTo,
            $storage,
            $category,
            $excludeCategories,
            $itemSearch
        );

        $inventorySubSql = "
            SELECT
                grouped.category_name,
                grouped.item_code,
                grouped.item_name,
                SUM(grouped.quantity_total) AS quantity_total,
                SUM(grouped.weight_total) AS weight_total,
                SUM(grouped.amount_total) AS amount_total
            FROM (
                SELECT
                    storage_name,
                    category_name,
                    item_code,
                    item_name,
                    {$perStoreQuantityExpr} AS quantity_total,
                    {$perStoreWeightExpr} AS weight_total,
                    {$perStoreAmountExpr} AS amount_total
                {$baseFrom}
                GROUP BY storage_name, category_name, item_code, item_name
            ) AS grouped
            GROUP BY grouped.category_name, grouped.item_code, grouped.item_name
        ";

        $sql = "
            SELECT
                inv.category_name,
                inv.item_code,
                inv.item_name,
                inv.quantity_total,
                inv.weight_total,
                inv.amount_total,
                COALESCE(sold.sold_quantity_period, CAST(0 AS decimal(24, 6))) AS sold_quantity_period
            FROM (
                {$inventorySubSql}
            ) AS inv
            LEFT JOIN (
                {$soldSubSql}
            ) AS sold
                ON sold.category_name = inv.category_name
               AND sold.item_code = inv.item_code
               AND sold.item_name = inv.item_name
            WHERE 1=1
              {$this->zeroCartonUnlessSoldVisibilitySql()}
            ORDER BY inv.category_name ASC, inv.amount_total DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        $allBindings = array_merge($bindings, $soldBindings);

        return DB::select($sql, $allBindings);
    }

    /**
     * Latest price-history row per store item: all five sale prices from one snapshot row.
     * By default (see {@see config('reporting.storage_items_price_history_cap_at_as_of_date')}) rows are not
     * capped at the report as_of_date — the newest price date in the DB wins. Optional pointer on tbl_store_items
     * still prefers the linked history id when present.
     * Bind placeholders must precede outer filter placeholders when the date cap is enabled.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function storeItemLatestPricesSubquerySqlAndBindings(string $asOfDate): array
    {
        $historyItemRefColumn = $this->historyItemRefColumn();
        $dateCol = $this->historyDateColumn();
        $histRowIdRaw = $this->resolveColumnName('dbo', 'tbl_store_item_unit_price_history', [
            'fld_store_item_unit_price_history_id',
            'fld_item_price_history_id',
            'fld_id',
            'id',
        ]);
        $histRowIdBracketed = $histRowIdRaw !== null ? $this->bracketSqlIdentifier($histRowIdRaw) : null;

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkColBracketed = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $ptrBracketed = $this->storeItemsPriceHistoryPointerColumnBracketed();

        $bindings = [];
        $whereSql = '';
        $capAtAsOf = (bool) config('reporting.storage_items_price_history_cap_at_as_of_date', false);
        if ($capAtAsOf && $dateCol !== null) {
            $whereSql = 'WHERE CAST(h.'.$dateCol.' AS date) <= CAST(? AS date) ';
            $bindings[] = $asOfDate;
        }

        $orderParts = [];
        if ($ptrBracketed !== null && $histRowIdBracketed !== null) {
            $orderParts[] = 'CASE WHEN i_hist.'.$ptrBracketed.' IS NOT NULL AND CAST(h.'.$histRowIdBracketed.' AS NVARCHAR(100)) = CAST(i_hist.'.$ptrBracketed.' AS NVARCHAR(100)) THEN 0 ELSE 1 END ASC';
        }
        if ($dateCol !== null) {
            $orderParts[] = 'CAST(h.'.$dateCol.' AS datetime2) DESC';
        }
        if ($histRowIdBracketed !== null) {
            $orderParts[] = 'h.'.$histRowIdBracketed.' DESC';
        }
        foreach (['fld_modify_date', 'fld_last_modified', 'fld_updated_at', 'modifiedon'] as $extraChrono) {
            $ex = $this->resolveColumnName('dbo', 'tbl_store_item_unit_price_history', [$extraChrono]);
            if ($ex !== null) {
                $orderParts[] = 'CAST(h.'.$this->bracketSqlIdentifier($ex).' AS datetime2) DESC';
            }
        }
        $orderSql = implode(', ', $orderParts);
        if ($orderSql === '') {
            $orderSql = 'h.'.$historyItemRefColumn.' DESC';
        }

        $sql = "
SELECT
                x.item_ref,
                CAST(x.price1 AS decimal(24, 6)) AS price1,
                CAST(x.price2 AS decimal(24, 6)) AS price2,
                CAST(x.price3 AS decimal(24, 6)) AS price3,
                CAST(x.price4 AS decimal(24, 6)) AS price4,
                CAST(x.price5 AS decimal(24, 6)) AS price5
            FROM (
                SELECT
                    CAST(h.{$historyItemRefColumn} AS NVARCHAR(100)) AS item_ref,
                    CAST(h.fld_sale_price1 AS decimal(24, 6)) AS price1,
                    CAST(h.fld_sale_price2 AS decimal(24, 6)) AS price2,
                    CAST(h.fld_sale_price3 AS decimal(24, 6)) AS price3,
                    CAST(h.fld_sale_price4 AS decimal(24, 6)) AS price4,
                    CAST(h.fld_sale_price5 AS decimal(24, 6)) AS price5,
                    ROW_NUMBER() OVER (
                        PARTITION BY CAST(h.{$historyItemRefColumn} AS NVARCHAR(100))
                        ORDER BY {$orderSql}
                    ) AS rn
                FROM dbo.tbl_store_item_unit_price_history AS h
                INNER JOIN {$itemsTable} AS i_hist
                    ON CAST(h.{$historyItemRefColumn} AS NVARCHAR(100)) = CAST(i_hist.{$pkColBracketed} AS NVARCHAR(100))
                {$whereSql}
            ) AS x
            WHERE x.rn = 1
        ";

        return [$sql, $bindings];
    }

    /**
     * @param  list<string>  $excludeCategories
     * @return array{0: string, 1: list<mixed>}
     */
    private function excludeCategoriesNotInSqlAndBindings(string $categoryExpr, array $excludeCategories): array
    {
        $clean = [];
        foreach ($excludeCategories as $c) {
            $t = trim((string) $c);
            if ($t !== '') {
                $clean[$t] = $t;
            }
        }
        $vals = array_values($clean);
        if ($vals === []) {
            return ['', []];
        }
        $placeholders = implode(', ', array_fill(0, count($vals), '?'));

        return [" AND {$categoryExpr} NOT IN ({$placeholders}) ", $vals];
    }

    /**
     * @param  list<string>  $excludeCategories
     * @return array{0:string, 1:list<mixed>}
     */
    private function evaluationFromAndBindings(string $asOfDate, ?string $storage, ?string $category, array $excludeCategories, ?string $itemSearch): array
    {
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $itemCodeExpr = $this->itemCodeExpr('i');
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemNameExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';
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
        $storageSql = '';
        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue !== '') {
            $storageSql = " AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) = ? ";
            $bindings[] = $storageValue;
        }

        $categorySql = '';
        $categoryValue = trim((string) ($category ?? ''));
        if ($categoryValue !== '') {
            $categorySql = " AND {$categoryExpr} = ? ";
            $bindings[] = $categoryValue;
        }

        [$excludeCategoriesSql, $excludeBindings] = $this->excludeCategoriesNotInSqlAndBindings($categoryExpr, $excludeCategories);
        $bindings = array_merge($bindings, $excludeBindings);

        $searchSql = '';
        $q = trim((string) ($itemSearch ?? ''));
        if ($q !== '') {
            $pattern = '%'.$this->escapeLikePattern($q).'%';
            $searchSql = " AND (
                {$itemNameExpr} LIKE ? ESCAPE N'\\'
                OR {$itemCodeExpr} LIKE ? ESCAPE N'\\'
                OR {$categoryExpr} LIKE ? ESCAPE N'\\'
            ) ";
            $bindings[] = $pattern;
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }

        $baseFrom = "
            FROM (
                SELECT
                    LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) AS storage_name,
                    {$categoryExpr} AS category_name,
                    {$itemCodeExpr} AS item_code,
                    {$itemNameExpr} AS item_name,
                    {$quantityExpr} AS quantity,
                    d.fld_store_document_unit_price AS unit_price,
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
                  {$searchSql}
            ) AS source_rows
        ";

        return [$baseFrom, $bindings];
    }

    /**
     * Sum of outbound document quantities in the inclusive date range (same grouping keys as inventory evaluation).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function soldInPeriodAggregateSubquerySqlAndBindings(
        string $salesDateFrom,
        string $salesDateTo,
        ?string $storage,
        ?string $category,
        array $excludeCategories,
        ?string $itemSearch
    ): array {
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $itemCodeExpr = $this->itemCodeExpr('i');
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemNameExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';

        $bindings = [$salesDateFrom, $salesDateTo];
        $storageSql = '';
        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue !== '') {
            $storageSql = " AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) = ? ";
            $bindings[] = $storageValue;
        }

        $categorySql = '';
        $categoryValue = trim((string) ($category ?? ''));
        if ($categoryValue !== '') {
            $categorySql = " AND {$categoryExpr} = ? ";
            $bindings[] = $categoryValue;
        }

        [$excludeCategoriesSql, $excludeBindings] = $this->excludeCategoriesNotInSqlAndBindings($categoryExpr, $excludeCategories);
        $bindings = array_merge($bindings, $excludeBindings);

        $searchSql = '';
        $q = trim((string) ($itemSearch ?? ''));
        if ($q !== '') {
            $pattern = '%'.$this->escapeLikePattern($q).'%';
            $searchSql = " AND (
                {$itemNameExpr} LIKE ? ESCAPE N'\\'
                OR {$itemCodeExpr} LIKE ? ESCAPE N'\\'
                OR {$categoryExpr} LIKE ? ESCAPE N'\\'
            ) ";
            $bindings[] = $pattern;
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }

        $sql = "
            SELECT
                {$categoryExpr} AS category_name,
                {$itemCodeExpr} AS item_code,
                {$itemNameExpr} AS item_name,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS sold_quantity_period
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_stores AS st
                ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$storageSql}
              {$categorySql}
              {$excludeCategoriesSql}
              {$searchSql}
            GROUP BY {$categoryExpr}, {$itemCodeExpr}, {$itemNameExpr}
        ";

        return [$sql, $bindings];
    }

    /**
     * Hide rows with zero cartons unless the item sold in the selected sales period.
     */
    private function zeroCartonUnlessSoldVisibilitySql(): string
    {
        return ' AND (
                inv.quantity_total > CAST(0 AS decimal(24, 6))
                OR COALESCE(sold.sold_quantity_period, CAST(0 AS decimal(24, 6))) > CAST(0 AS decimal(24, 6))
            ) ';
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

    /**
     * Optional column on {@code tbl_store_items} that references
     * {@code dbo.tbl_store_item_unit_price_history.fld_store_item_unit_price_history_id}
     * so the correct history row wins over date-only ordering.
     */
    private function storeItemsPriceHistoryPointerColumnBracketed(): ?string
    {
        [$schema, $table] = $this->storeItemsTableSchemaAndName();
        $explicit = trim((string) config('reporting.store_items_price_history_pointer_column', ''));
        if ($explicit !== '' && $this->columnExists($schema, $table, $explicit)) {
            return $this->bracketSqlIdentifier($explicit);
        }

        $col = $this->resolveColumnName($schema, $table, [
            'fld_store_item_unit_price_history_id',
            'fld_current_store_item_unit_price_history_id',
            'fld_item_unit_price_history_id_ref',
            'fld_price_history_id_ref',
        ]);

        return $col !== null ? $this->bracketSqlIdentifier($col) : null;
    }

    /**
     * One numeric tier: merged master {@code fld_sale_priceN} and history {@code p.priceN}.
     *
     * @param  1|2|3|4|5  $tierOneToFive
     */
    private function storeItemMergedPriceColumnSql(string $itemsAlias, int $tierOneToFive, string $histAlias = 'p'): string
    {
        $tierOneToFive = max(1, min(5, $tierOneToFive));

        return $this->storeItemMergedTierSql($itemsAlias, $tierOneToFive, $histAlias);
    }

    /**
     * @param  1|2|3|4|5  $tierOneToFive
     */
    private function storeItemMergedTierSql(string $itemsAlias, int $tierOneToFive, string $histAlias): string
    {
        $tierOneToFive = max(1, min(5, $tierOneToFive));
        $priceField = 'price'.$tierOneToFive;
        $histCast = 'CAST('.$histAlias.'.'.$priceField.' AS decimal(24, 6))';

        if (! (bool) config('reporting.storage_items_prefer_master_sale_prices', true)) {
            return $histCast;
        }

        [$schema, $table] = $this->storeItemsTableSchemaAndName();
        $col = $this->resolveColumnName($schema, $table, ['fld_sale_price'.$tierOneToFive]);
        if ($col === null) {
            return $histCast;
        }

        $b = $this->bracketSqlIdentifier($col);
        $masterCast = 'CAST('.$itemsAlias.'.'.$b.' AS decimal(24, 6))';
        $zeroUnset = (bool) config('reporting.storage_items_master_sale_price_zero_as_unset', true);
        $masterPart = $zeroUnset
            ? 'NULLIF('.$masterCast.', CAST(0 AS decimal(24, 6)))'
            : $masterCast;

        if ((bool) config('reporting.storage_items_prefer_history_sale_prices', true)) {
            return 'COALESCE('.$histCast.', '.$masterPart.')';
        }

        return 'COALESCE('.$masterPart.', '.$histCast.')';
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
     * @return list<string>
     */
    public function getAssemblyItemsByCategory(string $category): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemNameExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";

        $rows = DB::select(
            "SELECT DISTINCT {$itemNameExpr} AS item_name
             FROM {$itemsTable} AS i
             WHERE {$categoryExpr} = ?
             ORDER BY item_name",
            [trim($category)]
        );

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->item_name ?? ''),
            $rows
        ));
    }

    /**
     * Read-only search for damages packaging / entry forms (main DB only).
     *
     * @return list<stdClass>
     */
    public function searchStoreItemsForDamages(string $asOfDate, string $q, int $limit = 40): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $q = trim($q);
        if ($q === '') {
            return [];
        }

        try {
            [$latestPricesSql, $latestBindings] = $this->storeItemLatestPricesSubquerySqlAndBindings($asOfDate);
        } catch (RuntimeException) {
            return [];
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $itemNameExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemCodeExpr = $this->itemCodeExpr('i');

        $pattern = '%'.$this->escapeLikePattern($q).'%';
        $limit = max(1, min(100, $limit));

        $price1Select = 'COALESCE('.$this->storeItemMergedPriceColumnSql('i', 1).', CAST(0 AS decimal(24, 6))) AS price1';

        $sql = "
            SELECT TOP ({$limit})
                CAST(i.{$pkCol} AS NVARCHAR(100)) AS item_id,
                {$itemNameExpr} AS item_name,
                {$itemCodeExpr} AS item_code,
                {$price1Select}
            FROM {$itemsTable} AS i
            LEFT JOIN (
                {$latestPricesSql}
            ) AS p ON p.item_ref = CAST(i.{$pkCol} AS NVARCHAR(100))
            WHERE (
                {$itemNameExpr} LIKE ? ESCAPE N'\\'
                OR {$itemCodeExpr} LIKE ? ESCAPE N'\\'
                OR {$categoryExpr} LIKE ? ESCAPE N'\\'
            )
            ORDER BY {$itemNameExpr} ASC
        ";

        $rows = DB::select($sql, array_merge($latestBindings, [$pattern, $pattern, $pattern]));
        foreach ($rows as $row) {
            $this->applyPdaTierPricesToRow($row);
        }

        return $rows;
    }

    /**
     * Pricing resolution for damages: latest history (DB newest price date unless
     * {@see config('reporting.storage_items_price_history_cap_at_as_of_date')}), merged with master sale prices per config.
     * When {@see config('reporting.pda_pricing_user_uuid')} is set, tier amounts come from {@code SP_PDA_Get_Item_All_Units} (read-only).
     */
    public function getDamagesCartonPriceForClientTier(string $itemId, string $asOfDate, int $tier0To4): ?float
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $tier0To4 = max(0, min(4, $tier0To4));
        $tierOneToFive = $tier0To4 + 1;

        $fromPda = $this->pdaTierPriceForItemAndTier(trim($itemId), $tierOneToFive);
        if ($fromPda !== null) {
            return $fromPda;
        }

        try {
            [$latestPricesSql, $latestBindings] = $this->storeItemLatestPricesSubquerySqlAndBindings($asOfDate);
        } catch (RuntimeException) {
            return null;
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));

        $merged = $this->storeItemMergedPriceColumnSql('i', $tierOneToFive);

        $sql = "
            SELECT {$merged} AS p
            FROM {$itemsTable} AS i
            LEFT JOIN (
                {$latestPricesSql}
            ) AS p ON p.item_ref = CAST(i.{$pkCol} AS NVARCHAR(100))
            WHERE CAST(i.{$pkCol} AS NVARCHAR(100)) = ?
        ";

        $row = DB::selectOne($sql, array_merge($latestBindings, [trim($itemId)]));
        if ($row === null) {
            return null;
        }

        $pv = $row->p ?? null;
        if ($pv === null) {
            return null;
        }

        return (float) $pv;
    }

    /**
     * Chooses carton price for a damages line: last invoice-like sale to this
     * client for this item when {@see config('reporting.damages_price_prefer_last_client_sale')},
     * otherwise (or when no qualifying line exists) tier + history/master via
     * {@see getDamagesCartonPriceForClientTier()}.
     *
     * @return array{price: float, source: 'last_client_sale'|'tier_catalog', tier: int}
     */
    public function resolveDamagesCartonPriceForDamagesEntry(
        string $clientAccountId,
        string $itemId,
        string $occurredDate,
        int $tier0To4
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            return ['price' => 0.0, 'source' => 'tier_catalog', 'tier' => max(0, min(4, $tier0To4))];
        }

        $tier0To4 = max(0, min(4, $tier0To4));
        $clientAccountId = trim($clientAccountId);
        $itemId = trim($itemId);
        $occurredDate = trim($occurredDate);

        $preferSale = filter_var(
            config('reporting.damages_price_prefer_last_client_sale', true),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($preferSale && $clientAccountId !== '' && $itemId !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurredDate)) {
            $fromSale = $this->lastClientSaleEffectiveCartonPrice($clientAccountId, $itemId, $occurredDate);
            if ($fromSale !== null && $fromSale > 0.0) {
                return ['price' => $fromSale, 'source' => 'last_client_sale', 'tier' => $tier0To4];
            }
        }

        $tierPrice = $this->getDamagesCartonPriceForClientTier($itemId, $occurredDate, $tier0To4);

        return [
            'price' => round((float) ($tierPrice ?? 0.0), 6),
            'source' => 'tier_catalog',
            'tier' => $tier0To4,
        ];
    }

    /**
     * Newest outbound line {@code fld_store_document_unit_price} for this client
     * and item on or before {@code $asOfDate}, after {@code fld_store_document_discount_percent}.
     */
    private function lastClientSaleEffectiveCartonPrice(string $accountId, string $itemId, string $asOfDate): ?float
    {
        // Same effective line amount basis as invoicing reports.
        $sql = '
            SELECT TOP (1)
                CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                * (CAST(1 AS decimal(24, 6))
                    - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6))
                        / CAST(100 AS decimal(24, 6))))
                    AS carton_price_effective
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            WHERE CAST(t.fld_account_id_ref AS NVARCHAR(100)) = ?
              AND CAST(d.fld_item_id_ref AS NVARCHAR(100)) = ?
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              AND CAST(d.fld_store_document_quantity AS decimal(24, 6)) > CAST(0 AS decimal(24, 6))
            ORDER BY
                CAST(t.fld_store_document_title_date AS datetime) DESC,
                t.fld_store_document_title_id DESC
        ';

        $row = DB::selectOne($sql, [$accountId, $itemId, $asOfDate]);
        if (! $row instanceof stdClass || ! isset($row->carton_price_effective)) {
            return null;
        }

        $p = $row->carton_price_effective;
        if ($p === null) {
            return null;
        }
        $f = (float) $p;

        return $f > 0 ? $f : null;
    }

    public function getStoreItemDisplay(string $itemId): ?stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $itemNameExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";
        $itemCodeExpr = $this->itemCodeExpr('i');

        $sql = "
            SELECT TOP 1
                CAST(i.{$pkCol} AS NVARCHAR(100)) AS item_id,
                {$itemNameExpr} AS item_name,
                {$itemCodeExpr} AS item_code
            FROM {$itemsTable} AS i
            WHERE CAST(i.{$pkCol} AS NVARCHAR(100)) = ?
        ";

        $row = DB::selectOne($sql, [trim($itemId)]);

        return $row instanceof stdClass ? $row : null;
    }

    private function historyDateColumn(): ?string
    {
        // Prefer columns that explicitly mean “price effective date” so ORDER BY DESC matches
        // business “current price”. Generic fld_date often exists first but may not sort correctly.
        $candidates = [
            'fld_sale_price_date',
            'fld_price_date',
            'fld_date',
            'fld_created_at',
            'fld_create_date',
            'fld_creation_date',
            'created_at',
            'createdon',
            'create_date',
            'date',
        ];

        foreach ($candidates as $candidate) {
            if (! $this->columnExists('dbo', 'tbl_store_item_unit_price_history', $candidate)) {
                continue;
            }
            if ($this->columnHasAnyNonNullValue('dbo', 'tbl_store_item_unit_price_history', $candidate)) {
                return $this->bracketSqlIdentifier($candidate);
            }
        }

        $row = DB::selectOne(
            "SELECT TOP 1 COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
               AND DATA_TYPE IN ('date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset', 'time')
             ORDER BY CASE WHEN COLUMN_NAME LIKE '%date%' THEN 0 ELSE 1 END, ORDINAL_POSITION",
            ['dbo', 'tbl_store_item_unit_price_history']
        );

        if ($row === null) {
            return null;
        }

        $column = (string) $row->COLUMN_NAME;

        return $this->columnHasAnyNonNullValue('dbo', 'tbl_store_item_unit_price_history', $column)
            ? $this->bracketSqlIdentifier($column)
            : null;
    }

    private function historyItemRefColumn(): string
    {
        $column = $this->resolveColumnName('dbo', 'tbl_store_item_unit_price_history', [
            'fld_item_id_ref',
            'fld_item_id',
            'fld_store_item_id_ref',
            'fld_store_item_id',
        ]);
        if ($column === null) {
            throw new RuntimeException('Price history item reference column not found in dbo.tbl_store_item_unit_price_history.');
        }

        return $this->bracketSqlIdentifier($column);
    }

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

    private function columnHasAnyNonNullValue(string $schema, string $table, string $column): bool
    {
        $safeSchema = $this->bracketSqlIdentifier($schema);
        $safeTable = $this->bracketSqlIdentifier($table);
        $safeColumn = $this->bracketSqlIdentifier($column);

        $sql = "SELECT TOP 1 1 AS found FROM {$safeSchema}.{$safeTable} WHERE {$safeColumn} IS NOT NULL";
        $row = DB::selectOne($sql);

        return $row !== null;
    }

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    private function escapeLikePattern(string $value): string
    {
        $value = str_replace('[', '[[]', $value);

        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<string, array<int, float|null>>
     */
    private function pdaTierPriceByItemKeyMap(): array
    {
        if ($this->pdaTierPriceByItemKeyCache !== null) {
            return $this->pdaTierPriceByItemKeyCache;
        }

        $this->pdaTierPriceByItemKeyCache = $this->buildPdaTierPriceByItemKeyMap();

        return $this->pdaTierPriceByItemKeyCache;
    }

    /**
     * Read-only EXEC of configured PDA procedure; no writes to the main database.
     *
     * @return array<string, array<int, float|null>>
     */
    private function buildPdaTierPriceByItemKeyMap(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $uuid = $this->normalizedPdaPricingUserUuid();
        if ($uuid === null) {
            return [];
        }

        $sp = $this->validatedPdaStoredProcedureName();
        if ($sp === null) {
            Log::warning('reporting.pda_pricing_sp rejected (allowed form: dbo.ProcedureName).');

            return [];
        }

        try {
            $rows = DB::select('EXEC '.$sp.' @USERID = ?', [$uuid]);
        } catch (Throwable $e) {
            Log::warning('pda_pricing_sp_exec_failed', ['message' => $e->getMessage(), 'sp' => $sp]);

            return [];
        }

        $pickMax = trim((string) config('reporting.pda_pricing_pick_unit', 'max_scale')) !== 'min_scale';
        /** @var array<string, array{scale: float, prices: array<int, float|null>}> $best */
        $best = [];

        foreach ($rows as $row) {
            $itemIdRaw = $this->pdaItemIdRefFromRow($row);
            if ($itemIdRaw === null || $itemIdRaw === '') {
                continue;
            }
            $key = $this->normalizeItemIdKey((string) $itemIdRaw);
            if ($key === '') {
                continue;
            }

            $scale = $this->pdaUnitScaleFromRow($row);
            $prices = [];
            $hasAnyPrice = false;
            for ($i = 1; $i <= 5; $i++) {
                $prices[$i] = $this->pdaTierPriceValueFromRow($row, $i);
                if ($prices[$i] !== null) {
                    $hasAnyPrice = true;
                }
            }
            if (! $hasAnyPrice) {
                continue;
            }

            if (! isset($best[$key])) {
                $best[$key] = ['scale' => $scale, 'prices' => $prices];

                continue;
            }

            if ($this->pdaUnitRowStrictlyBetter($scale, $best[$key]['scale'], $pickMax)) {
                $best[$key] = ['scale' => $scale, 'prices' => $prices];
            }
        }

        $out = [];
        foreach ($best as $key => $bundle) {
            $out[$key] = $bundle['prices'];
        }

        return $out;
    }

    /**
     * Item id on rows returned by {@see config('reporting.pda_pricing_sp')} (name varies by database).
     */
    private function pdaItemIdRefFromRow(object $row): ?string
    {
        foreach (['fld_item_id_ref', 'fld_item_id', 'fld_store_item_id_ref', 'fld_store_item_id'] as $col) {
            $v = $this->objectPropertyInsensitive($row, $col);
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }

        return null;
    }

    private function pdaUnitScaleFromRow(object $row): float
    {
        foreach (['fld_unit_scale', 'fld_scale', 'fld_unit_qty', 'fld_qty_per_unit', 'fld_conversion_factor'] as $col) {
            $v = $this->objectPropertyInsensitive($row, $col);
            if ($v !== null && $v !== '') {
                return (float) $v;
            }
        }

        return 0.0;
    }

    /**
     * @return list<string>
     */
    private function pdaTierPriceColumnCandidates(int $tierOneToFive): array
    {
        $n = (string) max(1, min(5, $tierOneToFive));

        return [
            'fld_p'.$n,
            'fld_sale_price'.$n,
            'fld_sell_price'.$n,
            'fld_price'.$n,
        ];
    }

    private function pdaTierPriceValueFromRow(object $row, int $tierOneToFive): ?float
    {
        foreach ($this->pdaTierPriceColumnCandidates($tierOneToFive) as $col) {
            $v = $this->objectPropertyInsensitive($row, $col);
            if ($v !== null && $v !== '') {
                return (float) $v;
            }
        }

        return null;
    }

    /**
     * Prefer strictly larger (or smaller) scale; on ties keep the first row seen (stable), avoiding arbitrary “last row wins” when scale is 0/unknown for every unit.
     */
    private function pdaUnitRowStrictlyBetter(float $scale, float $bestScale, bool $pickMax): bool
    {
        $eps = 1e-9;
        if ($pickMax) {
            return $scale > $bestScale + $eps;
        }

        return $scale < $bestScale - $eps;
    }

    private function normalizedPdaPricingUserUuid(): ?string
    {
        $uuid = trim((string) config('reporting.pda_pricing_user_uuid', ''));
        if ($uuid === '') {
            return null;
        }
        if (! preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $uuid)) {
            Log::warning('reporting.pda_pricing_user_uuid is not a valid GUID; PDA price overlay skipped.');

            return null;
        }

        return $uuid;
    }

    private function validatedPdaStoredProcedureName(): ?string
    {
        $sp = trim((string) config('reporting.pda_pricing_sp', 'dbo.SP_PDA_Get_Item_All_Units'));
        if (! preg_match('/^dbo\.[A-Za-z_][A-Za-z0-9_]*$/', $sp)) {
            return null;
        }

        return $sp;
    }

    private function normalizeItemIdKey(string $itemId): string
    {
        $itemId = trim(strtolower(str_replace(['{', '}', ' '], '', $itemId)));

        return $itemId;
    }

    /**
     * @return mixed
     */
    private function objectPropertyInsensitive(object $object, string $name)
    {
        if (property_exists($object, $name)) {
            return $object->{$name};
        }

        $want = strtolower($name);
        foreach (get_object_vars($object) as $key => $value) {
            if (strtolower((string) $key) === $want) {
                return $value;
            }
        }

        return null;
    }

    private function applyPdaTierPricesToRow(stdClass $row): void
    {
        $map = $this->pdaTierPriceByItemKeyMap();
        if ($map === []) {
            return;
        }

        $key = $this->normalizeItemIdKey((string) ($row->item_id ?? ''));
        if ($key === '' || ! isset($map[$key])) {
            return;
        }

        $prices = $map[$key];
        for ($i = 1; $i <= 5; $i++) {
            if (! array_key_exists($i, $prices) || $prices[$i] === null) {
                continue;
            }
            $row->{'price'.$i} = $prices[$i];
        }
    }

    private function pdaTierPriceForItemAndTier(string $itemId, int $tierOneToFive): ?float
    {
        $map = $this->pdaTierPriceByItemKeyMap();
        if ($map === []) {
            return null;
        }

        $key = $this->normalizeItemIdKey($itemId);
        if ($key === '' || ! isset($map[$key][$tierOneToFive])) {
            return null;
        }

        $v = $map[$key][$tierOneToFive];

        return $v === null ? null : (float) $v;
    }
}
