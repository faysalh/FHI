<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\SalesDocumentMetricsSql;
use App\Support\SalesItemPriceTiers;
use Illuminate\Support\Facades\DB;

$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$visits = app(VisitsReportRepository::class);
$salesmanId = null;
$salesmanName = '';
foreach ($visits->getSalesmanOptions() as $sm) {
    $name = (string) ($sm['name'] ?? '');
    if (stripos($name, 'muhanad') !== false || stripos($name, 'mohanad') !== false || str_contains($name, 'مهند')) {
        echo ($sm['id'] ?? '').' => '.$name.PHP_EOL;
        if ($salesmanId === null) {
            $salesmanId = (string) $sm['id'];
            $salesmanName = $name;
        }
    }
}
if ($salesmanId === null) {
    fwrite(STDERR, "Salesman not found\n");
    exit(1);
}
echo "Using: {$salesmanName} ({$salesmanId})\n\n";

$repo = app(SalesBySalesmanReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$scope = $metrics->postedSalesScopeSql(false);

$hdr = $metrics->salesLineAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$gross = $metrics->lineGrossAmountExpr('d');
$erpAmount = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');
$roundHdrLine = 'CAST(ROUND(('.$hdr.'), 0) AS decimal(24,6))';

$formulas = [
    'erp_current' => $erpAmount,
    'round_hdr_line_all' => $roundHdrLine,
    'hdr_discount' => $hdr,
    'line_net' => $lineNet,
    'gross' => $gross,
];

function sumTier(string $expr, string $tierIdx, int $tier, string $filter, string $tierJoin, string $invoiceJoin, string $scope, string $from, string $to, string $id): float {
    $sql = "
    SELECT SUM({$expr}) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter}
      AND {$tierIdx} = ?
    ";
    return (float) (DB::selectOne($sql, [$from, $to, $id, $tier])->amt ?? 0);
}

foreach (['title' => 'AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)', 'account' => 'AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)'] as $label => $filter) {
    echo "=== Salesman filter: {$label} ===\n";
    foreach ($formulas as $name => $expr) {
        $amt = sumTier($expr, $tierIdx, 3, $filter, $tierJoin, $invoiceJoin, $scope, $dateFrom, $dateTo, $salesmanId);
        echo "  ماركيت {$name}: {$amt}\n";
    }
    $gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $salesmanId, [], null, [], []);
    echo "  App getGrandTotals p3_amt: ".($gt->p3_amt ?? 0).PHP_EOL;
    echo PHP_EOL;
}

// Lines where round(hdr) != erp amount for ماركيت
$sql = "
SELECT d.fld_store_document_detail_id, {$roundHdrLine} AS rounded, ({$erpAmount}) AS erp, ({$hdr}) AS raw_hdr
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
  AND ABS(({$roundHdrLine}) - ({$erpAmount})) > 0.001
";
$diffs = DB::select($sql, [$dateFrom, $dateTo, $salesmanId]);
echo 'Lines where round(hdr)!=erp for ماركيت: '.count($diffs).PHP_EOL;

// Sum rounding gaps on ماركيت
$sql2 = "
SELECT SUM(({$hdr})) AS raw_sum, SUM({$roundHdrLine}) AS round_sum, SUM(({$erpAmount})) AS erp_sum
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
";
$r = DB::selectOne($sql2, [$dateFrom, $dateTo, $salesmanId]);
echo "ماركيت raw_hdr={$r->raw_sum} round_sum={$r->round_sum} erp_sum={$r->erp_sum}\n";
echo 'round - erp = '.((float)$r->round_sum - (float)$r->erp_sum)."\n";
echo 'raw - round = '.((float)$r->raw_sum - (float)$r->round_sum)."\n";

// Per-invoice rounding residual
$sql3 = "
SELECT t.fld_store_document_title_id,
       SUM({$hdr}) AS raw_hdr,
       SUM({$roundHdrLine}) AS round_line_sum,
       CAST(ROUND(SUM({$hdr}), 0) AS decimal(24,6)) AS round_invoice_total
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
GROUP BY t.fld_store_document_title_id
HAVING ABS(SUM({$roundHdrLine}) - CAST(ROUND(SUM({$hdr}), 0) AS decimal(24,6))) > 0.001
   OR ABS(SUM({$hdr}) - CAST(ROUND(SUM({$hdr}), 0) AS decimal(24,6))) > 0.001
";
echo "\nInvoices with rounding differences:\n";
$invGap = 0;
foreach (DB::select($sql3, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $gap = (float)$row->round_invoice_total - (float)$row->round_line_sum;
    if (abs($gap) > 0.001) {
        echo "  inv={$row->fld_store_document_title_id} line_round={$row->round_line_sum} inv_round={$row->round_invoice_total} gap={$gap}\n";
        $invGap += $gap;
    }
}
echo "Sum invoice-level round gaps: {$invGap}\n";
