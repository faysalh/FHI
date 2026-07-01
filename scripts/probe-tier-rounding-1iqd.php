<?php

declare(strict_types=1);

/**
 * Find ±1 IQD rounding gaps vs ERP for ماركيت (tier 3).
 * Usage: php scripts/probe-tier-rounding-1iqd.php <salesman_id> [date_from] [date_to] [erp_target]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$id = $argv[1] ?? '';
$dateFrom = $argv[2] ?? '2026-06-01';
$dateTo = $argv[3] ?? '2026-06-30';
$erpTarget = isset($argv[4]) ? (float) $argv[4] : null;

if ($id === '') {
    fwrite(STDERR, "Usage: php scripts/probe-tier-rounding-1iqd.php <salesman_id> [from] [to] [erp_target]\n");
    exit(1);
}

$repo = app(SalesBySalesmanReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$m = new SalesDocumentMetricsSql();
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$invJoin = $m->invoiceDiscountJoinSql('t', 'inv');
$scope = $m->postedSalesScopeSql(false);
$lineNet = $m->lineNetAmountExpr('d');
$hdrOnly = $m->salesLineHeaderDiscountAmountExpr('d', 'inv');
$roundHdr = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24, 6))';
$roundNet = 'CAST(ROUND(('.$lineNet.'), 0) AS decimal(24, 6))';

$variants = [
    'app_current' => $m->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv'),
    'round_net_when_no_hdr' => '(CASE WHEN ('.$tierIdx.') = 3 THEN (CASE WHEN COALESCE(inv.inv_hdr, 0) = 0 THEN '.$roundNet.' ELSE '.$roundHdr.' END) ELSE '.$lineNet.' END)',
    'round_net_all_tiers' => '(CASE WHEN ('.$tierIdx.') = 3 THEN (CASE WHEN COALESCE(inv.inv_hdr, 0) = 0 THEN '.$roundNet.' ELSE '.$roundHdr.' END) ELSE '.$roundNet.' END)',
];

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3";

$gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], []);
echo "Salesman {$id} ماركيت {$dateFrom}..{$dateTo}\n";
echo 'Item repo p3_amt: '.($gt->p3_amt ?? 0)."\n";
if ($erpTarget !== null) {
    echo 'ERP target: '.$erpTarget."\n\n";
}

foreach ($variants as $name => $expr) {
    $amt = (float) (DB::selectOne("SELECT COALESCE(SUM({$expr}), 0) AS a {$base}", [$dateFrom, $dateTo, $id])->a ?? 0);
    $diff = $erpTarget !== null ? $amt - $erpTarget : null;
    echo sprintf("  %-22s %15s", $name, number_format($amt, 0, '.', ','));
    if ($diff !== null) {
        echo '  diff '.number_format($diff, 0, '.', ',');
        echo abs($diff) < 0.01 ? ' EXACT' : (abs($diff) <= 1.01 ? ' ~1 IQD' : '');
    }
    echo "\n";
}

// Fractional line_net lines when no hdr (candidate for +1)
$sql = "
SELECT COUNT(*) AS c,
       COALESCE(SUM({$lineNet}), 0) AS raw_sum,
       COALESCE(SUM({$roundNet}), 0) AS round_sum,
       COALESCE(SUM({$roundNet}), 0) - COALESCE(SUM({$lineNet}), 0) AS delta
{$base} AND COALESCE(inv.inv_hdr, 0) = 0
  AND ({$lineNet}) <> CAST(ROUND(({$lineNet}), 0) AS decimal(24, 6))
";
$r = DB::selectOne($sql, [$dateFrom, $dateTo, $id]);
echo "\nNo-hdr lines with fractional line_net: count={$r->c} raw={$r->raw_sum} rounded={$r->round_sum} delta={$r->delta}\n";

// Fractional hdr lines when hdr > 0
$sql2 = "
SELECT COUNT(*) AS c,
       COALESCE(SUM({$hdrOnly}), 0) AS raw_sum,
       COALESCE(SUM({$roundHdr}), 0) AS round_sum
{$base} AND COALESCE(inv.inv_hdr, 0) > 0
";
$r2 = DB::selectOne($sql2, [$dateFrom, $dateTo, $id]);
echo "Hdr lines: count={$r2->c} raw_hdr={$r2->raw_sum} round_hdr={$r2->round_sum} round_delta=".((float)$r2->round_sum - (float)$r2->raw_sum)."\n";
