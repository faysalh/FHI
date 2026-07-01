<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$repo = app(SalesBySalesmanReportRepository::class);
echo 'Resolved column: '.$repo->getResolvedClientPriceGroupColumn().PHP_EOL;

$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$lineAmount = $metrics->salesLineAmountExpr('d', 'inv');
$grossAmount = 'CAST(d.fld_store_document_quantity AS decimal(24,6)) * CAST(d.fld_store_document_unit_price AS decimal(24,6))';
$scope = $metrics->postedSalesScopeSql(false);

$labels = [0 => 'وكيل', 1 => 'وكيل2', 2 => 'ماركيت', 3 => 'جملة', 4 => 'كي'];
$displayLabels = [1 => 'وكيل', 2 => 'وكيل2', 3 => 'ماركيت', 4 => 'جملة', 5 => 'كي'];

function tierTotals(
    string $label,
    string $tierIndexSql,
    string $tierJoin,
    string $amountExpr,
    string $dateFrom,
    string $dateTo,
    string $salesmanId,
    string $invoiceJoin,
    string $scope,
    array $displayLabels
): void {
    $sql = "
    SELECT {$tierIndexSql} AS tier_idx, SUM({$amountExpr}) AS amt
    FROM dbo.tbl_store_document_detail AS d
    INNER JOIN dbo.tbl_store_document_titles AS t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts AS a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) >= CAST(? AS date)
      AND CAST(t.fld_store_document_title_date AS date) <= CAST(? AS date)
      AND ISNULL(t.fld_is_cancelled, 0) = 0
      AND ISNULL(d.fld_is_cancelled, 0) = 0
      {$scope}
      AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
    GROUP BY {$tierIndexSql}
    ORDER BY tier_idx
    ";
    echo "\n=== {$label} ===\n";
    foreach (DB::select($sql, [$dateFrom, $dateTo, $salesmanId]) as $row) {
        $t = (int) ($row->tier_idx ?? 0);
        $name = $displayLabels[$t] ?? ($t === 0 ? 'unknown' : 'tier '.$t);
        echo "  col {$t} ({$name}) amt=".($row->amt ?? 0).PHP_EOL;
    }
}

$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();

tierTotals('CURRENT (discount-aware)', $tierIdx, $tierJoin, $lineAmount, $dateFrom, $dateTo, $salesmanId, $invoiceJoin, $scope, $displayLabels);
tierTotals('CURRENT (gross)', $tierIdx, $tierJoin, $grossAmount, $dateFrom, $dateTo, $salesmanId, $invoiceJoin, $scope, $displayLabels);

// fld_upto_price_group on accounts only
$uptoExpr = "CAST(ROUND(CAST(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST(a.[fld_upto_price_group] AS NVARCHAR(100)))), N'') AS DECIMAL(24,6)) AS FLOAT),0) AS int)";
$uptoIdx = "(CASE WHEN ({$uptoExpr}) IS NULL THEN 0 WHEN {$uptoExpr} < 0 THEN 1 WHEN {$uptoExpr} > 4 THEN 5 ELSE {$uptoExpr} + 1 END)";
tierTotals('fld_upto_price_group (discount)', $uptoIdx, '', $lineAmount, $dateFrom, $dateTo, $salesmanId, $invoiceJoin, $scope, $displayLabels);

// accounts.fld_price_group if exists
$cols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME IN ('tbl_accounting_accounts','tbl_accounting_account_details') AND COLUMN_NAME LIKE '%price%' ORDER BY TABLE_NAME, COLUMN_NAME");
echo "\nPrice-related columns:\n";
foreach ($cols as $c) {
    echo '  '.$c->COLUMN_NAME.PHP_EOL;
}

// Compare MAX vs MIN on details
$empty = "N''";
$minJoin = "
LEFT JOIN (
    SELECT fld_account_id_ref,
           MIN(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST([fld_price_group] AS NVARCHAR(100)))), {$empty}) AS DECIMAL(24, 6))) AS rp_tier_raw
    FROM dbo.tbl_accounting_account_details GROUP BY fld_account_id_ref
) AS rp_tier ON rp_tier.fld_account_id_ref = a.fld_account_id
";
$minTierNum = 'rp_tier.rp_tier_raw';
$minIdx = "(CASE WHEN ({$minTierNum}) IS NULL THEN 0 WHEN CAST(ROUND(CAST(({$minTierNum}) AS FLOAT),0) AS int) < 0 THEN 1 WHEN CAST(ROUND(CAST(({$minTierNum}) AS FLOAT),0) AS int) > 4 THEN 5 ELSE CAST(ROUND(CAST(({$minTierNum}) AS FLOAT),0) AS int) + 1 END)";
tierTotals('MIN(details.fld_price_group)', $minIdx, $minJoin, $lineAmount, $dateFrom, $dateTo, $salesmanId, $invoiceJoin, $scope, $displayLabels);

// Sales by item report grand totals
$itemRepo = app(SalesByItemReportRepository::class);
$gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $salesmanId, [], null, []);
echo "\nSalesByItemReportRepository grand totals (amt):\n";
for ($t = 1; $t <= 5; $t++) {
    $key = 'p'.$t.'_amt';
    echo '  tier '.$t.' ('.($displayLabels[$t] ?? '').') '.($gt->{$key} ?? 0).PHP_EOL;
}

echo "\nUser expects: كي=1669750, شبه جملة/جملة=268974700, ماركيت=465432950\n";
