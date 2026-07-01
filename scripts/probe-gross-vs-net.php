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
$net = $metrics->salesLineAmountExpr('d', 'inv');
$gross = $metrics->lineGrossAmountExpr('d');
$scope = $metrics->postedSalesScopeSql(false);
$labels = [3 => 'ماركيت', 4 => 'جملة', 5 => 'كي'];

$sql = "
SELECT {$tierIdx} AS t, SUM({$net}) AS net, SUM({$gross}) AS gross
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$tierIdx}
ORDER BY t
";
foreach (DB::select($sql, [$salesmanId]) as $row) {
    $t = (int) $row->t;
    if ($t === 0) {
        continue;
    }
    echo ($labels[$t] ?? $t).": net={$row->net} gross={$row->gross}\n";
}

echo "\nUser: ماركيت=465432950, جملة=268974700, كي=1669750\n";
