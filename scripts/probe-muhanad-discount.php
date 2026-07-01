<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use Illuminate\Support\Facades\DB;

$salesmanId = 'E8940EFE-9B37-4063-8162-748314D7B32F';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$repo = app(SalesBySalesmanReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$scope = " AND COALESCE(t.fld_type_alias, N'') = N'S' AND CAST(d.fld_store_document_quantity AS decimal(24,6)) > 0 ";

$gross = 'CAST(d.fld_store_document_quantity AS decimal(24,6)) * CAST(d.fld_store_document_unit_price AS decimal(24,6))';

function hdrExpr(string $discPart): string {
    global $gross;
    return '('.$gross.') - (('.$gross.') / NULLIF(CAST(inv.inv_gross AS decimal(24,6)), 0)) * ('.$discPart.')';
}

$invoiceJoin = "
LEFT JOIN (
    SELECT d2.fld_store_document_title_id_ref AS inv_title_id,
           SUM(CAST(d2.fld_store_document_quantity AS decimal(24,6)) * CAST(d2.fld_store_document_unit_price AS decimal(24,6))) AS inv_gross,
           MAX(CAST(COALESCE(t2.fld_store_document_title_total_discount, 0) AS decimal(24,6))) AS inv_hdr,
           MAX(CAST(COALESCE(t2.fld_extra_discount, 0) AS decimal(24,6))) AS inv_extra
    FROM dbo.tbl_store_document_detail d2
    INNER JOIN dbo.tbl_store_document_titles t2 ON t2.fld_store_document_title_id = d2.fld_store_document_title_id_ref
    WHERE ISNULL(d2.fld_is_cancelled,0)=0 AND ISNULL(t2.fld_is_cancelled,0)=0
    GROUP BY d2.fld_store_document_title_id_ref
) AS inv ON inv.inv_title_id = t.fld_store_document_title_id
";

$base = "
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

$hdrBoth = hdrExpr('CAST(COALESCE(inv.inv_hdr,0) AS decimal(24,6)) + CAST(COALESCE(inv.inv_extra,0) AS decimal(24,6))');
$hdrOnly = hdrExpr('CAST(COALESCE(inv.inv_hdr,0) AS decimal(24,6))');
$extraOnly = hdrExpr('CAST(COALESCE(inv.inv_extra,0) AS decimal(24,6))');
$lineNet = $gross.' * (CAST(1 AS decimal(24,6)) - (CAST(COALESCE(d.fld_store_document_discount_percent, 0) AS decimal(24,6)) / CAST(100 AS decimal(24,6))))';

foreach ([
    'sum round(hdr both)' => 'SUM(CAST(ROUND(('.$hdrBoth.'),0) AS decimal(24,6)))',
    'sum round(hdr only)' => 'SUM(CAST(ROUND(('.$hdrOnly.'),0) AS decimal(24,6)))',
    'sum round(line_net)' => 'SUM(CAST(ROUND(('.$lineNet.'),0) AS decimal(24,6)))',
    'sum line_net' => 'SUM('.$lineNet.')',
    'sum gross' => 'SUM('.$gross.')',
    'round sum hdr both' => 'CAST(ROUND(SUM('.$hdrBoth.'),0) AS decimal(24,6))',
    'round sum hdr only' => 'CAST(ROUND(SUM('.$hdrOnly.'),0) AS decimal(24,6))',
] as $label => $expr) {
    $r = DB::selectOne("SELECT {$expr} AS amt {$base}", [$dateFrom, $dateTo, $salesmanId]);
    echo "{$label}: ".($r->amt ?? 0)."\n";
}

// Total extra discount allocated to ماركيت lines
$r2 = DB::selectOne("SELECT SUM(({$gross}) / NULLIF(inv.inv_gross,0) * inv.inv_extra) AS extra_alloc {$base}", [$dateFrom, $dateTo, $salesmanId]);
$r3 = DB::selectOne("SELECT SUM(({$gross}) / NULLIF(inv.inv_gross,0) * inv.inv_hdr) AS hdr_alloc {$base}", [$dateFrom, $dateTo, $salesmanId]);
echo "\nextra discount allocated to ماركيت: ".($r2->extra_alloc ?? 0)."\n";
echo "header discount allocated to ماركيت: ".($r3->hdr_alloc ?? 0)."\n";
echo "sum: ".((float)($r2->extra_alloc ?? 0) + (float)($r3->hdr_alloc ?? 0))."\n";
echo "\nIf exclude extra: ".(264981000 + (float)($r2->extra_alloc ?? 0))."\n";
echo "Target: 264981500\n";
