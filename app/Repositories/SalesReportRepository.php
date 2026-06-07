<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class SalesReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    private const MAX_EXPORT_ROWS = 10000;

    private const MAX_DRILLDOWN_ROWS = 2000;

    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

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
        foreach ($cities as $city) {
            if (! is_string($city)) {
                continue;
            }
            $city = trim($city);
            if ($city === '' || mb_strlen($city) > 200) {
                continue;
            }
            $out[] = $city;
        }

        return array_values(array_unique($out));
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
     * @param  list<string>|null  $customerAccountIds
     * @return list<string>
     */
    public function normalizeCustomerAccountIds(?array $customerAccountIds): array
    {
        if ($customerAccountIds === null || $customerAccountIds === []) {
            return [];
        }
        $out = [];
        foreach ($customerAccountIds as $id) {
            if (! is_string($id)) {
                continue;
            }
            $id = trim($id);
            if ($id === '') {
                continue;
            }
            if (preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $id)) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $normalizedIds
     * @return array{0: string, 1: list<string>}
     */
    private function customerAccountWhereClause(array $normalizedIds): array
    {
        if ($normalizedIds === []) {
            return ['', []];
        }
        $placeholders = implode(',', array_fill(0, count($normalizedIds), 'CAST(? AS UNIQUEIDENTIFIER)'));

        return [
            ' AND t.fld_account_id_ref IN ('.$placeholders.') ',
            $normalizedIds,
        ];
    }

    /**
     * @return list<string>
     */
    public function getStorageOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            $rows = DB::select(
                "
                SELECT DISTINCT LTRIM(RTRIM(CAST(s.fld_store_name AS NVARCHAR(500)))) AS store_name
                FROM dbo.tbl_stores AS s
                WHERE s.fld_store_name IS NOT NULL
                  AND LTRIM(RTRIM(CAST(s.fld_store_name AS NVARCHAR(500)))) <> N''
                ORDER BY store_name ASC
                "
            );
        } catch (Throwable $e) {
            Log::warning('sales.storage_options_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->store_name ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function storageWhereClause(?string $storage): array
    {
        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue === '') {
            return ['', []];
        }

        return [
            ' AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N\'\') AS NVARCHAR(500)))) = ? ',
            [$storageValue],
        ];
    }

    /**
     * @param  list<string>  $normalizedCities
     * @param  list<string>  $normalizedSalesmanIds
     * @return array{0: string, 1: list<string>}
     */
    private function geoFilterClauses(array $normalizedCities, array $normalizedSalesmanIds, ?string $storage = null): array
    {
        [$citySql, $cityBindings] = $this->visits->sqlFilterAccountCityEquals('a', $normalizedCities);
        [$salesmanSql, $salesmanBindings] = $this->salesmanWhereClause($normalizedSalesmanIds);
        [$storageSql, $storageBindings] = $this->storageWhereClause($storage);

        return [$citySql.$salesmanSql.$storageSql, array_merge($cityBindings, $salesmanBindings, $storageBindings)];
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
     * Client accounts linked to a salesman (same Identifier rule as visits).
     *
     * @return list<array{id: string, name: string}>
     */
    public function getCustomerAccountOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        try {
            $rows = DB::select(
                '
                SELECT CAST(c.fld_account_id AS NVARCHAR(50)) AS id,
                       CAST(COALESCE(c.fld_account_code, N\'\') AS NVARCHAR(120)) AS code,
                       CAST(COALESCE(c.fld_account_name, N\'\') AS NVARCHAR(500)) AS name
                FROM '.self::ACCOUNTS.' AS c
                INNER JOIN '.self::ACCOUNTS.' AS s
                    ON s.fld_account_id = c.fld_sales_man_id_ref
                    AND s.fld_parent_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
                ORDER BY c.fld_account_name
                ',
                [IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID]
            );
        } catch (Throwable $e) {
            Log::warning('sales.customer_options_failed', ['message' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r->id ?? '');
            if ($id === '') {
                continue;
            }
            $code = trim((string) ($r->code ?? ''));
            $name = trim((string) ($r->name ?? ''));
            $label = $code !== '' ? $code.' — '.$name : ($name !== '' ? $name : $id);
            $out[] = ['id' => $id, 'name' => $label];
        }

        return $out;
    }

    /**
     * Read-only sales aggregates from store document lines.
     *
     * Amount: quantity × unit price after line discount (same basis as Invoices report).
     * Quantity: whole pieces (each line rounded to integer pcs before summing).
     * Weight: line total weight when stored on the document line, otherwise quantity × item weight from settings.
     *
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return LengthAwarePaginator<int, stdClass>|array{0: stdClass}
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        int $page,
        int $perPage,
        array $customerAccountIds = [],
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): LengthAwarePaginator|array {
        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('s');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
                CAST(a.fld_account_id AS NVARCHAR(50)) AS client_account_id,
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
     * Item-level sales for one client account in a date range.
     *
     * @return list<stdClass>
     */
    public function getClientItemBreakdown(
        string $dateFrom,
        string $dateTo,
        string $clientAccountId
    ): array {
        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Client item breakdown requires SQL Server (sqlsrv).');
        }

        $clientAccountId = trim($clientAccountId);
        if (! preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $clientAccountId)) {
            throw new RuntimeException('Invalid client account id.');
        }

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $limit = self::MAX_DRILLDOWN_ROWS;
        $bindings = [$dateFrom, $dateTo, $clientAccountId];

        $sql = "
            SELECT
                {$categoryExpr} AS item_category,
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              AND t.fld_account_id_ref = CAST(? AS UNIQUEIDENTIFIER)
            GROUP BY d.fld_item_id_ref, {$categoryExpr}, {$itemExpr}
            ORDER BY amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * Grand totals across all matching document lines (full filter set, not current page).
     *
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     */
    public function getMetricGrandTotals(
        string $dateFrom,
        string $dateTo,
        array $customerAccountIds = [],
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null,
        ?string $searchDescription = null
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales report requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('s');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            {$itemsJoin}
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
     * Most recent calendar date strictly before $beforeDate that has sales amount &gt; 0.
     *
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     */
    public function findLastDateWithSalesBefore(
        string $beforeDate,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null,
        int $maxDaysBack = 120
    ): ?string {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales report requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $maxDaysBack = max(1, min(365, $maxDaysBack));
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);

        $earliest = \Carbon\Carbon::parse($beforeDate)->subDays($maxDaysBack)->toDateString();
        $bindings = array_merge([$beforeDate, $earliest], $geoBindings);

        $sql = "
            SELECT TOP 1 sale_date
            FROM (
                SELECT
                    CAST(t.fld_store_document_title_date AS date) AS sale_date,
                    COALESCE(SUM({$lineAmountExpr}), 0) AS amount
                FROM dbo.tbl_store_document_detail AS d
                INNER JOIN dbo.tbl_store_document_titles AS t
                    ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
                LEFT JOIN dbo.tbl_accounting_accounts AS a
                    ON a.fld_account_id = t.fld_account_id_ref
                LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
                WHERE CAST(t.fld_store_document_title_date AS date) < CAST(? AS date)
                  AND CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
                  AND ISNULL(t.fld_is_cancelled, 0) = 0
                  AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
                  {$geoSql}
                GROUP BY CAST(t.fld_store_document_title_date AS date)
                HAVING COALESCE(SUM({$lineAmountExpr}), 0) > 0
            ) AS days_with_sales
            ORDER BY sale_date DESC
        ";

        $row = DB::selectOne($sql, $bindings);
        if ($row === null || empty($row->sale_date)) {
            return null;
        }

        $date = $row->sale_date;
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    /**
     * Weight totals grouped by item description (category), ordered by weight descending.
     *
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    public function getWeightTotalsByCategory(
        string $dateFrom,
        string $dateTo,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales report requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $geoBindings);

        $sql = "
            SELECT TOP (40)
                {$categoryExpr} AS category_name,
                COALESCE(SUM({$lineWeightExpr}), 0) AS weight_total
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$geoSql}
            GROUP BY {$categoryExpr}
            HAVING COALESCE(SUM({$lineWeightExpr}), 0) > 0
            ORDER BY weight_total DESC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * Sales amount grouped by item description (category).
     *
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    public function getAmountTotalsByCategory(
        string $dateFrom,
        string $dateTo,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales report requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $geoBindings);

        $sql = "
            SELECT TOP (40)
                {$categoryExpr} AS category_name,
                COALESCE(SUM({$lineAmountExpr}), 0) AS amount_total,
                COALESCE(SUM({$lineQtyExpr}), 0) AS units_sold
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$geoSql}
            GROUP BY {$categoryExpr}
            HAVING COALESCE(SUM({$lineAmountExpr}), 0) > 0
            ORDER BY amount_total DESC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * Sales amount grouped by salesman (for dashboard pie chart).
     *
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    public function getSalesAmountBySalesman(
        string $dateFrom,
        string $dateTo,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales report requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $geoBindings);

        $sql = "
            SELECT
                CAST(COALESCE(sm.fld_account_id, '00000000-0000-0000-0000-000000000000') AS NVARCHAR(50)) AS salesman_id,
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(sm.fld_account_name AS NVARCHAR(500)))), N''), N'(no salesman)') AS salesman_name,
                COALESCE(SUM({$lineAmountExpr}), 0) AS amount
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS sm
                ON sm.fld_account_id = a.fld_sales_man_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$geoSql}
            GROUP BY sm.fld_account_id, sm.fld_account_name
            HAVING COALESCE(SUM({$lineAmountExpr}), 0) > 0
            ORDER BY amount DESC
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * Sales by store item description (chicken category). SQL Server only.
     *
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdown(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $customerAccountIds = [],
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
     * Sales by category (item description) and item name. SQL Server only.
     *
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryItemBreakdown(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $customerAccountIds = [],
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category item breakdown requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
              {$searchSql}
        ";

        $groupByCategoryItem = 'd.fld_item_id_ref, '.$categoryExpr.', '.$itemExpr;

        $countSql = "
            SELECT COUNT(*) AS c FROM (
                SELECT 1 AS grp
                {$baseFrom}
                GROUP BY {$groupByCategoryItem}
            ) AS grp
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);

        $dataSql = "
            SELECT
                {$categoryExpr} AS chicken_category,
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY {$groupByCategoryItem}
            ORDER BY {$categoryExpr} ASC, amount DESC
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
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdownByClient(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $customerAccountIds = [],
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
                CAST(a.fld_account_id AS NVARCHAR(50)) AS client_account_id,
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
     * @param  list<string>  $customerAccountIds
     * @param  list<string>  $cities
     * @param  list<string>  $salesmanIds
     * @return list<stdClass>
     */
    public function exportReportRows(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        array $customerAccountIds,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('s');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
     * @param  list<string>  $customerAccountIds
     * @return list<stdClass>
     */
    public function exportChickenCategoryRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $customerAccountIds,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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
     * @param  list<string>  $customerAccountIds
     * @return list<stdClass>
     */
    public function exportChickenCategoryItemRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $customerAccountIds,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category item breakdown requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';
        $itemExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$nameCol.' AS NVARCHAR(500)))), N\'\'), N\'(unnamed item)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
              {$searchSql}
        ";

        $groupByCategoryItem = 'd.fld_item_id_ref, '.$categoryExpr.', '.$itemExpr;
        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                {$categoryExpr} AS chicken_category,
                {$itemExpr} AS item_name,
                SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total
            {$baseFrom}
            GROUP BY {$groupByCategoryItem}
            ORDER BY {$categoryExpr} ASC, amount DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($dataSql, $bindings);
    }

    /**
     * @param  list<string>  $customerAccountIds
     * @return list<stdClass>
     */
    public function exportChickenCategoryByClientRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $customerAccountIds,
        array $cities = [],
        array $salesmanIds = [],
        ?string $storage = null
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

        $salesScopeSql = $this->salesLineScopeSql();
        $sqlMetrics = $this->salesMetricSqlFragments('w');
        $invoiceJoin = $sqlMetrics['invoiceJoin'];
        $lineQtyExpr = $sqlMetrics['lineQty'];
        $lineAmountExpr = $sqlMetrics['lineAmount'];
        $lineWeightExpr = $sqlMetrics['lineWeight'];

        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $descCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'));

        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $categoryExpr = 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.'.$descCol.' AS NVARCHAR(500)))), N\'\'), N\'(uncategorized)\')';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $cityIds = $this->normalizeCities($cities);
        $salesmanIdList = $this->normalizeSalesmanIds($salesmanIds);
        [$geoSql, $geoBindings] = $this->geoFilterClauses($cityIds, $salesmanIdList, $storage);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings, $geoBindings);

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
            LEFT JOIN dbo.tbl_stores AS st ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$salesScopeSql}
              {$custSql}
              {$geoSql}
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

    private function salesLineScopeSql(): string
    {
        return $this->postedSalesMetrics()->postedSalesScopeSql(true);
    }

    /**
     * @return array{invoiceJoin: string, lineQty: string, lineAmount: string, lineNetAmount: string, lineWeight: string, weightSubquery: string}
     */
    private function salesMetricSqlFragments(string $weightAlias = 'w'): array
    {
        return $this->postedSalesMetrics()->metricFragments($weightAlias);
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
