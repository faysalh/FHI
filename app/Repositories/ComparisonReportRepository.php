<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

class ComparisonReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    /**
     * @param  list<string>  $cities
     * @return list<string>
     */
    public function getCategoryOptions(string $dateFrom, string $dateTo, array $cities, ?string $salesmanId): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Comparison report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketColumn((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketColumn((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";

        $bindings = [$dateFrom, $dateTo];
        $citySql = '';
        $cityColumn = $this->resolveAccountCityColumn();
        if ($cities !== []) {
            $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $city): bool => $city !== ''));
            if ($cities !== [] && $cityColumn !== null) {
                $placeholders = implode(',', array_fill(0, count($cities), '?'));
                $citySql = ' AND LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketColumn($cityColumn).', N\'\') AS NVARCHAR(500)))) IN ('.$placeholders.') ';
                $bindings = array_merge($bindings, $cities);
            }
        }

        $salesmanSql = '';
        $salesmanValue = trim((string) ($salesmanId ?? ''));
        if ($salesmanValue !== '') {
            $salesmanSql = ' AND a.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanValue;
        }

        $sql = "
            SELECT DISTINCT {$categoryExpr} AS category_name
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
            ORDER BY category_name ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    /**
     * @param  list<string>  $cities
     */
    public function getTotals(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $salesmanId
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Comparison report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('s'));
        $weightSub = $weightSubquery;

        $bindings = [$dateFrom, $dateTo];
        $citySql = '';
        $cityColumn = $this->resolveAccountCityColumn();
        if ($cities !== []) {
            $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $city): bool => $city !== ''));
            if ($cities !== [] && $cityColumn !== null) {
                $placeholders = implode(',', array_fill(0, count($cities), '?'));
                $citySql = ' AND LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketColumn($cityColumn).', N\'\') AS NVARCHAR(500)))) IN ('.$placeholders.') ';
                $bindings = array_merge($bindings, $cities);
            }
        }

        $salesmanSql = '';
        $salesmanValue = trim((string) ($salesmanId ?? ''));
        if ($salesmanValue !== '') {
            $salesmanSql = ' AND a.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanValue;
        }

        $sql = "
            SELECT
                SUM({$lineQtyExpr}) AS quantity_total,
                SUM({$lineAmountExpr}) AS amount_total,
                SUM({$lineWeightExpr}) AS weight_total
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? (object) [
            'quantity_total' => 0,
            'amount_total' => 0,
            'weight_total' => 0,
        ];
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
            throw new RuntimeException('Comparison report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('s'));
        $weightSub = $weightSubquery;

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketColumn((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketColumn((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $descCol = $this->bracketColumn((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
        $itemExpr = "COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";

        $bindings = [$dateFrom, $dateTo];
        $citySql = '';
        $cityColumn = $this->resolveAccountCityColumn();
        if ($cities !== []) {
            $cities = array_values(array_filter(array_map('trim', $cities), static fn (string $city): bool => $city !== ''));
            if ($cities !== [] && $cityColumn !== null) {
                $placeholders = implode(',', array_fill(0, count($cities), '?'));
                $citySql = ' AND LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketColumn($cityColumn).', N\'\') AS NVARCHAR(500)))) IN ('.$placeholders.') ';
                $bindings = array_merge($bindings, $cities);
            }
        }

        $salesmanSql = '';
        $salesmanValue = trim((string) ($salesmanId ?? ''));
        if ($salesmanValue !== '') {
            $salesmanSql = ' AND a.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanValue;
        }

        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }

        $sql = "
            SELECT
                {$categoryExpr} AS category_name,
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS quantity_total,
                SUM({$lineAmountExpr}) AS amount_total,
                SUM({$lineWeightExpr}) AS weight_total
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
              {$excludeSql}
            GROUP BY {$categoryExpr}, {$itemExpr}
        ";

        return DB::select($sql, $bindings);
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

        Log::warning('comparison.city_column_not_found');

        return null;
    }

    private function bracketColumn(string $column): string
    {
        return '['.str_replace(']', ']]', $column).']';
    }
}
