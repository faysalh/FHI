<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * Period item totals from AsanMax summary ledger:
 * {@see tbl_multi_store_item_summary} with {@code fld_type_alias = 'S'}.
 * Amount uses monthly-style net (line % + per-unit extra × qty).
 */
class ComparisonAsanReportRepository
{
    /**
     * @param  list<string>  $cities
     * @return list<string>
     */
    public function getCategoryOptions(string $dateFrom, string $dateTo, array $cities, ?string $salesmanId): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Asan comparison report requires SQL Server (sqlsrv).');
        }

        [$citySql, $bindings] = $this->cityAndSalesmanBindings($dateFrom, $dateTo, $cities, $salesmanId);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketColumn((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketColumn((string) config('reporting.store_items_description_column', 'fld_description'));
        // Same category source as Posted sales comparison (item description).
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";

        $sql = "
            SELECT DISTINCT {$categoryExpr} AS category_name
            FROM dbo.tbl_multi_store_item_summary AS m
            INNER JOIN {$itemsTable} AS itm
                ON itm.{$pkCol} = m.fld_item_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS ac
                ON ac.fld_account_id = m.fld_account_id_ref
            WHERE m.fld_type_alias = N'S'
              AND CONVERT(date, m.fld_date) >= CONVERT(date, ?)
              AND CONVERT(date, m.fld_date) <= CONVERT(date, ?)
              {$citySql}
            ORDER BY category_name ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function getItemRows(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanId,
        ?string $excludeCategory = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Asan comparison report requires SQL Server (sqlsrv).');
        }

        [$citySql, $bindings] = $this->cityAndSalesmanBindings($dateFrom, $dateTo, $cities, $salesmanId);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketColumn((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketColumn((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketColumn((string) config('reporting.store_items_description_column', 'fld_description'));
        // Same category / item labels as Posted sales comparison so Assembly order matches.
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(itm.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";

        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }

        // Qty from scaled summary qty. Amount matches monthly-style Asan math for type S:
        // gross − (qty × price × disc%) − (ABS(qty) × ABS(extra_unit_discount)).
        // Weight is qty × item setting weight (UI parity).
        $sql = "
            SELECT
                {$categoryExpr} AS category_name,
                {$itemExpr} AS item_name,
                SUM(ABS(CAST(COALESCE(m.fld_scaled_qty, 0) AS decimal(24, 6)))) AS quantity_total,
                SUM(
                    ABS(CAST(COALESCE(m.fld_quantity, 0) AS decimal(24, 6)))
                    * CAST(COALESCE(m.fld_unit_price, 0) AS decimal(24, 6))
                    - ABS(CAST(COALESCE(m.fld_quantity, 0) AS decimal(24, 6)))
                      * CAST(COALESCE(m.fld_unit_price, 0) AS decimal(24, 6))
                      * (CAST(COALESCE(m.fld_discount_percent, 0) AS decimal(24, 6)) / CAST(100.0 AS decimal(24, 6)))
                    - ABS(CAST(COALESCE(m.fld_quantity, 0) AS decimal(24, 6)))
                      * ABS(CAST(COALESCE(m.fld_extra_unit_discount, 0) AS decimal(24, 6)))
                ) AS amount_total,
                SUM(
                    ABS(CAST(COALESCE(m.fld_scaled_qty, 0) AS decimal(24, 6)))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
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
            WHERE m.fld_type_alias = N'S'
              AND CONVERT(date, m.fld_date) >= CONVERT(date, ?)
              AND CONVERT(date, m.fld_date) <= CONVERT(date, ?)
              {$citySql}
              {$excludeSql}
            GROUP BY {$categoryExpr}, {$itemExpr}
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<mixed>}
     */
    private function cityAndSalesmanBindings(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanId
    ): array {
        $bindings = [$dateFrom, $dateTo];
        $citySql = '';
        $cityColumn = $this->resolveAccountCityColumn();
        if ($cities !== []) {
            $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $city): bool => $city !== ''));
            if ($cities !== [] && $cityColumn !== null) {
                $placeholders = implode(',', array_fill(0, count($cities), '?'));
                $citySql .= ' AND LTRIM(RTRIM(CAST(COALESCE(ac.'.$this->bracketColumn($cityColumn).', N\'\') AS NVARCHAR(500)))) IN ('.$placeholders.') ';
                $bindings = array_merge($bindings, $cities);
            }
        }

        $salesmanValue = trim((string) ($salesmanId ?? ''));
        if ($salesmanValue !== '') {
            // AsanMax ItemBySales filters salesman on the summary row.
            $citySql .= ' AND m.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanValue;
        }

        return [$citySql, $bindings];
    }

    private function resolveAccountCityColumn(): ?string
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return null;
        }

        $candidates = ['fld_city', 'fld_city_name', 'city', 'fld_account_city'];
        foreach ($candidates as $column) {
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

        $row = DB::selectOne(
            "SELECT TOP 1 COLUMN_NAME
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = N'dbo'
               AND TABLE_NAME = N'tbl_accounting_accounts'
               AND COLUMN_NAME LIKE N'%city%'
             ORDER BY COLUMN_NAME"
        );

        if ($row !== null) {
            return (string) $row->COLUMN_NAME;
        }

        Log::warning('comparison_asan.city_column_not_found');

        return null;
    }

    private function bracketColumn(string $column): string
    {
        return '['.str_replace(']', ']]', $column).']';
    }
}
