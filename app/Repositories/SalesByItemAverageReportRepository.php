<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class SalesByItemAverageReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    private const MAX_EXPORT_ROWS = 10000;

    public function __construct(
        private readonly VisitsReportRepository $visits
    ) {
    }

    /**
     * @param  list<string>|null  $cities
     * @return list<string>
     */
    public function normalizeCities(?array $cities): array
    {
        if ($cities === null || $cities === []) {
            return [];
        }
        $out = [];
        foreach ($cities as $c) {
            if (! is_string($c)) {
                continue;
            }
            $c = trim($c);
            if ($c === '' || mb_strlen($c) > 200) {
                continue;
            }
            $out[] = $c;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $normalizedCities
     * @return array{0: string, 1: list<string>}
     */
    private function cityAccountWhereClause(array $normalizedCities): array
    {
        return $this->visits->sqlFilterAccountCityEquals('a', $normalizedCities);
    }

    /**
     * @return list<string>
     */
    public function getCategoryOptions(string $dateFrom, string $dateTo, ?string $searchItem, array $cities): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item average report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);

        $searchSql = '';
        $q = trim((string) ($searchItem ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
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
              {$searchSql}
            ORDER BY category_name ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->category_name ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        ?string $searchItem,
        ?string $excludeCategory,
        array $cities,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item average report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $balanceSub = '
            SELECT fld_item_id_ref, SUM(CAST(fld_item_balance AS float)) AS fld_item_balance
            FROM dbo.tbl_store_item_informations
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);

        $searchSql = '';
        $q = trim((string) ($searchItem ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
        }
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            LEFT JOIN ({$balanceSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$searchSql}
              {$excludeSql}
        ";

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp
                {$baseFrom}
                GROUP BY {$categoryExpr}
            ) AS grp
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $dataSql = "
            SELECT
                x.category_name,
                SUM(x.units_sold) AS units_sold,
                SUM(x.amount) AS amount,
                SUM(x.weight_total) AS weight_total,
                SUM(x.storage_balance) AS storage_balance
            FROM (
                SELECT
                    {$categoryExpr} AS category_name,
                    i.{$pkCol} AS item_id,
                    SUM({$lineQtyExpr}) AS units_sold,
                    SUM({$lineAmountExpr}) AS amount,
                    SUM({$lineWeightExpr}) AS weight_total,
                    MAX(CAST(COALESCE(s.fld_item_balance, 0) AS float)) AS storage_balance
                {$baseFrom}
                GROUP BY {$categoryExpr}, i.{$pkCol}
            ) AS x
            GROUP BY x.category_name
            ORDER BY amount DESC
            OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
        ";

        $items = DB::select($dataSql, $bindings);

        return new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * Grand totals for units sold, amount, and weight (full filter set).
     *
     * @param  list<string>  $cities
     */
    public function getGrandTotals(
        string $dateFrom,
        string $dateTo,
        ?string $searchItem,
        ?string $excludeCategory,
        array $cities
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item average report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);

        $searchSql = '';
        $q = trim((string) ($searchItem ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
        }
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$searchSql}
              {$excludeSql}
        ";

        $sql = "
            SELECT
                COALESCE(SUM({$lineQtyExpr}), 0) AS units_sold,
                COALESCE(SUM({$lineAmountExpr}), 0) AS amount,
                COALESCE(SUM({$lineWeightExpr}), 0) AS weight_total
            {$baseFrom}
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? (object) [
            'units_sold' => 0,
            'amount' => 0,
            'weight_total' => 0,
        ];
    }

    /**
     * @return list<stdClass>
     */
    public function getCategoryItems(
        string $dateFrom,
        string $dateTo,
        string $category,
        ?string $excludeCategory,
        array $cities
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item average report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';

        $balanceSub = '
            SELECT fld_item_id_ref, SUM(CAST(fld_item_balance AS float)) AS fld_item_balance
            FROM dbo.tbl_store_item_informations
            GROUP BY fld_item_id_ref
        ';

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, [$category]);
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }
        $limit = self::MAX_EXPORT_ROWS;
        $sql = "
            SELECT
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total,
                MAX(CAST(COALESCE(s.fld_item_balance, 0) AS float)) AS storage_balance
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            LEFT JOIN ({$balanceSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              AND {$categoryExpr} = ?
              {$excludeSql}
            GROUP BY {$itemExpr}
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @return list<stdClass>
     */
    public function exportRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchItem,
        ?string $category,
        ?string $excludeCategory,
        array $cities
    ): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by item average report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));

        $balanceSub = '
            SELECT fld_item_id_ref, SUM(CAST(fld_item_balance AS float)) AS fld_item_balance
            FROM dbo.tbl_store_item_informations
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);

        $searchSql = '';
        $q = trim((string) ($searchItem ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
        }
        $categorySql = '';
        $categoryValue = trim((string) ($category ?? ''));
        if ($categoryValue !== '') {
            $categorySql = " AND {$categoryExpr} = ? ";
            $bindings[] = $categoryValue;
        }
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }
        $groupByExpr = $categoryExpr.', '.$itemExpr;

        $limit = self::MAX_EXPORT_ROWS;
        $sql = "
            SELECT
                {$categoryExpr} AS category_name,
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total,
                MAX(CAST(COALESCE(s.fld_item_balance, 0) AS float)) AS storage_balance
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            LEFT JOIN ({$balanceSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$searchSql}
              {$categorySql}
              {$excludeSql}
            GROUP BY {$groupByExpr}
            ORDER BY category_name ASC, amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }

    private function escapeLikePattern(string $value): string
    {
        $value = str_replace('[', '[[]', $value);

        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }
}
