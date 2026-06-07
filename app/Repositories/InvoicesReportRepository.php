<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class InvoicesReportRepository
{
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
     * @return list<string>
     */
    public function getStoreOptions(): array
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
     * @param  list<string>  $cities
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $store,
        ?string $salesmanId,
        ?string $searchText,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, $store, $salesmanId, $searchText);

        $countSql = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT t.fld_store_document_title_id
                {$baseFrom}
                GROUP BY t.fld_store_document_title_id
            ) AS invoices
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $dataSql = "
            SELECT
                t.fld_store_document_title_id AS invoice_id,
                MAX({$this->invoiceNumberExpr()}) AS invoice_no,
                MAX(CAST(t.fld_store_document_title_date AS date)) AS invoice_date,
                MAX(CAST(t.fld_last_print_date AS datetime)) AS last_print_date,
                MAX({$this->creationTimeExpr()}) AS created_at,
                MAX(COALESCE(a.fld_account_code, N'')) AS client_code,
                MAX(COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)')) AS client_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(ad.fld_account_mobile, N'') AS NVARCHAR(200))))) AS client_phone,
                MAX(LTRIM(RTRIM(CAST(COALESCE(ad.fld_account_address, N'') AS NVARCHAR(500))))) AS client_address,
                MAX({$this->cityExprSql()}) AS city_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500))))) AS store_name,
                MAX(COALESCE(sm.fld_account_name, N'')) AS salesman_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(smd.fld_account_mobile, N'') AS NVARCHAR(200))))) AS salesman_phone,
                MAX(LTRIM(RTRIM(CAST(COALESCE(t.fld_store_document_title_desc, N'') AS NVARCHAR(2000))))) AS invoice_desc,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                ) AS invoice_amount,
                MAX({$this->clientDueExpr()}) AS client_due_amount
            {$baseFrom}
            GROUP BY
                t.fld_store_document_title_id
            ORDER BY invoice_date DESC, t.fld_store_document_title_id DESC
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
    public function getReportRowsForExport(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $store,
        ?string $salesmanId,
        ?string $searchText
    ): array {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, $store, $salesmanId, $searchText);
        $limit = self::MAX_EXPORT_ROWS;

        $dataSql = "
            SELECT
                t.fld_store_document_title_id AS invoice_id,
                MAX({$this->invoiceNumberExpr()}) AS invoice_no,
                MAX(CAST(t.fld_store_document_title_date AS date)) AS invoice_date,
                MAX(CAST(t.fld_last_print_date AS datetime)) AS last_print_date,
                MAX({$this->creationTimeExpr()}) AS created_at,
                MAX(COALESCE(a.fld_account_code, N'')) AS client_code,
                MAX(COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)')) AS client_name,
                MAX({$this->cityExprSql()}) AS city_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500))))) AS store_name,
                MAX(COALESCE(sm.fld_account_name, N'')) AS salesman_name,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                ) AS invoice_amount,
                MAX({$this->clientDueExpr()}) AS client_due_amount
            {$baseFrom}
            GROUP BY
                t.fld_store_document_title_id
            ORDER BY invoice_date DESC, t.fld_store_document_title_id DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($dataSql, $bindings);
    }

    /**
     * Invoice count and line totals for a date range (dashboard / summary use).
     *
     * @param  list<string>  $cities
     * @return stdClass{invoice_count: int, quantity_total: float, invoice_amount: float}
     */
    public function getInvoiceSummary(string $dateFrom, string $dateTo, array $cities, ?string $salesmanId = null): stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, null, $salesmanId, null);

        $sql = "
            SELECT
                COUNT(*) AS invoice_count,
                COALESCE(SUM(inv.quantity_total), 0) AS quantity_total,
                COALESCE(SUM(inv.invoice_amount), 0) AS invoice_amount
            FROM (
                SELECT
                    t.fld_store_document_title_id AS invoice_id,
                    SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                        * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                    ) AS invoice_amount
                {$baseFrom}
                GROUP BY t.fld_store_document_title_id
            ) AS inv
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? (object) [
            'invoice_count' => 0,
            'quantity_total' => 0,
            'invoice_amount' => 0,
        ];
    }

    /**
     * Grand totals across all invoices matching filters (not current page).
     *
     * @param  list<string>  $cities
     * @return stdClass{quantity_total: float, invoice_amount: float, client_due_amount: float}
     */
    public function getReportTotals(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $store,
        ?string $salesmanId,
        ?string $searchText
    ): stdClass {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, $store, $salesmanId, $searchText);

        $sql = "
            SELECT
                COALESCE(SUM(inv.quantity_total), 0) AS quantity_total,
                COALESCE(SUM(inv.invoice_amount), 0) AS invoice_amount,
                COALESCE(SUM(inv.client_due_amount), 0) AS client_due_amount
            FROM (
                SELECT
                    t.fld_store_document_title_id AS invoice_id,
                    SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                    SUM(
                        CAST(d.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                        * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                    ) AS invoice_amount,
                    MAX({$this->clientDueExpr()}) AS client_due_amount
                {$baseFrom}
                GROUP BY t.fld_store_document_title_id
            ) AS inv
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? (object) [
            'quantity_total' => 0,
            'invoice_amount' => 0,
            'client_due_amount' => 0,
        ];
    }

    /**
     * @return list<stdClass>
     */
    public function getInvoiceItems(string $invoiceId): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }
        $itemsTable = (string) config('reporting.store_items_table', 'dbo.tbl_store_items');
        $pkCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_pk_column', 'fld_item_id'));
        $nameCol = $this->bracketSqlIdentifier((string) config('reporting.store_items_name_column', 'fld_item_name'));
        $itemCodeExpr = $this->itemCodeExpr('i');

        $sql = "
            SELECT
                {$itemCodeExpr} AS item_code,
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'))} AS NVARCHAR(500)))), N''), N'(uncategorized)') AS category_name,
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)') AS item_name,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity,
                MAX(CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6))) AS discount_percent,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS gross_amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                ) AS amount
                ,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total
            FROM dbo.tbl_store_document_detail AS d
            LEFT JOIN {$itemsTable} AS i
                ON i.{$pkCol} = d.fld_item_id_ref
            LEFT JOIN (
                SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
                FROM dbo.tbl_store_item_setting
                GROUP BY fld_item_id_ref
            ) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE d.fld_store_document_title_id_ref = ?
              AND ISNULL(d.fld_is_cancelled, 0) = 0
            GROUP BY
                {$itemCodeExpr},
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$this->bracketSqlIdentifier((string) config('reporting.store_items_description_column', 'fld_description'))} AS NVARCHAR(500)))), N''), N'(uncategorized)'),
                COALESCE(NULLIF(LTRIM(RTRIM(CAST(i.{$nameCol} AS NVARCHAR(500)))), N''), N'(unnamed item)'),
                CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6))
            ORDER BY amount DESC
        ";

        return DB::select($sql, [$invoiceId]);
    }

    public function getInvoiceHeader(string $invoiceId): ?stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        $sql = "
            SELECT
                t.fld_store_document_title_id AS invoice_id,
                MAX({$this->invoiceNumberExpr()}) AS invoice_no,
                MAX(CAST(t.fld_store_document_title_date AS date)) AS invoice_date,
                MAX(CAST(t.fld_last_print_date AS datetime)) AS last_print_date,
                MAX({$this->creationTimeExpr()}) AS created_at,
                MAX(COALESCE(a.fld_account_code, N'')) AS client_code,
                MAX(COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)')) AS client_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(ad.fld_account_mobile, N'') AS NVARCHAR(200))))) AS client_phone,
                MAX(LTRIM(RTRIM(CAST(COALESCE(ad.fld_account_address, N'') AS NVARCHAR(500))))) AS client_address,
                MAX({$this->cityExprSql()}) AS city_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500))))) AS store_name,
                MAX(COALESCE(sm.fld_account_name, N'')) AS salesman_name,
                MAX(LTRIM(RTRIM(CAST(COALESCE(smd.fld_account_mobile, N'') AS NVARCHAR(200))))) AS salesman_phone,
                MAX(LTRIM(RTRIM(CAST(COALESCE(t.fld_store_document_title_desc, N'') AS NVARCHAR(2000))))) AS invoice_desc,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                    * (1 - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / 100.0))
                ) AS invoice_amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total,
                MAX({$this->clientDueExpr()}) AS client_due_amount
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS sm
                ON sm.fld_account_id = a.fld_sales_man_id_ref
            LEFT JOIN dbo.tbl_accounting_account_details AS ad
                ON ad.fld_account_id_ref = a.fld_account_id
            LEFT JOIN dbo.tbl_accounting_account_details AS smd
                ON smd.fld_account_id_ref = sm.fld_account_id
            LEFT JOIN dbo.tbl_stores AS st
                ON st.fld_store_id = t.fld_store_id_ref
            LEFT JOIN (
                SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
                FROM dbo.tbl_store_item_setting
                GROUP BY fld_item_id_ref
            ) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE t.fld_store_document_title_id = ?
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
            GROUP BY t.fld_store_document_title_id
        ";

        return DB::selectOne($sql, [$invoiceId]);
    }

    public function touchLastPrintDate(string $invoiceId, bool $markNotDelivered = false): int
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Invoices report requires SQL Server (sqlsrv).');
        }

        return DB::transaction(function () use ($invoiceId, $markNotDelivered): int {
            $deliveryRowsUpdated = 0;

            if ($markNotDelivered) {
                $deliveryRowsUpdated = DB::update(
                    'UPDATE d
                     SET d.fld_is_delivered = 0
                     FROM dbo.tbl_store_document_detail AS d
                     INNER JOIN dbo.tbl_store_document_titles AS t
                        ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
                     WHERE t.fld_store_document_title_id = ?
                       AND ISNULL(t.fld_is_cancelled, 0) = 0
                       AND ISNULL(d.fld_is_cancelled, 0) = 0',
                    [$invoiceId]
                );
                DB::update(
                    'UPDATE dbo.tbl_store_document_titles
                     SET fld_is_delivered = 0
                     WHERE fld_store_document_title_id = ?
                       AND ISNULL(fld_is_cancelled, 0) = 0',
                    [$invoiceId]
                );
            }

            DB::update(
                'UPDATE dbo.tbl_store_document_titles
                 SET fld_last_print_date = GETDATE()
                 WHERE fld_store_document_title_id = ?',
                [$invoiceId]
            );

            return $deliveryRowsUpdated;
        });
    }

    /**
     * @param  list<string>  $cities
     * @return array{0:string,1:list<string>}
     */
    private function baseFromAndBindings(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $store,
        ?string $salesmanId,
        ?string $searchText
    ): array {
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->visits->sqlFilterAccountCityEquals('a', $cityIds);
        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);

        $storeSql = '';
        $storeValue = trim((string) ($store ?? ''));
        if ($storeValue !== '') {
            $storeSql = " AND LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) = ? ";
            $bindings[] = $storeValue;
        }

        $salesmanSql = '';
        $salesmanValue = trim((string) ($salesmanId ?? ''));
        if ($salesmanValue !== '') {
            $salesmanSql = ' AND a.fld_sales_man_id_ref = ? ';
            $bindings[] = $salesmanValue;
        }

        $searchSql = '';
        $q = trim((string) ($searchText ?? ''));
        if ($q !== '') {
            $searchSql = " AND (
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)) LIKE ? ESCAPE N'\\'
                OR LTRIM(RTRIM(CAST(COALESCE(a.fld_account_code, N'') AS NVARCHAR(500)))) LIKE ? ESCAPE N'\\'
                OR LTRIM(RTRIM(CAST(COALESCE(a.fld_account_name, t.fld_person_name, N'') AS NVARCHAR(500)))) LIKE ? ESCAPE N'\\'
                OR LTRIM(RTRIM(CAST(COALESCE(st.fld_store_name, N'') AS NVARCHAR(500)))) LIKE ? ESCAPE N'\\'
                OR LTRIM(RTRIM(CAST(COALESCE(sm.fld_account_name, N'') AS NVARCHAR(500)))) LIKE ? ESCAPE N'\\'
            ) ";
            $pattern = '%'.$this->escapeLikePattern($q).'%';
            $bindings = array_merge($bindings, [$pattern, $pattern, $pattern, $pattern, $pattern]);
        }

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS sm
                ON sm.fld_account_id = a.fld_sales_man_id_ref
            LEFT JOIN dbo.tbl_accounting_account_details AS ad
                ON ad.fld_account_id_ref = a.fld_account_id
            LEFT JOIN dbo.tbl_accounting_account_details AS smd
                ON smd.fld_account_id_ref = sm.fld_account_id
            LEFT JOIN dbo.tbl_stores AS st
                ON st.fld_store_id = t.fld_store_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$citySql}
              {$storeSql}
              {$salesmanSql}
              {$searchSql}
        ";

        return [$baseFrom, $bindings];
    }

    private function invoiceNumberExpr(): string
    {
        $col = $this->resolveColumnName('dbo', 'tbl_store_document_titles', [
            'fld_store_document_title_no',
            'fld_store_document_title_number',
            'fld_document_number',
            'fld_document_no',
        ]);
        if ($col === null) {
            return 'CAST(t.fld_store_document_title_id AS NVARCHAR(100))';
        }

        return 'COALESCE(NULLIF(LTRIM(RTRIM(CAST(t.'.$this->bracketSqlIdentifier($col)." AS NVARCHAR(100)))), N''), CAST(t.fld_store_document_title_id AS NVARCHAR(100)))";
    }

    private function creationTimeExpr(): string
    {
        $col = $this->resolveColumnName('dbo', 'tbl_store_document_titles', [
            'fld_pda_sync_date',
            'fld_pocket_pc_sync_date',
            'fld_sync_date',
            'fld_sync_datetime',
            'fld_document_sync_date',
            'fld_created_at',
            'fld_create_date',
            'fld_creation_date',
            'fld_store_document_title_created_at',
        ]);
        if ($col === null) {
            return 't.fld_store_document_title_date';
        }

        return 't.'.$this->bracketSqlIdentifier($col);
    }

    private function clientDueExpr(): string
    {
        return 'COALESCE(
            CAST(dbo.FN_Get_Prev_Account_Balance(
                a.fld_account_id,
                t.fld_store_document_title_date,
                t.fld_year_id_ref
            ) AS decimal(24, 6)),
            CAST(0 AS decimal(24, 6))
        )';
    }

    private function cityExprSql(): string
    {
        $cityCol = $this->visits->getAccountCityColumnName();
        if (! is_string($cityCol) || trim($cityCol) === '') {
            return "N''";
        }

        return 'LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketSqlIdentifier($cityCol).", N'') AS NVARCHAR(500))))";
    }

    private function accountPhoneExpr(string $alias): string
    {
        $col = $this->resolveColumnName('dbo', 'tbl_accounting_accounts', [
            'fld_mobile',
            'fld_phone',
            'fld_phone_no',
            'fld_tel',
        ]);
        if ($col === null) {
            return "N''";
        }

        return "LTRIM(RTRIM(CAST(COALESCE({$alias}.".$this->bracketSqlIdentifier($col).", N'') AS NVARCHAR(200))))";
    }

    private function accountAddressExpr(string $alias): string
    {
        $col = $this->resolveColumnName('dbo', 'tbl_accounting_accounts', [
            'fld_address',
            'fld_account_address',
            'fld_location',
        ]);
        if ($col === null) {
            return "N''";
        }

        return "LTRIM(RTRIM(CAST(COALESCE({$alias}.".$this->bracketSqlIdentifier($col).", N'') AS NVARCHAR(500))))";
    }

    private function itemCodeExpr(string $alias): string
    {
        $col = $this->resolveColumnName('dbo', 'tbl_store_items', [
            'fld_item_code',
            'fld_barcode',
            'fld_item_barcode',
        ]);
        if ($col === null) {
            return "N''";
        }

        return "LTRIM(RTRIM(CAST(COALESCE({$alias}.".$this->bracketSqlIdentifier($col).", N'') AS NVARCHAR(200))))";
    }

    private function resolveColumnName(string $schema, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            $row = DB::selectOne(
                'SELECT TOP 1 COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$schema, $table, $column]
            );
            if ($row !== null) {
                return (string) $column;
            }
        }

        return null;
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
}
