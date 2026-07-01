<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$repo = app(SalesBySalesmanReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$scope = $metrics->postedSalesScopeSql(false);

$exprs = [
    'header_discount_aware' => $metrics->salesLineAmountExpr('d', 'inv'),
    'line_pct_net' => $metrics->lineNetAmountExpr('d'),
    'gross' => $metrics->lineGrossAmountExpr('d'),
    'rounded_line_gross' => 'CAST(ROUND(CAST(d.fld_store_document_quantity AS float) * CAST(d.fld_store_document_unit_price AS float), 0) AS decimal(24,6))',
    'rounded_line_net' => 'CAST(ROUND(('.$metrics->salesLineAmountExpr('d', 'inv').'), 0) AS decimal(24,6))',
];

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 4
";

foreach ($exprs as $label => $expr) {
    $sql = "SELECT SUM({$expr}) AS amt {$base}";
    $r = DB::selectOne($sql, [$salesmanId]);
    echo "جملة {$label}: ".($r->amt ?? 0).PHP_EOL;
}

// Same for ماركيت tier 3
foreach ($exprs as $label => $expr) {
    $sql = "SELECT SUM({$expr}) AS amt {$base}";
    $sql = str_replace('= 4', '= 3', $sql);
    $r = DB::selectOne($sql, [$salesmanId]);
    echo "ماركيت {$label}: ".($r->amt ?? 0).PHP_EOL;
}

echo "\nTargets: ماركيت=465432950, جملة=268974700\n";
