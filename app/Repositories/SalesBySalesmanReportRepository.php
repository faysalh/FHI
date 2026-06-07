<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class SalesBySalesmanReportRepository
{
    use UsesPostedSalesDocumentMetrics;

    private const MAX_EXPORT_ROWS = 10000;

    private const ACCOUNTS = 'dbo.tbl_accounting_accounts';

    private const ACCOUNT_DETAILS = 'dbo.tbl_accounting_account_details';

    private bool $priceGroupColumnResolved = false;

    private ?string $priceGroupAccountColumn = null;

    private ?string $priceGroupDetailsColumn = null;

    public function normalizeSalesmanId(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        if (! preg_match('/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/', $id)) {
            return null;
        }

        return $id;
    }

    /**
     * Physical column name on dbo.tbl_accounting_accounts, if detected.
     */
    public function getResolvedClientPriceGroupColumn(): ?string
    {
        $this->resolvePriceGroupSources();

        $parts = [];
        if ($this->priceGroupAccountColumn !== null) {
            $parts[] = self::ACCOUNTS.'.'.$this->priceGroupAccountColumn;
        }
        if ($this->priceGroupDetailsColumn !== null) {
            $parts[] = self::ACCOUNT_DETAILS.'.'.$this->priceGroupDetailsColumn.' (MAX per account)';
        }

        return $parts === [] ? null : implode(' + ', $parts);
    }

    /**
     * Numeric tier 0–4 aligned with item sale prices 1–5 (same mapping as storage / sales-by-salesman).
     * Returns 0 when the tier cannot be read (falls back to sale price 1).
     */
    public function getClientPriceTierZeroToFourForAccount(string $accountId): int
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return 0;
        }

        $accountId = trim($accountId);
        if ($accountId === '') {
            return 0;
        }

        $this->resolvePriceGroupSources();
        $tierNum = $this->tierNumericSourceSql();
        if ($tierNum === null) {
            return 0;
        }

        $join = $this->priceTierJoinSql();
        $sql = '
            SELECT CAST(ROUND(CAST(('.$tierNum.') AS FLOAT), 0) AS int) AS tier
            FROM '.self::ACCOUNTS.' AS a
            '.$join.'
            WHERE CAST(a.fld_account_id AS NVARCHAR(100)) = ?
        ';

        $row = DB::selectOne($sql, [$accountId]);
        if ($row === null || $row->tier === null) {
            return 0;
        }

        $t = (int) $row->tier;
        if ($t < 0) {
            $t = 0;
        }
        if ($t > 4) {
            $t = 4;
        }

        return $t;
    }

    /**
     * @return LengthAwarePaginator<int, stdClass>
     */
    public function getReport(
        string $dateFrom,
        string $dateTo,
        string $salesmanId,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by salesman report requires SQL Server (sqlsrv).');
        }

        $perPage = max(1, min(250, $perPage));
        $page = max(1, $page);

        $priceGroupExpr = $this->clientPriceGroupSelectExpression();
        $tierJoin = $this->priceTierJoinSql();
        $bindings = [$dateFrom, $dateTo, $salesmanId];
        extract($this->postedSalesQueryContext('w'));
        $baseFrom = $this->clientDetailBaseFrom($tierJoin, $invoiceJoin, $postedSalesScopeSql);

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
                MAX({$priceGroupExpr}) AS client_price_group,
                COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count,
                SUM({$lineQtyExpr}) AS quantity_sold,
                SUM({$lineAmountExpr}) AS amount
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
     * Grand totals across all clients matching the filters (full period, not current page).
     *
     * @return stdClass{sum_invoice_count: float|int, sum_quantity_sold: float, sum_amount: float}
     */
    public function getGrandTotals(string $dateFrom, string $dateTo, string $salesmanId): stdClass
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by salesman report requires SQL Server (sqlsrv).');
        }

        $tierJoin = $this->priceTierJoinSql();
        $bindings = [$dateFrom, $dateTo, $salesmanId];
        extract($this->postedSalesQueryContext('w'));
        $baseFrom = $this->clientDetailBaseFrom($tierJoin, $invoiceJoin, $postedSalesScopeSql);

        $sql = "
            SELECT
                COALESCE(SUM(grp.invoice_count), 0) AS sum_invoice_count,
                COALESCE(SUM(grp.quantity_sold), 0) AS sum_quantity_sold,
                COALESCE(SUM(grp.amount), 0) AS sum_amount
            FROM (
                SELECT
                    COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count,
                    SUM({$lineQtyExpr}) AS quantity_sold,
                    SUM({$lineAmountExpr}) AS amount
                {$baseFrom}
                GROUP BY
                    a.fld_account_id,
                    a.fld_account_code,
                    a.fld_account_name,
                    t.fld_person_name
            ) AS grp
        ";

        $row = DB::selectOne($sql, $bindings);

        return $row ?? (object) [
            'sum_invoice_count' => 0,
            'sum_quantity_sold' => 0,
            'sum_amount' => 0,
        ];
    }

    /**
     * @return list<stdClass>
     */
    public function exportRows(string $dateFrom, string $dateTo, string $salesmanId): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Sales by salesman report requires SQL Server (sqlsrv).');
        }

        $priceGroupExpr = $this->clientPriceGroupSelectExpression();
        $tierJoin = $this->priceTierJoinSql();
        $bindings = [$dateFrom, $dateTo, $salesmanId];
        $limit = self::MAX_EXPORT_ROWS;
        extract($this->postedSalesQueryContext('w'));
        $baseFrom = $this->clientDetailBaseFrom($tierJoin, $invoiceJoin, $postedSalesScopeSql);

        $sql = "
            SELECT TOP ({$limit})
                COALESCE(a.fld_account_code, N'') AS client_code,
                COALESCE(a.fld_account_name, t.fld_person_name, N'(no account)') AS client_name,
                MAX({$priceGroupExpr}) AS client_price_group,
                COUNT(DISTINCT t.fld_store_document_title_id) AS invoice_count,
                SUM({$lineQtyExpr}) AS quantity_sold,
                SUM({$lineAmountExpr}) AS amount
            {$baseFrom}
            GROUP BY
                a.fld_account_id,
                a.fld_account_code,
                a.fld_account_name,
                t.fld_person_name
            ORDER BY amount DESC
        ";

        return DB::select($sql, $bindings);
    }

    private function clientDetailBaseFrom(string $tierJoin, string $invoiceJoin, string $postedSalesScopeSql): string
    {
        return '
            FROM dbo.tbl_store_document_detail AS d
            INNER JOIN dbo.tbl_store_document_titles AS t
                ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            '.$invoiceJoin.'
            LEFT JOIN '.self::ACCOUNTS." AS a
                ON a.fld_account_id = t.fld_account_id_ref
            {$tierJoin}
            WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
              AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
              AND ISNULL(t.fld_is_cancelled, 0) = 0
              AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
        ";
    }

    private function clientPriceGroupSelectExpression(): string
    {
        $tierNum = $this->tierNumericSourceSql();
        $empty = 'N'.chr(39).chr(39);

        if ($tierNum === null) {
            return sprintf('CAST(%s%s%s AS NVARCHAR(120))', chr(78), chr(39), chr(39));
        }

        // Account fld_price_group (etc.) uses 0–4; labels align with item sale-price columns 1–5 (offset +1 in storage items).
        $ti = 'CAST(ROUND(CAST(('.$tierNum.') AS FLOAT), 0) AS int)';

        return <<<SQL
(CASE
            WHEN ({$tierNum}) IS NULL THEN {$empty}
            WHEN {$ti} = 0 THEN N'وكيل'
            WHEN {$ti} = 1 THEN N'وكيل 2'
            WHEN {$ti} = 2 THEN N'ماركيت'
            WHEN {$ti} = 3 THEN N'جملة'
            WHEN {$ti} = 4 THEN N'كي'
            ELSE LTRIM(RTRIM(CAST({$ti} AS NVARCHAR(50))))
        END)
SQL;
    }

    /**
     * LEFT JOIN aggregated tier from account details (when that table has the column).
     */
    private function priceTierJoinSql(): string
    {
        $this->resolvePriceGroupSources();
        if ($this->priceGroupDetailsColumn === null) {
            return '';
        }

        $c = $this->bracketSqlIdentifier($this->priceGroupDetailsColumn);
        $empty = 'N'.chr(39).chr(39);

        return "
            LEFT JOIN (
                SELECT fld_account_id_ref,
                       MAX(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST({$c} AS NVARCHAR(100)))), {$empty}) AS DECIMAL(24, 6))) AS rp_tier_raw
                FROM ".self::ACCOUNT_DETAILS.'
                GROUP BY fld_account_id_ref
            ) AS rp_tier
                ON rp_tier.fld_account_id_ref = a.fld_account_id
        ';
    }

    /**
     * Single numeric expression: account column, details aggregate, or COALESCE of both.
     */
    private function tierNumericSourceSql(): ?string
    {
        $this->resolvePriceGroupSources();
        $empty = 'N'.chr(39).chr(39);
        $parts = [];

        if ($this->priceGroupAccountColumn !== null) {
            $ac = 'a.'.$this->bracketSqlIdentifier($this->priceGroupAccountColumn);
            $parts[] = "TRY_CAST(NULLIF(LTRIM(RTRIM(CAST({$ac} AS NVARCHAR(100)))), {$empty}) AS DECIMAL(24, 6))";
        }

        if ($this->priceGroupDetailsColumn !== null) {
            $parts[] = 'rp_tier.rp_tier_raw';
        }

        if ($parts === []) {
            return null;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        return 'COALESCE('.$parts[0].', '.$parts[1].')';
    }

    private function resolvePriceGroupSources(): void
    {
        if ($this->priceGroupColumnResolved) {
            return;
        }
        $this->priceGroupColumnResolved = true;
        $this->priceGroupAccountColumn = null;
        $this->priceGroupDetailsColumn = null;

        if (DB::getDriverName() !== 'sqlsrv') {
            return;
        }

        $configured = trim((string) config('reporting.account_client_price_group_column', ''));
        if ($configured !== '') {
            if ($this->columnExists('dbo', 'tbl_accounting_accounts', $configured)) {
                $this->priceGroupAccountColumn = $configured;
            }
            if ($this->columnExists('dbo', 'tbl_accounting_account_details', $configured)) {
                $this->priceGroupDetailsColumn = $configured;
            }

            return;
        }

        $candidates = config('reporting.account_client_price_group_column_candidates', []);
        if (! is_array($candidates)) {
            return;
        }

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);
            if ($name === '') {
                continue;
            }
            $onAccounts = $this->columnExists('dbo', 'tbl_accounting_accounts', $name);
            $onDetails = $this->columnExists('dbo', 'tbl_accounting_account_details', $name);
            if (! $onAccounts && ! $onDetails) {
                continue;
            }
            if ($onAccounts) {
                $this->priceGroupAccountColumn = $name;
            }
            if ($onDetails) {
                $this->priceGroupDetailsColumn = $name;
            }

            break;
        }
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

    private function bracketSqlIdentifier(string $name): string
    {
        return '['.str_replace(']', ']]', $name).']';
    }
}
