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
$gross = $metrics->lineGrossAmountExpr('d');
$hdr = $metrics->salesLineAmountExpr('d', 'inv');
$hdrAlloc = "({$gross}) - ({$hdr})"; // header+extra discount allocated to line
$scope = $metrics->postedSalesScopeSql(false);

$tierCase = "(CASE WHEN rp_tier.rp_tier_raw IS NULL THEN 0 ELSE CAST(ROUND(CAST(rp_tier.rp_tier_raw AS FLOAT),0) AS int)+1 END)";

$sql = "
SELECT {$tierCase} AS tier,
       SUM({$hdrAlloc}) AS hdr_alloc,
       SUM({$gross}) AS gross,
       SUM({$hdr}) AS net_hdr
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN (
    SELECT fld_account_id_ref, MAX(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST([fld_price_group] AS NVARCHAR(100)))), N'') AS DECIMAL(24,6))) AS rp_tier_raw
    FROM dbo.tbl_accounting_account_details GROUP BY fld_account_id_ref
) AS rp_tier ON rp_tier.fld_account_id_ref = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$tierCase}
ORDER BY tier
";
$labels = [3=>'M',4=>'J',5=>'K'];
foreach (DB::select($sql, [$salesmanId]) as $row) {
    $t = (int)$row->tier;
    echo ($labels[$t]??$t)." hdr_alloc={$row->hdr_alloc} gross={$row->gross} net_hdr={$row->net_hdr}\n";
}

// Hybrid: tier 3 use round(hdr), tier 4+ use line_net
$lineNet = $metrics->lineNetAmountExpr('d');
$hybrid = "(CASE WHEN {$tierCase} = 3 THEN CAST(ROUND(({$hdr}), 0) AS decimal(24,6)) ELSE {$lineNet} END)";
$sql2 = "SELECT {$tierCase} AS tier, SUM({$hybrid}) AS amt
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN (
    SELECT fld_account_id_ref, MAX(TRY_CAST(NULLIF(LTRIM(RTRIM(CAST([fld_price_group] AS NVARCHAR(100)))), N'') AS DECIMAL(24,6))) AS rp_tier_raw
    FROM dbo.tbl_accounting_account_details GROUP BY fld_account_id_ref
) AS rp_tier ON rp_tier.fld_account_id_ref = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN '2026-06-01' AND '2026-06-30'
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$tierCase} ORDER BY tier";
echo "\nHybrid (M=round(hdr), else line_net):\n";
foreach (DB::select($sql2, [$salesmanId]) as $row) {
    $t = (int)$row->tier;
    if ($t===0) continue;
    echo ($labels[$t]??$t).": {$row->amt}\n";
}
