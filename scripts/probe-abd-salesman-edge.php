<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$lineAmount = $metrics->salesLineAmountExpr('d', 'inv');
$scope = $metrics->postedSalesScopeSql(false);

$sql = "
SELECT
    CASE
        WHEN t.fld_sales_man_id_ref IS NULL AND a.fld_sales_man_id_ref IS NULL THEN 'both_null'
        WHEN t.fld_sales_man_id_ref IS NULL THEN 'title_null'
        WHEN a.fld_sales_man_id_ref IS NULL THEN 'account_null'
        WHEN t.fld_sales_man_id_ref = a.fld_sales_man_id_ref THEN 'match'
        ELSE 'mismatch'
    END AS bucket,
    SUM({$lineAmount}) AS amt,
    COUNT(*) AS lines
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND (t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER) OR a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER))
GROUP BY CASE
        WHEN t.fld_sales_man_id_ref IS NULL AND a.fld_sales_man_id_ref IS NULL THEN 'both_null'
        WHEN t.fld_sales_man_id_ref IS NULL THEN 'title_null'
        WHEN a.fld_sales_man_id_ref IS NULL THEN 'account_null'
        WHEN t.fld_sales_man_id_ref = a.fld_sales_man_id_ref THEN 'match'
        ELSE 'mismatch'
    END
";
foreach (DB::select($sql, [$dateFrom, $dateTo, $salesmanId, $salesmanId]) as $row) {
    echo "{$row->bucket}: amt={$row->amt} lines={$row->lines}\n";
}

// COALESCE title, account
$sql2 = "
SELECT SUM({$lineAmount}) AS amt FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND COALESCE(t.fld_sales_man_id_ref, a.fld_sales_man_id_ref) = CAST(? AS UNIQUEIDENTIFIER)
";
$r = DB::selectOne($sql2, [$dateFrom, $dateTo, $salesmanId]);
echo "\nCOALESCE(title, account) total: {$r->amt}\n";
