<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = 'E8940EFE-9B37-4063-8162-748314D7B32F';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$repo = app(SalesBySalesmanReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$scope = $metrics->postedSalesScopeSql(false);
$hdr = $metrics->salesLineAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$gross = $metrics->lineGrossAmountExpr('d');
$erp = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');

echo "All tiers (title salesman, erp formula):\n";
$sql = "
SELECT {$tierIdx} AS t, SUM(({$erp})) AS erp, SUM(({$hdr})) AS hdr, SUM(({$lineNet})) AS line_net, SUM(({$gross})) AS gross
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY {$tierIdx} ORDER BY t
";
$labels = [0=>'?',1=>'وكيل',2=>'وكيل2',3=>'ماركيت',4=>'جملة',5=>'كي'];
foreach (DB::select($sql, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo '  '.($labels[(int)$row->t]??$row->t)." erp={$row->erp} hdr={$row->hdr} line_net={$row->line_net}\n";
}

// Mixed-tier invoices
$sql2 = "
SELECT t.fld_store_document_title_id,
       SUM(CASE WHEN {$tierIdx}=3 THEN ({$erp}) ELSE 0 END) AS m_erp,
       SUM(CASE WHEN {$tierIdx}=3 THEN ({$hdr}) ELSE 0 END) AS m_hdr,
       SUM(CASE WHEN {$tierIdx}=3 THEN ({$lineNet}) ELSE 0 END) AS m_line_net,
       COUNT(DISTINCT {$tierIdx}) AS tier_count
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
GROUP BY t.fld_store_document_title_id
HAVING COUNT(DISTINCT {$tierIdx}) > 1 AND SUM(CASE WHEN {$tierIdx}=3 THEN 1 ELSE 0 END) > 0
";
$mixed = DB::select($sql2, [$dateFrom, $dateTo, $salesmanId]);
echo "\nMixed-tier invoices with ماركيت: ".count($mixed)."\n";

// ماركيت on mixed vs single tier invoices
$sql3 = "
SELECT
  CASE WHEN inv_tiers.cnt > 1 THEN 'mixed' ELSE 'single' END AS bucket,
  SUM(({$erp})) AS erp
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
INNER JOIN (
  SELECT d3.fld_store_document_title_id_ref AS tid, COUNT(DISTINCT {$tierIdx}) AS cnt
  FROM dbo.tbl_store_document_detail d3
  INNER JOIN dbo.tbl_store_document_titles t3 ON t3.fld_store_document_title_id = d3.fld_store_document_title_id_ref
  LEFT JOIN dbo.tbl_accounting_accounts a3 ON a3.fld_account_id = t3.fld_account_id_ref
  {$tierJoin}
  WHERE CAST(t3.fld_store_document_title_date AS date) BETWEEN ? AND ?
    AND ISNULL(t3.fld_is_cancelled,0)=0 AND ISNULL(d3.fld_is_cancelled,0)=0 {$scope}
    AND t3.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  GROUP BY d3.fld_store_document_title_id_ref
) AS inv_tiers ON inv_tiers.tid = t.fld_store_document_title_id
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
GROUP BY CASE WHEN inv_tiers.cnt > 1 THEN 'mixed' ELSE 'single' END
";
// fix tier join alias in subquery - tier join uses 'a' and 'rp_tier' - need same join on a3

// Simpler: compare formulas that differ by ~500
$candidates = [
    'CAST(ROUND(SUM('.$hdr.'), 0) AS decimal(24,6))' => 'round total hdr',
    'SUM(CAST(ROUND('.$gross.', 0) AS decimal(24,6)))' => 'sum round gross',
    'SUM('.$lineNet.') - 7000 + 500' => 'n/a',
];

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

foreach ([
    'SUM('.$erp.')' => 'erp sum',
    'CAST(ROUND(SUM('.$hdr.'), 0) AS decimal(24,6))' => 'round(sum hdr)',
    'SUM(CAST(ROUND('.$hdr.', 0) AS decimal(24,6)))' => 'sum(round hdr)',
    'SUM('.$hdr.')' => 'sum hdr raw',
    'SUM('.$lineNet.')' => 'sum line_net',
    'SUM(CAST(ROUND('.$gross.', 0) AS decimal(24,6)))' => 'sum round gross',
    'SUM('.$gross.')' => 'sum gross',
    'SUM(CAST(CEILING('.$hdr.') AS decimal(24,6)))' => 'sum ceiling hdr',
    'SUM(CAST(FLOOR('.$hdr.' + 0.5) AS decimal(24,6)))' => 'sum floor hdr+0.5',
] as $expr => $label) {
    $r = DB::selectOne("SELECT {$expr} AS amt {$base}", [$dateFrom, $dateTo, $salesmanId]);
    echo "{$label}: ".($r->amt ?? 0)."\n";
}
echo "\nTarget if +500: 264981500\n";

// Excluded lines: cancelled, qty=0, non-S type with ماركيت tier
$sqlEx = "
SELECT COALESCE(t.fld_type_alias,N'') AS typ,
       ISNULL(t.fld_is_cancelled,0) AS tc, ISNULL(d.fld_is_cancelled,0) AS dc,
       CAST(d.fld_store_document_quantity AS decimal(24,6)) AS qty,
       SUM({$gross}) AS gross
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
  AND {$tierIdx} = 3
  AND (COALESCE(t.fld_type_alias,N'') <> N'S' OR ISNULL(t.fld_is_cancelled,0)<>0 OR ISNULL(d.fld_is_cancelled,0)<>0
       OR CAST(d.fld_store_document_quantity AS decimal(24,6)) <= 0)
GROUP BY COALESCE(t.fld_type_alias,N''), ISNULL(t.fld_is_cancelled,0), ISNULL(d.fld_is_cancelled,0), CAST(d.fld_store_document_quantity AS decimal(24,6))
";
echo "\nExcluded scope lines (ماركيت):\n";
foreach (DB::select($sqlEx, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  typ={$row->typ} tc={$row->tc} dc={$row->dc} qty={$row->qty} gross={$row->gross}\n";
}
