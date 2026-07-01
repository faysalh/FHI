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
$empty = "N''";

$detailMaxJoin = "
LEFT JOIN (
    SELECT fld_account_id_ref,
           MAX(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST([fld_price_group] AS NVARCHAR(100)))), {$empty}) AS DECIMAL(24, 6))) AS d_tier
    FROM dbo.tbl_accounting_account_details GROUP BY fld_account_id_ref
) AS det ON det.fld_account_id_ref = a.fld_account_id
";

// accounts.fld_price_group exists?
$acctCols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_accounting_accounts' AND COLUMN_NAME IN ('fld_price_group','fld_upto_price_group')");
echo "Account columns: ";
foreach ($acctCols as $c) { echo $c->COLUMN_NAME.' '; }
echo PHP_EOL;

// Clients where account header tier differs from details MAX
$sql = "
SELECT
    CAST(ROUND(CAST(a.fld_upto_price_group AS FLOAT),0) AS int) AS upto,
    CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int) AS det_max,
    COUNT(DISTINCT a.fld_account_id) AS clients
FROM dbo.tbl_accounting_accounts a
LEFT JOIN {$detailMaxJoin} 
WHERE a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(a.fld_upto_price_group AS FLOAT),0) AS int), CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int)
ORDER BY upto, det_max
";
// fix join syntax
$sql = "
SELECT
    CAST(ROUND(CAST(TRY_CAST(a.fld_upto_price_group AS FLOAT) AS FLOAT),0) AS int) AS upto,
    CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int) AS det_max,
    COUNT(DISTINCT a.fld_account_id) AS clients
FROM dbo.tbl_accounting_accounts a
{$detailMaxJoin}
WHERE a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(TRY_CAST(a.fld_upto_price_group AS FLOAT) AS FLOAT),0) AS int), CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int)
ORDER BY upto, det_max
";
echo "\nAccount upto vs details MAX distribution:\n";
foreach (DB::select($sql, [$salesmanId]) as $row) {
    echo "  upto={$row->upto} det_max={$row->det_max} clients={$row->clients}\n";
}

// Sales amount when upto != det_max
$sql2 = "
SELECT
    CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int) AS det_max,
    CAST(ROUND(CAST(TRY_CAST(a.fld_upto_price_group AS FLOAT) AS FLOAT),0) AS int) AS upto,
    SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$detailMaxJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND (
    CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int) <> CAST(ROUND(CAST(TRY_CAST(a.fld_upto_price_group AS FLOAT) AS FLOAT),0) AS int)
    OR det.d_tier IS NULL OR a.fld_upto_price_group IS NULL
  )
GROUP BY CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int), CAST(ROUND(CAST(TRY_CAST(a.fld_upto_price_group AS FLOAT) AS FLOAT),0) AS int)
ORDER BY amt DESC
";
echo "\nSales where upto != det_max (det_max, upto, amt):\n";
foreach (DB::select($sql2, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  det={$row->det_max} upto={$row->upto} amt={$row->amt}\n";
}

// Try COALESCE(upto, det_max) as tier source
$coalesceTier = "COALESCE(
    TRY_CAST(NULLIF(LTRIM(RTRIM(CAST(a.fld_upto_price_group AS NVARCHAR(100)))), {$empty}) AS DECIMAL(24,6)),
    det.d_tier
)";
$coalesceIdx = "(CASE WHEN ({$coalesceTier}) IS NULL THEN 0 ELSE CAST(ROUND(CAST(({$coalesceTier}) AS FLOAT),0) AS int)+1 END)";

$sql3 = "
SELECT {$coalesceIdx} AS tier_idx, SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$detailMaxJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$coalesceIdx}
ORDER BY tier_idx
";
echo "\nCOALESCE(upto, det_max) tiers:\n";
$labels = [1=>'وكيل',2=>'وكيل2',3=>'ماركيت',4=>'جملة',5=>'كي'];
foreach (DB::select($sql3, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)$row->tier_idx;
    echo "  {$t} (".($labels[$t]??'?').") amt={$row->amt}\n";
}

// fld_person on title vs account salesman
$sql4 = "
SELECT
    CASE WHEN t.fld_person IS NOT NULL AND a.fld_sales_man_id_ref IS NOT NULL
         AND CAST(t.fld_person AS UNIQUEIDENTIFIER) <> a.fld_sales_man_id_ref THEN 1 ELSE 0 END AS person_mismatch,
    SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CASE WHEN t.fld_person IS NOT NULL AND a.fld_sales_man_id_ref IS NOT NULL
         AND CAST(t.fld_person AS UNIQUEIDENTIFIER) <> a.fld_sales_man_id_ref THEN 1 ELSE 0 END
";
echo "\nTitle fld_person vs account salesman:\n";
foreach (DB::select($sql4, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  mismatch={$row->person_mismatch} amt={$row->amt}\n";
}

// Filter by title fld_person = salesman instead of account
$sql5 = "
SELECT CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int)+1 AS tier_idx, SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$detailMaxJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_person = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(det.d_tier AS FLOAT),0) AS int)+1
ORDER BY tier_idx
";
echo "\nFilter t.fld_person = salesman (not account):\n";
foreach (DB::select($sql5, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)$row->tier_idx;
    echo "  {$t} (".($labels[$t]??'?').") amt={$row->amt}\n";
}
