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
$scope = $metrics->postedSalesScopeSql(false);

$amounts = [
    'discount_aware' => $lineAmount,
    'gross' => $gross,
    'line_net' => 'CAST(d.fld_store_document_quantity AS decimal(24,6)) * CAST(d.fld_store_document_unit_price AS decimal(24,6)) - CAST(COALESCE(d.fld_store_document_line_discount, 0) AS decimal(24,6))',
];

foreach ($amounts as $label => $expr) {
    $sql = "
    SELECT SUM({$expr}) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
      AND {$tierIdx} = 4
    ";
    $r = DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId]);
    echo "جملة (tier 4) {$label}: ".($r->amt ?? 0).PHP_EOL;
}

// Lines where gross != net in جملة tier - sum gaps
$sql2 = "
SELECT SUM({$gross}) - SUM({$lineAmount}) AS total_gap,
       COUNT(*) AS lines
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 4
";
$r2 = DB::selectOne($sql2, [$dateFrom, $dateTo, $salesmanId]);
echo "جملة gross-net gap: ".($r2->total_gap ?? 0)." ({$r2->lines} lines)\n";

// Per-invoice gaps in جملة tier
$sql3 = "
SELECT t.fld_store_document_title_id,
       SUM({$gross}) AS gross,
       SUM({$lineAmount}) AS net,
       SUM({$gross}) - SUM({$lineAmount}) AS gap,
       MAX(CAST(COALESCE(t.fld_store_document_title_total_discount,0) AS decimal(24,6))) AS hdr_disc,
       MAX(CAST(COALESCE(t.fld_extra_discount,0) AS decimal(24,6))) AS extra_disc
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 4
GROUP BY t.fld_store_document_title_id
HAVING ABS(SUM({$gross}) - SUM({$lineAmount})) > 0.001
ORDER BY gap DESC
";
echo "\nInvoices with gross!=net in جملة:\n";
$gapSum = 0;
foreach (DB::select($sql3, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    $gapSum += (float)$row->gap;
    if ((float)$row->gap > 100) {
        echo "  id={$row->fld_store_document_title_id} gross={$row->gross} net={$row->net} gap={$row->gap} hdr={$row->hdr_disc} extra={$row->extra_disc}\n";
    }
}
echo "Sum of all invoice gaps: {$gapSum}\n";
echo "Target جملة: 268974700, current net: 268973650.0026, need +1050\n";
