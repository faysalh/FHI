<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$dateFrom = $argv[1] ?? '2026-06-01';
$dateTo = $argv[2] ?? '2026-06-30';
$nameNeedle = $argv[3] ?? 'hawkar';

$visits = app(VisitsReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$salesmanRepo = app(SalesBySalesmanReportRepository::class);
$metrics = new SalesDocumentMetricsSql();

$salesmanId = null;
$salesmanName = null;
foreach ($visits->getSalesmanOptions() as $sm) {
    $name = (string) ($sm['name'] ?? '');
    if (stripos($name, $nameNeedle) !== false || stripos($name, 'عهاوكار') !== false || stripos($name, 'هاوكار') !== false) {
        $salesmanId = (string) ($sm['id'] ?? '');
        $salesmanName = $name;
        break;
    }
}

if ($salesmanId === null) {
    echo "Salesman not found for needle: {$nameNeedle}\n";
    exit(1);
}

echo "=== {$salesmanName} ({$salesmanId}) {$dateFrom} .. {$dateTo} ===\n\n";

$tierJoin = $salesmanRepo->priceTierJoinSql();
$tierIdx = $salesmanRepo->clientPriceTierIndexSql();
$scope = $metrics->postedSalesScopeSql(false);
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$erpExpr = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');
$hdrBoth = 'CAST(ROUND(('.$metrics->salesLineAmountExpr('d', 'inv').'), 0) AS decimal(24, 6))';
$hdrOnly = 'CAST(ROUND(('.$metrics->salesLineHeaderDiscountAmountExpr('d', 'inv').'), 0) AS decimal(24, 6))';
$lineNet = $metrics->lineNetAmountExpr('d');
$gross = $metrics->lineGrossAmountExpr('d');

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)";

$accountBase = str_replace('t.fld_sales_man_id_ref', 'a.fld_sales_man_id_ref', $base);

$gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $salesmanId, [], null, [], []);
$appMarket = (float) ($gt->p3_amt ?? 0);

echo "App item report ماركيت (tier 3): {$appMarket}\n\n";

$formulas = [
    'erp_current' => $erpExpr,
    'hdr_only_rounded' => $hdrOnly,
    'hdr_and_extra_rounded' => $hdrBoth,
    'line_net' => $lineNet,
    'gross' => $gross,
    'sum_rounded_hdr_only' => "SUM(CAST(ROUND((".$metrics->salesLineHeaderDiscountAmountExpr('d', 'inv')."), 0) AS decimal(24, 6)))",
    'sum_not_rounded_hdr_only' => 'SUM('.$metrics->salesLineHeaderDiscountAmountExpr('d', 'inv').')',
];

foreach ($formulas as $label => $expr) {
  foreach ([['title', $base], ['account', $accountBase]] as [$filter, $sql]) {
    $amt = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$expr}), 0) AS a {$sql} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $salesmanId]
    )->a ?? 0);
    echo sprintf("  %-28s %-8s: %s\n", $label, $filter, number_format($amt, 0, '.', ','));
  }
}

echo "\nPer-tier title salesman (erp_current):\n";
foreach ([1, 2, 3, 4, 5] as $tier) {
    $amt = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$base} AND {$tierIdx} = ?",
        [$dateFrom, $dateTo, $salesmanId, $tier]
    )->a ?? 0);
    echo "  tier {$tier}: ".number_format($amt, 0, '.', ',')."\n";
}

// Lines where hdr+extra differs from hdr-only for market tier
echo "\nInvoices with extra discount affecting market tier (top 20 by |delta|):\n";
$deltaExpr = "CAST(ROUND((".$metrics->salesLineAmountExpr('d', 'inv')."), 0) AS decimal(24, 6))
    - CAST(ROUND((".$metrics->salesLineHeaderDiscountAmountExpr('d', 'inv')."), 0) AS decimal(24, 6))";
$rows = DB::select("
    SELECT t.fld_store_document_title_id AS title_id,
           CAST(t.fld_store_document_title_date AS date) AS doc_date,
           SUM({$deltaExpr}) AS delta
    {$base} AND {$tierIdx} = 3
    GROUP BY t.fld_store_document_title_id, t.fld_store_document_title_date
    HAVING ABS(SUM({$deltaExpr})) > 0.01
    ORDER BY ABS(SUM({$deltaExpr})) DESC
", [$dateFrom, $dateTo, $salesmanId]);

$extraTotal = 0.0;
foreach ($rows as $row) {
    $extraTotal += (float) $row->delta;
}
echo '  extra-discount delta sum: '.number_format($extraTotal, 0, '.', ',')." (".count($rows)." invoices)\n";

// Account vs title salesman mismatch lines on market tier
$titleOnly = (float) (DB::selectOne("SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$base} AND {$tierIdx} = 3", [$dateFrom, $dateTo, $salesmanId])->a ?? 0);
$accountOnly = (float) (DB::selectOne("SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$accountBase} AND {$tierIdx} = 3", [$dateFrom, $dateTo, $salesmanId])->a ?? 0);
echo "\nTitle vs account salesman filter (market): title={$titleOnly} account={$accountOnly} diff=".($accountOnly - $titleOnly)."\n";

// Lines attributed to Hawkar on title but different account salesman (or vice versa)
echo "\nMarket lines: title salesman = Hawkar but account salesman differs:\n";
$misTitle = (float) (DB::selectOne("
    SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$base} AND {$tierIdx} = 3
      AND a.fld_sales_man_id_ref IS NOT NULL
      AND a.fld_sales_man_id_ref <> t.fld_sales_man_id_ref
", [$dateFrom, $dateTo, $salesmanId])->a ?? 0);
echo "  amount: {$misTitle}\n";

echo "\nMarket lines: account salesman = Hawkar but title salesman differs:\n";
$misAccount = (float) (DB::selectOne("
    SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$accountBase} AND {$tierIdx} = 3
      AND t.fld_sales_man_id_ref IS NOT NULL
      AND t.fld_sales_man_id_ref <> a.fld_sales_man_id_ref
", [$dateFrom, $dateTo, $salesmanId])->a ?? 0);
echo "  amount: {$misAccount}\n";

if (isset($argv[4]) && is_numeric($argv[4])) {
    $target = (float) $argv[4];
    echo "\nUser ERP target: ".number_format($target, 0, '.', ',')."\n";
    echo "App - target: ".number_format($appMarket - $target, 0, '.', ',')."\n";
    foreach ($formulas as $label => $expr) {
        $amt = (float) (DB::selectOne(
            "SELECT COALESCE(SUM({$expr}), 0) AS a {$base} AND {$tierIdx} = 3",
            [$dateFrom, $dateTo, $salesmanId]
        )->a ?? 0);
        $diff = $amt - $target;
        if (abs($diff) < 0.01) {
            echo "MATCH: {$label} = ".number_format($amt, 0, '.', ',')."\n";
        }
    }
}
