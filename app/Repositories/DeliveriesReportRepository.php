<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class DeliveriesReportRepository
{
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
     * @return list<string>
     */
    public function getStorageOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $rows = DB::select(
            "
            SELECT DISTINCT LTRIM(RTRIM(CAST(s.fld_store_name AS NVARCHAR(500)))) AS store_name
            FROM dbo.tbl_stores AS s
            WHERE s.fld_store_name IS NOT NULL
              AND LTRIM(RTRIM(CAST(s.fld_store_name AS NVARCHAR(500)))) <> N''
            ORDER BY store_name ASC
            "
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
        ?string $storage,
        ?string $deliveryStatus,
        ?array $invoiceIds,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Deliveries report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);

        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, $storage, $deliveryStatus, $invoiceIds);

        $countSql = "
            SELECT COUNT(*) AS c
            FROM (
                SELECT
                    CAST(t.fld_store_document_title_id AS NVARCHAR(100)) AS invoice_id,
                    CAST(t.fld_store_document_title_date AS date) AS document_date,
                    COALESCE(a.fld_account_code, N'') AS client_code,
                    COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                    {$this->cityExprSql()} AS city_name,
                    LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))) AS storage_name,
                    CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END AS delivery_status
                {$baseFrom}
                GROUP BY
                    CAST(t.fld_store_document_title_id AS NVARCHAR(100)),
                    CAST(t.fld_store_document_title_date AS date),
                    COALESCE(a.fld_account_code, N''),
                    COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)'),
                    {$this->cityExprSql()},
                    LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))),
                    CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END
            ) AS grouped_rows
        ";
        $total = (int) (DB::selectOne($countSql, $bindings)->c ?? 0);

        $offset = max(0, ($page - 1) * $perPage);
        $invoiceNumberExpr = $this->invoiceNumberExpr();
        $dataSql = "
            SELECT
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)) AS invoice_id,
                MAX({$invoiceNumberExpr}) AS invoice_no,
                CAST(t.fld_store_document_title_date AS date) AS document_date,
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                {$this->cityExprSql()} AS city_name,
                LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))) AS storage_name,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total,
                CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END AS delivery_status
            {$baseFrom}
            GROUP BY
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)),
                CAST(t.fld_store_document_title_date AS date),
                COALESCE(a.fld_account_code, N''),
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)'),
                {$this->cityExprSql()},
                LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))),
                CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END
            ORDER BY document_date DESC
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
    public function exportRows(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $storage,
        ?string $deliveryStatus,
        ?array $invoiceIds
    ): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Deliveries report requires SQL Server (sqlsrv).');
        }

        [$baseFrom, $bindings] = $this->baseFromAndBindings($dateFrom, $dateTo, $cities, $storage, $deliveryStatus, $invoiceIds);
        $limit = self::MAX_EXPORT_ROWS;
        $invoiceNumberExpr = $this->invoiceNumberExpr();
        $sql = "
            SELECT
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)) AS invoice_id,
                MAX({$invoiceNumberExpr}) AS invoice_no,
                CAST(t.fld_store_document_title_date AS date) AS document_date,
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                {$this->cityExprSql()} AS city_name,
                LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))) AS storage_name,
                SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total,
                CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END AS delivery_status
            {$baseFrom}
            GROUP BY
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)),
                CAST(t.fld_store_document_title_date AS date),
                COALESCE(a.fld_account_code, N''),
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)'),
                {$this->cityExprSql()},
                LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N'') AS NVARCHAR(500)))),
                CASE WHEN ISNULL(d.fld_is_delivered, 0) = 1 THEN N'Delivered' ELSE N'Not delivered' END
            ORDER BY document_date DESC
            OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $invoiceNumbers
     * @return list<stdClass>
     */
    public function findInvoicesByInvoiceNumbers(string $dateFrom, string $dateTo, array $invoiceNumbers): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Deliveries report requires SQL Server (sqlsrv).');
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $invoiceNumbers
        ))));
        if ($normalized === []) {
            return [];
        }

        $invoiceNumberExpr = $this->invoiceNumberExpr();
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $bindings = array_merge([$dateFrom, $dateTo], $normalized);

        $sql = "
            SELECT
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)) AS invoice_id,
                MAX({$invoiceNumberExpr}) AS invoice_no,
                CAST(t.fld_store_document_title_date AS date) AS document_date
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              AND {$invoiceNumberExpr} IN ({$placeholders})
            GROUP BY
                CAST(t.fld_store_document_title_id AS NVARCHAR(100)),
                CAST(t.fld_store_document_title_date AS date)
        ";

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<string>  $cities
     * @return array{0: string, 1: list<string>}
     */
    private function baseFromAndBindings(
        string $dateFrom,
        string $dateTo,
        array $cities,
        ?string $storage,
        ?string $deliveryStatus,
        ?array $invoiceIds
    ): array
    {
        $cityIds = $this->normalizeCities($cities);
        [$citySql, $cityBindings] = $this->visits->sqlFilterAccountCityEquals('a', $cityIds);

        $bindings = array_merge([$dateFrom, $dateTo], $cityBindings);
        $storageSql = '';
        $storageValue = trim((string) ($storage ?? ''));
        if ($storageValue !== '') {
            $storageSql = ' AND LTRIM(RTRIM(CAST(COALESCE(s.fld_store_name, N\'\') AS NVARCHAR(500)))) = ? ';
            $bindings[] = $storageValue;
        }
        $statusSql = '';
        if ($deliveryStatus === 'delivered') {
            $statusSql = ' AND ISNULL(d.fld_is_delivered, 0) = 1 ';
        } elseif ($deliveryStatus === 'not_delivered') {
            $statusSql = ' AND ISNULL(d.fld_is_delivered, 0) <> 1 ';
        }
        $invoiceSql = '';
        if (is_array($invoiceIds) && $invoiceIds !== []) {
            $normalized = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $invoiceIds
            ))));
            if ($normalized === []) {
                $invoiceSql = ' AND 1 = 0 ';
            } else {
                $placeholders = implode(',', array_fill(0, count($normalized), '?'));
                $invoiceSql = " AND CAST(t.fld_store_document_title_id AS NVARCHAR(100)) IN ({$placeholders}) ";
                $bindings = array_merge($bindings, $normalized);
            }
        }

        $baseFrom = "
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
            LEFT JOIN dbo.tbl_stores AS s
                ON s.fld_store_id = t.fld_store_id_ref
            LEFT JOIN (
                SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
                FROM dbo.tbl_store_item_setting
                GROUP BY fld_item_id_ref
            ) AS w
                ON w.fld_item_id_ref = d.fld_item_id_ref
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$citySql}
              {$storageSql}
              {$statusSql}
              {$invoiceSql}
        ";

        return [$baseFrom, $bindings];
    }

    private function cityExprSql(): string
    {
        $cityCol = $this->visits->getAccountCityColumnName();
        if (! is_string($cityCol) || trim($cityCol) === '') {
            return "N''";
        }

        return 'LTRIM(RTRIM(CAST(COALESCE(a.'.$this->bracketSqlIdentifier($cityCol).", N'') AS NVARCHAR(500))))";
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
            return "CAST(t.fld_store_document_title_id AS NVARCHAR(100))";
        }

        return "COALESCE(NULLIF(LTRIM(RTRIM(CAST(t.".$this->bracketSqlIdentifier($col)." AS NVARCHAR(100)))), N''), CAST(t.fld_store_document_title_id AS NVARCHAR(100)))";
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveColumnName(string $schema, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            $row = DB::selectOne(
                "SELECT TOP 1 COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$schema, $table, $column]
            );
            if ($row !== null) {
                return $column;
            }
        }

        return null;
    }

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }
}

