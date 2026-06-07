<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class CitiesReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    private const MAX_EXPORT_ROWS = 10000;

    public function __construct(
        private readonly VisitsReportRepository $visits
    ) {}

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
     * @param  list<string>|null  $salesmanIds
     * @return list<string>
     */
    public function normalizeSalesmanIds(?array $salesmanIds): array
    {
        if ($salesmanIds === null || $salesmanIds === []) {
            return [];
        }
        $out = [];
        foreach ($salesmanIds as $id) {
            if (! is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            if (preg_match('/^[0-9A-Fa-f-]{36}$/', $id)) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $normalizedSalesmanIds
     * @return array{0: string, 1: list<string>}
     */
    private function salesmanWhereClause(array $normalizedSalesmanIds): array
    {
        if ($normalizedSalesmanIds === []) {
            return ['', []];
        }
        $placeholders = implode(',', array_fill(0, count($normalizedSalesmanIds), 'CAST(? AS UNIQUEIDENTIFIER)'));

        return [
            ' AND a.fld_sales_man_id_ref IN ('.$placeholders.') ',
            $normalizedSalesmanIds,
        ];
    }

    /**
     * Read-only sales aggregates from store document lines.
     *
     * Amount: quantity × unit price (line extension before tax/discount complexity).
     * Weight: quantity × item weight from tbl_store_item_setting (one row per item via subquery).
     *
     * @param  list<string>  $cities
     * @return LengthAwarePaginator<int, stdClass>|array{0: stdClass}
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        int $page,
        int $perPage,
        array $cities = [],
        array $salesmanIds = []
    ): LengthAwarePaginator|array {
        extract($this->postedSalesQueryContext('s'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $baseFrom = "
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

        if (! $groupByClient) {
            $sql = "
                SELECT
                    SUM({$lineQtyExpr}) AS units_sold,
                    SUM({$lineAmountExpr}) AS amount,
                    SUM({$lineWeightExpr}) AS weight_total
                {$baseFrom}
            ";

            $row = DB::selectOne($sql, $bindings);

            return [$row ?? (object) [
                'units_sold' => null,
                'amount' => null,
                'weight_total' => null,
            ]];
        }

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp_row
                {$baseFrom}
                GROUP BY
                    a.fld_account_id,
                    a.fld_account_code,
                    a.fld_account_name,
                    t.fld_person_name
            ) AS grp
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name
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
     * Grand totals across all matching document lines (full filter set, not current page).
     *
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     */
    public function getMetricGrandTotals(
        string $dateFrom,
        string $dateTo,
        array $cities = [],
        array $salesmanIds = [],
        ?string $searchDescription = null
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Cities report requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('s'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $itemsJoin = '';
        $searchSql = '';
        $q = trim((string) ($searchDescription ?? ''));
        if ($q !== '') {
            $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
            $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
            $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
            $itemsJoin = "
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref";
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
        }

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$itemsJoin}
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
              {$searchSql}
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
     * Sales by store item description (chicken category). SQL Server only.
     *
     * @param  list<string>  $cities
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdown(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $cities = [],
        array $salesmanIds = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $searchSql = '';
        $q = trim((string) ($searchDescription ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
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
              {$salesmanSql}
              {$searchSql}
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
                {$categoryExpr} AS chicken_category,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY {$categoryExpr}
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
     * Sales by client and store item description (category per customer). SQL Server only.
     *
     * @param  list<string>  $cities
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdownByClient(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $cities = [],
        array $salesmanIds = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $searchSql = '';
        $q = trim((string) ($searchDescription ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
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
              {$salesmanSql}
              {$searchSql}
        ";

        $groupByClientCategory = '
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name,
                '.$categoryExpr;

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp
                {$baseFrom}
                GROUP BY {$groupByClientCategory}
            ) AS grp
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                {$categoryExpr} AS chicken_category,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name,
                {$categoryExpr}
            ORDER BY client_name ASC, amount DESC
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
     * Up to MAX_EXPORT_ROWS rows for CSV/PDF (same filters as on-screen report).
     *
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function exportReportRows(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        array $cities,
        array $salesmanIds = []
    ): array {
        extract($this->postedSalesQueryContext('s'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $baseFrom = "
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

        $limit = self::MAX_EXPORT_ROWS;

        if (! $groupByClient) {
            $sql = "
                SELECT
                    SUM({$lineQtyExpr}) AS units_sold,
                    SUM({$lineAmountExpr}) AS amount,
                    SUM({$lineWeightExpr}) AS weight_total
                {$baseFrom}
            ";

            $row = DB::selectOne($sql, $bindings);

            return [$row ?? (object) [
                'units_sold' => null,
                'amount' => null,
                'weight_total' => null,
            ]];
        }

        $dataSql = "
            SELECT
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($dataSql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function exportChickenCategoryRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $cities,
        array $salesmanIds = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $searchSql = '';
        $q = trim((string) ($searchDescription ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
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
              {$salesmanSql}
              {$searchSql}
        ";

        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                {$categoryExpr} AS chicken_category,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY {$categoryExpr}
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($dataSql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function exportChickenCategoryByClientRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $cities,
        array $salesmanIds = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $searchSql = '';
        $q = trim((string) ($searchDescription ?? ''));
        if ($q !== '') {
            $searchSql = ' AND LTRIM(RTRIM(CAST(COALESCE(i.'.$descCol.', N\'\') AS NVARCHAR(500)))) LIKE ? ESCAPE N\'\\\' ';
            $bindings[] = '%'.$this->escapeLikePattern($q).'%';
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
              {$salesmanSql}
              {$searchSql}
        ";

        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                {$categoryExpr} AS chicken_category,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name,
                {$categoryExpr}
            ORDER BY client_name ASC, amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($dataSql, $bindings);
    }

    /**
     * Daily sales totals for time-series charts (amount, quantity, weight, customers, invoices). One row per calendar day in range.
     *
     * @param  list<string>  $cities
     * @return list<stdClass> sale_date, units_sold, amount, weight_total, customer_count, invoice_count
     */
    public function getSalesOverTimeChartSeries(string $dateFrom, string $dateTo, array $cities, array $salesmanIds = []): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        extract($this->postedSalesQueryContext('s'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);

        $baseFrom = "
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

        $sql = "
            SELECT
                CAST(t.fld_store_document_title_date AS date) AS sale_date,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total,
                COUNT(DISTINCT t.fld_account_id_ref) AS customer_count,
                COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count
            {$baseFrom}
            GROUP BY CAST(t.fld_store_document_title_date AS date)
            ORDER BY CAST(t.fld_store_document_title_date AS date)
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $memberCities
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getGovernorateCategoryBreakdown(
        string $dateFrom,
        string $dateTo,
        string $governorateCity,
        array $memberCities,
        ?string $excludeCategory,
        int $page,
        int $perPage,
        array $salesmanIds = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Governorate category breakdown requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $governorateCity = trim($governorateCity);
        $memberCities = $this->normalizeCities($memberCities);
        $targetCities = $memberCities;
        if ($governorateCity !== '') {
            $targetCities[] = $governorateCity;
        }
        $targetCities = array_values(array_unique($targetCities));
        if ($targetCities === []) {
            return new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        extract($this->postedSalesQueryContext('w'));

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $cityExpr = $this->cityExprSql();
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $excludeSql = '';
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($targetCities);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);
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
              {$salesmanSql}
              {$excludeSql}
        ";

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp
                {$baseFrom}
                GROUP BY {$cityExpr}, {$categoryExpr}
            ) AS grouped_rows
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);
        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                {$cityExpr} AS city_name,
                {$categoryExpr} AS item_category,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY {$cityExpr}, {$categoryExpr}
            ORDER BY city_name ASC, amount DESC
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
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function getPieByCitySeries(string $dateFrom, string $dateTo, array $cities, ?string $excludeCategory, array $salesmanIds = []): array
    {
        return $this->pieByDimension(
            $dateFrom,
            $dateTo,
            $cities,
            $salesmanIds,
            $this->cityExprSql(),
            'city_name',
            $this->itemsJoinSql(),
            $excludeCategory
        );
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function getPieBySalesmanSeries(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $excludeCategory,
        array $salesmanIds = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        extract($this->postedSalesQueryContext('w'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = ' AND '.$this->itemCategoryExpr('i').' <> ? ';
            $bindings[] = $excluded;
        }

        $sql = "
            SELECT
                COALESCE(CAST(a.fld_sales_man_id_ref AS NVARCHAR(100)), N'') AS salesman_id,
                SUM({$lineAmountExpr}) AS amount
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$this->itemsJoinSql()}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
              {$excludeSql}
            GROUP BY COALESCE(CAST(a.fld_sales_man_id_ref AS NVARCHAR(100)), N'')
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT 50 ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function getPieByCategorySeries(string $dateFrom, string $dateTo, array $cities, ?string $excludeCategory, array $salesmanIds = []): array
    {
        $categoryExpr = $this->itemCategoryExpr('i');

        return $this->pieByDimension(
            $dateFrom,
            $dateTo,
            $cities,
            $salesmanIds,
            $categoryExpr,
            'item_category',
            $this->itemsJoinSql(),
            $excludeCategory
        );
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    public function getPieByItemSeries(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $category,
        ?string $excludeCategory,
        array $salesmanIds = []
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        extract($this->postedSalesQueryContext('w'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);
        $categoryExpr = $this->itemCategoryExpr('i');
        $itemExpr = $this->itemNameExpr('i');
        $filterCategorySql = '';
        $excludeSql = '';
        $categoryValue = trim((string) ($category ?? ''));
        if ($categoryValue !== '') {
            $filterCategorySql = " AND {$categoryExpr} = ? ";
            $bindings[] = $categoryValue;
        }
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = " AND {$categoryExpr} <> ? ";
            $bindings[] = $excluded;
        }
        $joins = $this->itemsJoinSql();

        $sql = "
            SELECT
                {$itemExpr} AS item_name,
                SUM({$lineAmountExpr}) AS amount
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$joins}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
              {$filterCategorySql}
              {$excludeSql}
            GROUP BY {$itemExpr}
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT 50 ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return list<string>
     */
    public function getItemCategoryOptions(string $dateFrom, string $dateTo, array $cities, array $salesmanIds = []): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        extract($this->postedSalesQueryContext('w'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);
        $categoryExpr = $this->itemCategoryExpr('i');
        $joins = $this->itemsJoinSql();

        $sql = "
            SELECT DISTINCT {$categoryExpr} AS item_category
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$joins}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
            ORDER BY item_category ASC
        ";

        return array_values(array_map(
            static fn (object $row): string => (string) ($row->item_category ?? ''),
            DB::select($sql, $bindings)
        ));
    }

    private function cityExprSql(): string
    {
        $cityCol = $this->visits->getAccountCityColumnName();
        if (! is_string($cityCol) || trim($cityCol) === '') {
            return "N''";
        }

        return 'LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketSqlIdentifier($cityCol).", N'') AS NVARCHAR(500))))";
    }

    private function itemsJoinSql(): string
    {
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));

        return "LEFT JOIN {$itemsTable} AS i ON i.{$pkCol} = d.fld_item_id_ref";
    }

    private function itemCategoryExpr(string $alias): string
    {
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        return "COALESCE(NULLIF(LTRIM(RTRIM(CAST({$alias}.{$descCol} AS NVARCHAR(500)))), N''), N'(uncategorized)')";
    }

    private function itemNameExpr(string $alias): string
    {
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));

        return "COALESCE(NULLIF(LTRIM(RTRIM(CAST({$alias}.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)')";
    }

    /**
     * @param  list<string>  $cities
     * @return list<stdClass>
     */
    private function pieByDimension(
        string $dateFrom,
        string $dateTo,
        array $cities,
        array $salesmanIds,
        string $dimensionExpr,
        string $dimensionAlias,
        ?string $extraJoinSql,
        ?string $excludeCategory
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        extract($this->postedSalesQueryContext('w'));

        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->cityAccountWhereClause($cityIds);
        $salesmanIds = $this->normalizeSalesmanIds($salesmanIds);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($salesmanIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings, $salesmanBindings);
        $join = trim((string) $extraJoinSql);
        $excludeSql = '';
        $excluded = trim((string) ($excludeCategory ?? ''));
        if ($excluded !== '') {
            $excludeSql = ' AND '.$this->itemCategoryExpr('i').' <> ? ';
            $bindings[] = $excluded;
        }

        $sql = "
            SELECT
                {$dimensionExpr} AS {$dimensionAlias},
                SUM({$lineAmountExpr}) AS amount
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$join}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              {$citySql}
              {$salesmanSql}
              {$excludeSql}
            GROUP BY {$dimensionExpr}
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT 50 ROWS ONLY
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
