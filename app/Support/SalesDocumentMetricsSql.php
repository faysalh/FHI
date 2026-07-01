<?php

declare(strict_types=1);

namespace App\Support;

use App\Repositories\IdentifierRepository;

/**
 * Canonical SQL fragments for posted sales ({@code fld_type_alias = S}) on store documents.
 *
 * Amount = line gross minus proportional invoice header + extra discount.
 * Quantity and weight use line quantity × item weight from settings.
 */
final class SalesDocumentMetricsSql
{
    public const ACCOUNTS_TABLE = 'dbo.tbl_accounting_accounts';

    /**
     * Scope for posted sales invoice lines.
     *
     * @param  bool  $requireClientUnderSalesman  When true, only clients linked to the Identifier salesman tree (Sales report).
     */
    public function postedSalesScopeSql(
        bool $requireClientUnderSalesman = true,
        string $titleAlias = 't',
        string $detailAlias = 'd'
    ): string {
        $sql = "
              AND COALESCE({$titleAlias}.fld_type_alias, N'') = N'S'
              AND CAST({$detailAlias}.fld_store_document_quantity AS decimal(24, 6)) > CAST(0 AS decimal(24, 6))";

        if (! $requireClientUnderSalesman) {
            return $sql;
        }

        $guid = IdentifierRepository::SALESMAN_PARENT_ACCOUNT_GUID;

        return $sql."
              AND EXISTS (
                  SELECT 1
                  FROM ".self::ACCOUNTS_TABLE.' AS sales_client
                  INNER JOIN '.self::ACCOUNTS_TABLE.' AS sales_sm
                      ON sales_sm.fld_account_id = sales_client.fld_sales_man_id_ref
                      AND sales_sm.fld_parent_account_id_ref = CAST(\''.$guid.'\' AS UNIQUEIDENTIFIER)
                  WHERE sales_client.fld_account_id = '.$titleAlias.'.fld_account_id_ref
              )';
    }

    /**
     * Per-invoice header discounts for proportional allocation to lines.
     */
    public function invoiceDiscountJoinSql(string $titleAlias = 't', string $joinAlias = 'inv'): string
    {
        return '
            LEFT JOIN (
                SELECT
                    d2.fld_store_document_title_id_ref AS inv_title_id,
                    SUM(
                        CAST(d2.fld_store_document_quantity AS decimal(24, 6))
                        * CAST(d2.fld_store_document_unit_price AS decimal(24, 6))
                    ) AS inv_gross,
                    MAX(CAST(COALESCE(t2.fld_store_document_title_total_discount, 0) AS decimal(24, 6))) AS inv_hdr,
                    MAX(CAST(COALESCE(t2.fld_extra_discount, 0) AS decimal(24, 6))) AS inv_extra
                FROM dbo.tbl_store_document_detail AS d2
                INNER JOIN dbo.tbl_store_document_titles AS t2
                    ON t2.fld_store_document_title_id = d2.fld_store_document_title_id_ref
                WHERE ISNULL(d2.fld_is_cancelled, 0) = 0
                  AND ISNULL(t2.fld_is_cancelled, 0) = 0
                GROUP BY d2.fld_store_document_title_id_ref
            ) AS '.$joinAlias.'
                ON '.$joinAlias.'.inv_title_id = '.$titleAlias.'.fld_store_document_title_id';
    }

    /**
     * @return array{
     *     invoiceJoin: string,
     *     lineQty: string,
     *     lineAmount: string,
     *     lineNetAmount: string,
     *     lineWeight: string,
     *     weightSubquery: string
     * }
     */
    public function metricFragments(string $weightAlias = 'w', string $invJoinAlias = 'inv'): array
    {
        return [
            'invoiceJoin' => $this->invoiceDiscountJoinSql('t', $invJoinAlias),
            'lineQty' => $this->lineQuantityExpr('d'),
            'lineAmount' => $this->salesLineAmountExpr('d', $invJoinAlias),
            'lineNetAmount' => $this->lineNetAmountExpr('d'),
            'lineWeight' => $this->lineWeightExpr('d', $weightAlias),
            'weightSubquery' => $this->itemWeightSubquerySql(),
        ];
    }

    public function itemWeightSubquerySql(): string
    {
        return '
            SELECT fld_item_id_ref, MAX(CAST(fld_weight AS float)) AS fld_weight
            FROM dbo.tbl_store_item_setting
            GROUP BY fld_item_id_ref';
    }

    public function lineQuantityExpr(string $detailAlias = 'd'): string
    {
        return 'CAST('.$detailAlias.'.fld_store_document_quantity AS decimal(24, 6))';
    }

    public function lineGrossAmountExpr(string $detailAlias = 'd'): string
    {
        return 'CAST('.$detailAlias.'.fld_store_document_quantity AS decimal(24, 6))
                * CAST('.$detailAlias.'.fld_store_document_unit_price AS decimal(24, 6))';
    }

    public function lineNetAmountExpr(string $detailAlias = 'd'): string
    {
        $gross = $this->lineGrossAmountExpr($detailAlias);

        return $gross.' * (CAST(1 AS decimal(24, 6)) - (CAST(COALESCE('.$detailAlias.'.fld_store_document_discount_percent, 0) AS decimal(24, 6)) / CAST(100 AS decimal(24, 6))))';
    }

    public function salesLineAmountExpr(string $detailAlias = 'd', string $invJoinAlias = 'inv'): string
    {
        $gross = $this->lineGrossAmountExpr($detailAlias);

        return '('.$gross.') - (('.$gross.') / NULLIF(CAST('.$invJoinAlias.'.inv_gross AS decimal(24, 6)), 0))
                * (CAST(COALESCE('.$invJoinAlias.'.inv_hdr, 0) AS decimal(24, 6)) + CAST(COALESCE('.$invJoinAlias.'.inv_extra, 0) AS decimal(24, 6)))';
    }

    /**
     * Line gross minus proportional invoice header discount only ({@code fld_store_document_title_total_discount}).
     */
    public function salesLineHeaderDiscountAmountExpr(string $detailAlias = 'd', string $invJoinAlias = 'inv'): string
    {
        $gross = $this->lineGrossAmountExpr($detailAlias);

        return '('.$gross.') - (('.$gross.') / NULLIF(CAST('.$invJoinAlias.'.inv_gross AS decimal(24, 6)), 0))
                * CAST(COALESCE('.$invJoinAlias.'.inv_hdr, 0) AS decimal(24, 6))';
    }

    /**
     * Line amount for salesman / client price-tier breakdown reports (matches ERP salesman totals).
     *
     * ماركيت (tier index 3): line net when the invoice has no header discount; otherwise proportional
     * header discount only, rounded per line (extra discount excluded).
     * Other tiers: qty × unit price after line discount % only (no header/extra spread).
     */
    public function salesmanTierReportLineAmountExpr(
        string $clientTierIndexSql,
        string $detailAlias = 'd',
        string $invJoinAlias = 'inv'
    ): string {
        $hdrRounded = 'CAST(ROUND(('.$this->salesLineHeaderDiscountAmountExpr($detailAlias, $invJoinAlias).'), 0) AS decimal(24, 6))';
        $lineNet = $this->lineNetAmountExpr($detailAlias);
        $marketAmount = '(CASE WHEN COALESCE('.$invJoinAlias.'.inv_hdr, 0) = 0 THEN '.$lineNet.' ELSE '.$hdrRounded.' END)';

        return '(CASE WHEN ('.$clientTierIndexSql.') = 3 THEN '.$marketAmount.' ELSE '.$lineNet.' END)';
    }

    public function lineWeightExpr(string $detailAlias = 'd', string $weightAlias = 'w'): string
    {
        return 'CAST('.$detailAlias.'.fld_store_document_quantity AS decimal(24, 6))
            * CAST(COALESCE('.$weightAlias.'.fld_weight, 0) AS float)';
    }

    /**
     * Correlated subquery: total posted sales amount for one client account in a date range.
     */
    public function clientPostedSalesAmountSubquerySql(string $from, string $to): string
    {
        $fromLit = $this->assertIsoDateLiteral($from);
        $toLit = $this->assertIsoDateLiteral($to);
        $amount = $this->salesLineAmountExpr('d2', 'inv2');
        $invoiceJoin = $this->invoiceDiscountJoinSql('t2', 'inv2');
        $scope = $this->postedSalesScopeSql(false, 't2', 'd2');

        return '(
                SELECT COALESCE(SUM('.$amount.'), 0)
                FROM dbo.tbl_store_document_detail AS d2
                INNER JOIN dbo.tbl_store_document_titles AS t2
                    ON t2.fld_store_document_title_id = d2.fld_store_document_title_id_ref
                '.$invoiceJoin.'
                WHERE t2.fld_account_id_ref = c.fld_account_id
                  AND CAST(t2.fld_store_document_title_date AS date) >= CAST(\''.$fromLit.'\' AS date)
                  AND CAST(t2.fld_store_document_title_date AS date) <= CAST(\''.$toLit.'\' AS date)
                  AND ISNULL(t2.fld_is_cancelled, 0) = 0
                  AND ISNULL(d2.fld_is_cancelled, 0) = 0
                  '.$scope.'
            )';
    }

    private function assertIsoDateLiteral(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Expected Y-m-d date for SQL literal.');
        }

        return $date;
    }
}
