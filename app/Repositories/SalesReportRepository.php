<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class SalesReportRepository
{
    private const MAX_EXPORT_ROWS = 10000;

    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

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
     * Amount: quantity × unit price (line extension before tax/discount complexity).
     * Weight: quantity × item weight from tbl_store_item_setting (one row per item via subquery).
     *
     * @param  list<string>  $customerAccountIds
     * @return LengthAwarePaginator<int, stdClass>|array{0: stdClass}
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        int $page,
        int $perPage,
        array $customerAccountIds = []
    ): LengthAwarePaginator|array {
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$custSql}
        ";

        if (! $groupByClient) {
            $sql = "
                SELECT
                    SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    ) AS amount,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(COALESCE(s.fld_weight, 0) AS float)
                    ) AS weight_total
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
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(s.fld_weight, 0) AS float)
                ) AS weight_total
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
     * Sales by store item description (chicken category). SQL Server only.
     *
     * @param  list<string>  $customerAccountIds
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdown(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $customerAccountIds = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

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
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

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
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$custSql}
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
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
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
     * @param  list<string>  $customerAccountIds
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getChickenCategoryBreakdownByClient(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        int $page,
        int $perPage,
        array $customerAccountIds = []
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

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
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

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
              {$custSql}
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
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
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
     * @return list<stdClass>
     */
    public function exportReportRows(
        string $dateFrom,
        string $dateTo,
        bool $groupByClient,
        array $customerAccountIds
    ): array {
        $weightSub = '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref
        ';

        $custIds = $this->normalizeCustomerAccountIds($customerAccountIds);
        [$custSql, $custBindings] = $this->customerAccountWhereClause($custIds);
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN ({$weightSub}) AS s
                ON s.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$custSql}
        ";

        $limit = self::MAX_EXPORT_ROWS;

        if (! $groupByClient) {
            $sql = "
                SELECT
                    SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    ) AS amount,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(COALESCE(s.fld_weight, 0) AS float)
                    ) AS weight_total
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
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(s.fld_weight, 0) AS float)
                ) AS weight_total
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
        array $customerAccountIds
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown requires SQL Server (sqlsrv).');
        }

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
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

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
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN ({$weightSub}) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$custSql}
              {$searchSql}
        ";

        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                {$categoryExpr} AS chicken_category,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
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
    public function exportChickenCategoryByClientRows(
        string $dateFrom,
        string $dateTo,
        ?string $searchDescription,
        array $customerAccountIds
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Category breakdown based on clients requires SQL Server (sqlsrv).');
        }

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
        $bindings = array_merge([$dateFrom, $dateTo], $custBindings);

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
              {$custSql}
              {$searchSql}
        ";

        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                {$categoryExpr} AS chicken_category,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
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
