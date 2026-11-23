<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * Item sales inspired by AsanMax ItemBasedMasterReport: posted sales (S, POS)
 * and sales returns (SR, POSR) on tbl_multi_store_item_summary, one row per item.
 */
class ItemBasedSalesReportRepository
{
    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function rows(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanId,
        string $category,
        string $search
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Item sales report requires SQL Server (sqlsrv).');
        }

        [$filterSql, $bindings] = $this->filters($dateFrom, $dateTo, $cities, $salesmanId, $category, $search);
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracket((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracket((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracket((string) config('reporting.store_items_description_column', 'fld_description'));
        $codeCol = $this->itemCodeColumn();
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";
        $codeExpr = $codeCol === null
            ? "N''"
            : "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$this->bracket($codeCol)} AS NVARCHAR(200)))), N''), N'')";

        $salesType = "m.fld_type_alias IN (N'S', N'POS')";
        $returnType = "m.fld_type_alias IN (N'SR', N'POSR')";
        $qty = 'ABS(CAST(COALESCE(m.fld_scaled_qty, 0) AS decimal(24, 6)))';
        $lineQty = 'ABS(CAST(COALESCE(m.fld_quantity, 0) AS decimal(24, 6)))';
        $price = 'CAST(COALESCE(m.fld_unit_price, 0) AS decimal(24, 6))';
        $disc = 'CAST(COALESCE(m.fld_discount_percent, 0) AS decimal(24, 6))';
        $extra = 'ABS(CAST(COALESCE(m.fld_extra_unit_discount, 0) AS decimal(24, 6)))';
        $netLine = "({$lineQty} * {$price}) - ({$lineQty} * {$price} * ({$disc} / CAST(100.0 AS decimal(24, 6)))) - ({$lineQty} * {$extra})";
        $discountLine = "({$lineQty} * {$price} * ({$disc} / CAST(100.0 AS decimal(24, 6)))) + ({$lineQty} * {$extra})";

        $sql = "
            SELECT
                {$codeExpr} AS item_code,
                {$itemExpr} AS item_name,
                {$categoryExpr} AS category_name,
                SUM(CASE WHEN {$salesType} THEN {$qty} ELSE 0 END) AS sales_qty,
                SUM(CASE WHEN {$salesType} THEN ABS(CAST(COALESCE(m.fld_scaled_free_qty, 0) AS decimal(24, 6))) ELSE 0 END) AS free_qty,
                SUM(CASE WHEN {$salesType} THEN {$discountLine} ELSE 0 END) AS sales_discount,
                SUM(CASE WHEN {$salesType} THEN {$netLine} ELSE 0 END) AS sales_amount,
                SUM(CASE WHEN {$returnType} THEN {$qty} ELSE 0 END) AS return_qty,
                SUM(CASE WHEN {$returnType} THEN {$netLine} ELSE 0 END) AS return_amount,
                SUM(CASE WHEN {$salesType} THEN {$qty} * CAST(COALESCE(w.fld_weight, 0) AS float)
                         WHEN {$returnType} THEN -1 * {$qty} * CAST(COALESCE(w.fld_weight, 0) AS float)
                         ELSE 0 END) AS weight_total
            FROM dbo.tbl_multi_store_item_summary AS m
            INNER JOIN {$itemsTable} AS itm
                ON itm.{$pkCol} = m.fld_item_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS ac
                ON ac.fld_account_id = m.fld_account_id_ref
            LEFT JOIN (
                SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
                FROM dbo.tbl_store_item_setting
                GROUP BY fld_item_id_ref
            ) AS w
                ON w.fld_item_id_ref = m.fld_item_id_ref
            WHERE m.fld_type_alias IN (N'S', N'POS', N'SR', N'POSR')
              AND CONVERT(date, m.fld_date) >= CONVERT(date, ?)
              AND CONVERT(date, m.fld_date) <= CONVERT(date, ?)
              {$filterSql}
            GROUP BY {$codeExpr}, {$itemExpr}, {$categoryExpr}
            HAVING SUM(CASE WHEN {$salesType} THEN {$qty} ELSE 0 END) <> 0
                OR SUM(CASE WHEN {$returnType} THEN {$qty} ELSE 0 END) <> 0
            ORDER BY category_name ASC, item_name ASC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return list<string>
     */
    public function categoryOptions(string $dateFrom, string $dateTo, array $cities, ?string $salesmanId): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        [$filterSql, $bindings] = $this->filters($dateFrom, $dateTo, $cities, $salesmanId, '', '');
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracket((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracket((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";

        $sql = "
            SELECT DISTINCT {$categoryExpr} AS category_name
            FROM dbo.tbl_multi_store_item_summary AS m
            INNER JOIN {$itemsTable} AS itm
                ON itm.{$pkCol} = m.fld_item_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS ac
                ON ac.fld_account_id = m.fld_account_id_ref
            WHERE m.fld_type_alias IN (N'S', N'POS', N'SR', N'POSR')
              AND CONVERT(date, m.fld_date) >= CONVERT(date, ?)
              AND CONVERT(date, m.fld_date) <= CONVERT(date, ?)
              {$filterSql}
            ORDER BY category_name ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<mixed>}
     */
    private function filters(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanId,
        string $category,
        string $search
    ): array {
        $bindings = [$dateFrom, $dateTo];
        $sql = '';

        $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $city): bool => $city !== ''));
        $cityColumn = $cities === [] ? null : $this->accountCityColumn();
        if ($cities !== [] && $cityColumn !== null) {
            $placeholders = implode(',', array_fill(0, count($cities), '?'));
            $sql .= ' AND LTRIM(RTRIM(CAST(COALESCE(ac.'.$this->bracket($cityColumn).", N'') AS NVARCHAR(500)))) IN ({$placeholders}) ";
            $bindings = array_merge($bindings, $cities);
        }

        $salesman = trim((string) $salesmanId);
        if ($salesman !== '') {
            $sql .= ' AND m.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesman;
        }

        $category = trim($category);
        if ($category !== '') {
            $descCol = $this->bracket((string) config('reporting.store_items_description_column', 'fld_description'));
            $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
            $sql .= " AND {$categoryExpr} = ? ";
            $bindings[] = $category;
        }

        $search = trim($search);
        if ($search !== '') {
            $nameCol = $this->bracket((string) config('reporting.store_items_name_column', 'fld_item_name'));
            $like = '%'.$search.'%';
            $codeCol = $this->itemCodeColumn();
            if ($codeCol === null) {
                $sql .= " AND CAST(itm.{$nameCol} AS NVARCHAR(500)) LIKE ? ";
                $bindings[] = $like;
            } else {
                $sql .= " AND (CAST(itm.{$nameCol} AS NVARCHAR(500)) LIKE ? OR CAST(itm.{$this->bracket($codeCol)} AS NVARCHAR(200)) LIKE ?) ";
                $bindings[] = $like;
                $bindings[] = $like;
            }
        }

        return [$sql, $bindings];
    }

    private function itemCodeColumn(): ?string
    {
        foreach (['fld_item_code', 'fld_code', 'fld_item_number', 'fld_barcode'] as $column) {
            $exists = DB::selectOne(
                "SELECT TOP 1 1 AS x
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = N'dbo'
                   AND TABLE_NAME = N'tbl_store_items'
                   AND COLUMN_NAME = ?",
                [$column]
            );
            if ($exists !== null) {
                return $column;
            }
        }

        return null;
    }

    private function accountCityColumn(): ?string
    {
        foreach (['fld_city', 'fld_city_name', 'city', 'fld_account_city'] as $column) {
            $exists = DB::selectOne(
                "SELECT TOP 1 1 AS x
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = N'dbo'
                   AND TABLE_NAME = N'tbl_accounting_accounts'
                   AND COLUMN_NAME = ?",
                [$column]
            );
            if ($exists !== null) {
                return $column;
            }
        }

        Log::warning('item_based_sales.city_column_not_found');

        return null;
    }

    private function bracket(string $column): string
    {
        return '['.str_replace(']', ']]', $column).']';
    }
}
