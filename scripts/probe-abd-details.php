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

// How many detail rows per account have different price groups?
$sql = "
SELECT
    ad.fld_account_id_ref,
    COUNT(*) AS detail_rows,
    COUNT(DISTINCT CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)) AS distinct_tiers
FROM dbo.tbl_accounting_account_details ad
INNER JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = ad.fld_account_id_ref
WHERE a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY ad.fld_account_id_ref
HAVING COUNT(DISTINCT CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)) > 1
";
$multi = DB::select($sql, [$salesmanId]);
echo 'Accounts with multiple distinct price_group in details: '.count($multi).PHP_EOL;

// Sales attributed using detail row joined on title (if there's a link)
$detailCols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_accounting_account_details' ORDER BY ORDINAL_POSITION");
echo "Account details columns:\n";
foreach ($detailCols as $c) {
    echo '  '.$c->COLUMN_NAME.PHP_EOL;
}

// Try joining account_details without aggregation - might duplicate lines
$sqlDup = "
SELECT CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1 AS tier_idx,
       SUM({$lineAmount}) AS amt,
       COUNT(*) AS line_count
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
INNER JOIN dbo.tbl_accounting_account_details ad ON ad.fld_account_id_ref = a.fld_account_id
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1
ORDER BY tier_idx
";
echo "\nINNER JOIN all account_details rows (may duplicate):\n";
$labels = [1=>'وكيل',2=>'وكيل2',3=>'ماركيت',4=>'جملة',5=>'كي'];
foreach (DB::select($sqlDup, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)$row->tier_idx;
    echo "  {$t} (".($labels[$t]??'?').") amt={$row->amt} lines={$row->line_count}\n";
}

// Title-level fld_account_detail_id_ref?
$titleCols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_store_document_titles' AND COLUMN_NAME LIKE '%account%'");
echo "\nTitle account-related columns:\n";
foreach ($titleCols as $c) { echo '  '.$c->COLUMN_NAME.PHP_EOL; }

// Check fld_accounting_account_detail_id_ref on titles
$refCol = null;
foreach ($titleCols as $c) {
    if (stripos($c->COLUMN_NAME, 'detail') !== false) {
        $refCol = $c->COLUMN_NAME;
    }
}
if ($refCol) {
    $sqlRef = "
    SELECT CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1 AS tier_idx,
           SUM({$lineAmount}) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    LEFT JOIN dbo.tbl_accounting_account_details ad ON ad.fld_accounting_account_detail_id = t.[{$refCol}]
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
    GROUP BY CAST(ROUND(CAST(TRY_CAST(ad.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1
    ORDER BY tier_idx
    ";
    echo "\nTier via title->{$refCol}:\n";
    try {
        foreach (DB::select($sqlRef, [$dateFrom, $dateTo, $salesmanId]) as $row) {
            $t = (int)($row->tier_idx ?? 0);
            echo "  {$t} (".($labels[$t]??'?').") amt={$row->amt}\n";
        }
    } catch (Throwable $e) {
        echo '  Error: '.$e->getMessage()."\n";
    }
}

// Alternative: use accounts.fld_price_group if we add to candidates - check if column exists on accounts
$acctPg = DB::selectOne("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_accounting_accounts' AND COLUMN_NAME='fld_price_group'");
echo "\naccounts.fld_price_group exists: ".(($acctPg->c ?? 0) > 0 ? 'yes' : 'no')."\n";

// Per-invoice: use price group at time of sale from detail row with MAX id?
$sqlLatest = "
LEFT JOIN (
    SELECT fld_account_id_ref, fld_price_group,
           ROW_NUMBER() OVER (PARTITION BY fld_account_id_ref ORDER BY fld_accounting_account_detail_id DESC) AS rn
    FROM dbo.tbl_accounting_account_details
) ad_latest ON ad_latest.fld_account_id_ref = a.fld_account_id AND ad_latest.rn = 1
";
$sqlLatest2 = "
SELECT CAST(ROUND(CAST(TRY_CAST(ad_latest.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1 AS tier_idx,
       SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$sqlLatest}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(TRY_CAST(ad_latest.fld_price_group AS FLOAT) AS FLOAT),0) AS int)+1
ORDER BY tier_idx
";
echo "\nLatest detail row per account (by detail id DESC):\n";
foreach (DB::select($sqlLatest2, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)($row->tier_idx ?? 0);
    echo "  {$t} (".($labels[$t]??'?').") amt={$row->amt}\n";
}
