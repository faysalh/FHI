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
$scope = $metrics->postedSalesScopeSql(false);

$labels = [1=>'وكيل',2=>'وكيل2',3=>'ماركيت',4=>'جملة',5=>'كي'];

function tiers(string $filter, array $bindings, string $tierIdx, string $tierJoin, string $lineAmount, string $invoiceJoin, string $scope, string $dateFrom, string $dateTo, array $labels): void {
    $sql = "
    SELECT {$tierIdx} AS t, SUM({$lineAmount}) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter}
    GROUP BY {$tierIdx}
    ORDER BY t
    ";
    $bindings = array_merge([$dateFrom, $dateTo], $bindings);
    echo $filter.PHP_EOL;
    $sum = 0;
    foreach (DB::select($sql, $bindings) as $row) {
        $t = (int)$row->t;
        if ($t === 0) { continue; }
        $amt = (float)$row->amt;
        $sum += $amt;
        echo '  '.($labels[$t]??$t).': '.number_format($amt, 3, '.', '').PHP_EOL;
    }
    echo '  TOTAL: '.number_format($sum, 3, '.', '').PHP_EOL.PHP_EOL;
}

tiers('AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)', [$salesmanId], $tierIdx, $tierJoin, $lineAmount, $invoiceJoin, $scope, $dateFrom, $dateTo, $labels);
tiers('AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)', [$salesmanId], $tierIdx, $tierJoin, $lineAmount, $invoiceJoin, $scope, $dateFrom, $dateTo, $labels);
tiers('AND (a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER) OR t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER))', [$salesmanId, $salesmanId], $tierIdx, $tierJoin, $lineAmount, $invoiceJoin, $scope, $dateFrom, $dateTo, $labels);

echo "User expects: كي=1669750, شبه جملة/جملة=268974700, ماركيت=465432950\n";
