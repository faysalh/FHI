<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$repo = app(SalesBySalesmanReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();

$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$lineAmount = $metrics->salesLineAmountExpr('d', 'inv');
$gross = 'CAST(d.fld_store_document_quantity AS decimal(24,6)) * CAST(d.fld_store_document_unit_price AS decimal(24,6))';
$titleTotal = 'CAST(COALESCE(t.fld_store_document_title_total, 0) AS decimal(24,6))';
$scope = $metrics->postedSalesScopeSql(false);

$labels = [3=>'ماركيت',4=>'جملة',5=>'كي'];

// Net vs gross per tier
$sql = "
SELECT {$tierIdx} AS t,
       SUM({$lineAmount}) AS net,
       SUM({$gross}) AS gross,
       SUM({$gross}) - SUM({$lineAmount}) AS discount_gap
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$tierIdx}
ORDER BY t
";
echo "Net vs gross by tier:\n";
$totalDiscountGap = 0;
foreach (DB::select($sql, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  {$labels[(int)$row->t]} net={$row->net} gross={$row->gross} gap={$row->discount_gap}\n";
    $totalDiscountGap += (float)$row->discount_gap;
}
echo "Total discount gap: {$totalDiscountGap}\n";

// Title total sum (distinct titles) by client tier - allocate full title total to tier
$sql2 = "
SELECT tier_idx, SUM(title_total) AS amt FROM (
    SELECT {$tierIdx} AS tier_idx, t.fld_store_document_title_id,
           MAX({$titleTotal}) AS title_total
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
    GROUP BY {$tierIdx}, t.fld_store_document_title_id
) x GROUP BY tier_idx ORDER BY tier_idx
";
echo "\nSum of invoice title totals by client tier:\n";
foreach (DB::select($sql2, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)$row->tier_idx;
    echo "  ".($labels[$t]??$t)." amt={$row->amt}\n";
}

// fld_price_group raw values - maybe 1-based in UI?
$sql3 = "
SELECT CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int) AS raw_db,
       {$tierIdx} AS col_idx,
       SUM({$lineAmount}) AS amt,
       COUNT(DISTINCT a.fld_account_id) AS clients
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int), {$tierIdx}
ORDER BY raw_db
";
echo "\nRaw DB value vs display column:\n";
foreach (DB::select($sql3, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  raw={$row->raw_db} col={$row->col_idx} clients={$row->clients} amt={$row->amt}\n";
}

// What if tier mapping uses raw value AS column number (1-5) without +1?
$rawAsCol = "CASE WHEN rp_tier.rp_tier_raw IS NULL THEN 0 ELSE CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int) END";
$sql4 = "
SELECT {$rawAsCol} AS tier_idx, SUM({$lineAmount}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$rawAsCol}
ORDER BY tier_idx
";
echo "\nIf DB raw used directly as tier 1-5 (no +1):\n";
$allLabels = [1=>'وكيل',2=>'وكيل2/شبه?',3=>'ماركيت',4=>'جملة',5=>'كي'];
foreach (DB::select($sql4, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $t = (int)$row->tier_idx;
    echo "  tier {$t} (".($allLabels[$t]??'?').") amt={$row->amt}\n";
}

echo "\nUser: ماركيت=465432950, شبه جملة/جملة=268974700, كي=1669750\n";
