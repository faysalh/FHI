<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$id = '4A974891-50A3-4872-986A-B84906B43540';
$repo = app(SalesBySalesmanReportRepository::class);
$m = new SalesDocumentMetricsSql();
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$invJoin = $m->invoiceDiscountJoinSql('t', 'inv');
$scope = $m->postedSalesScopeSql(false);
$expr = $m->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');
$hdrOnly = $m->salesLineHeaderDiscountAmountExpr('d', 'inv');
$lineNet = $m->lineNetAmountExpr('d');
$roundHdr = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24, 6))';

$sql = "
SELECT TOP 20
    t.fld_store_document_title_id,
    d.fld_store_document_detail_id,
    COALESCE(inv.inv_hdr, 0) AS hdr,
    ({$lineNet}) AS line_net,
    ({$roundHdr}) AS hdr_r,
    ({$expr}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
ORDER BY ABS(({$expr}) - CAST(ROUND(({$lineNet}), 0) AS decimal(24,6))) DESC
";
foreach (DB::select($sql, [$id]) as $r) {
    echo "inv={$r->fld_store_document_title_id} hdr={$r->hdr} net={$r->line_net} hdr_r={$r->hdr_r} amt={$r->amt}\n";
}
