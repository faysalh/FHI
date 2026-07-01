<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$repo = app(SalesBySalesmanReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$scope = " AND COALESCE(t.fld_type_alias, N'') = N'S' AND CAST(d.fld_store_document_quantity AS decimal(24, 6)) > 0 ";
$gross = 'CAST(d.fld_store_document_quantity AS decimal(24, 6)) * CAST(d.fld_store_document_unit_price AS decimal(24, 6))';
$invoiceJoin = (new SalesDocumentMetricsSql())->invoiceDiscountJoinSql('t', 'inv');
$lineNet = (new SalesDocumentMetricsSql())->lineNetAmountExpr('d');
$hdrBoth = '('.$gross.') - (('.$gross.') / NULLIF(CAST(inv.inv_gross AS decimal(24, 6)), 0))
    * (CAST(COALESCE(inv.inv_hdr, 0) AS decimal(24, 6)) + CAST(COALESCE(inv.inv_extra, 0) AS decimal(24, 6)))';
$hdrOnly = '('.$gross.') - (('.$gross.') / NULLIF(CAST(inv.inv_gross AS decimal(24, 6)), 0))
    * CAST(COALESCE(inv.inv_hdr, 0) AS decimal(24, 6))';

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = ?
";

foreach ([
    '660E9168-7A24-4529-9CDE-F9B9B20C6913' => ['Abd', 3 => 465432950, 4 => 268974700, 5 => 1669750],
    'E8940EFE-9B37-4063-8162-748314D7B32F' => ['Muhanad', 3 => 264981500],
] as $id => $info) {
    $name = array_shift($info);
    echo "\n=== {$name} ===\n";
    foreach ([3, 4, 5] as $tier) {
        if (! isset($info[$tier]) && $name === 'Muhanad' && $tier !== 3) {
            continue;
        }
        $exprBoth = $tier === 3 ? "SUM(CAST(ROUND(({$hdrBoth}), 0) AS decimal(24, 6)))" : "SUM({$lineNet})";
        $exprHdr = $tier === 3 ? "SUM(CAST(ROUND(({$hdrOnly}), 0) AS decimal(24, 6)))" : "SUM({$lineNet})";
        $both = (float) (DB::selectOne("SELECT {$exprBoth} AS a {$base}", [$id, $tier])->a ?? 0);
        $hdr = (float) (DB::selectOne("SELECT {$exprHdr} AS a {$base}", [$id, $tier])->a ?? 0);
        $target = $info[$tier] ?? '—';
        echo "  tier {$tier}: both={$both} hdr_only={$hdr} target={$target}\n";
    }
}
