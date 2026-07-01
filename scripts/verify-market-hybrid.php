<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';
$metrics = new SalesDocumentMetricsSql();
$repo = app(SalesBySalesmanReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$scope = $metrics->postedSalesScopeSql(false);
$hdrOnly = $metrics->salesLineHeaderDiscountAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$roundHdr = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24, 6))';

$hybrid = '(CASE WHEN COALESCE(inv.inv_hdr, 0) = 0 THEN '.$lineNet.' ELSE '.$roundHdr.' END)';
$current = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3";

$cases = [
    ['660E9168-7A24-4529-9CDE-F9B9B20C6913', 'Abd', 465432950],
    ['E8940EFE-9B37-4063-8162-748314D7B32F', 'Muhanad', 264981500],
    ['4A974891-50A3-4872-986A-B84906B43540', 'Hawkar', 192575200],
];

echo "ماركيت tier 3 — current vs hybrid (no hdr => line_net)\n\n";
foreach ($cases as [$id, $name, $target]) {
    $cur = (float) (DB::selectOne("SELECT COALESCE(SUM({$current}), 0) AS a {$base}", [$dateFrom, $dateTo, $id])->a ?? 0);
    $hyb = (float) (DB::selectOne("SELECT COALESCE(SUM({$hybrid}), 0) AS a {$base}", [$dateFrom, $dateTo, $id])->a ?? 0);
    $app = (float) ($itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], [])->p3_amt ?? 0);
    echo "{$name}:\n";
    echo "  target:     ".number_format($target, 0, '.', ',')."\n";
    echo "  current:    ".number_format($cur, 0, '.', ',').'  diff '.number_format($cur - $target, 0, '.', ',')."\n";
    echo "  hybrid:     ".number_format($hyb, 0, '.', ',').'  diff '.number_format($hyb - $target, 0, '.', ',')."\n";
    echo "  app repo:   ".number_format($app, 0, '.', ',')."\n\n";
}
