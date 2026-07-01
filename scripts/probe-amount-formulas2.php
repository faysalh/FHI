<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$hdr = $metrics->salesLineAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$scope = $metrics->postedSalesScopeSql(false);

$formulas = [
    'hdr' => $hdr,
    'line_net' => $lineNet,
    'round_hdr_line' => "CAST(ROUND(({$hdr}), 0) AS decimal(24,6))",
    'round_line_net_line' => "CAST(ROUND(({$lineNet}), 0) AS decimal(24,6))",
    'floor_hdr_line' => "CAST(FLOOR(({$hdr})) AS decimal(24,6))",
];

$sqlBase = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN (
    SELECT fld_account_id_ref, MAX(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST([fld_price_group] AS NVARCHAR(100)))), N'') AS DECIMAL(24,6))) AS rp_tier_raw
    FROM dbo.tbl_accounting_account_details GROUP BY fld_account_id_ref
) AS rp_tier ON rp_tier.fld_account_id_ref = t.fld_account_id_ref
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
";

foreach ([3 => 'ماركيت', 4 => 'جملة', 5 => 'كي'] as $tier => $label) {
    echo "=== {$label} ===\n";
    foreach ($formulas as $name => $expr) {
        $sql = "SELECT SUM({$expr}) AS amt {$sqlBase}
            AND (CASE WHEN rp_tier.rp_tier_raw IS NULL THEN 0 ELSE CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int)+1 END) = {$tier}";
        $r = DB::selectOne($sql, [$salesmanId]);
        echo "  {$name}: ".($r->amt ?? 0)."\n";
    }
    $sql = "SELECT SUM({$hdr}) AS raw {$sqlBase}
        AND (CASE WHEN rp_tier.rp_tier_raw IS NULL THEN 0 ELSE CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int)+1 END) = {$tier}";
    $raw = (float) (DB::selectOne($sql, [$salesmanId])->raw ?? 0);
    echo "  round_total_hdr: ".round($raw)."\n";
}

echo "\nTargets: M=465432950 J=268974700 K=1669750\n";
