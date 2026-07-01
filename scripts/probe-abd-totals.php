<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$salesmanId = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$lineAmount = $metrics->salesLineAmountExpr('d', 'inv');
$gross = 'CAST(d.fld_store_document_quantity AS decimal(24,6)) * CAST(d.fld_store_document_unit_price AS decimal(24,6))';
$scopeS = $metrics->postedSalesScopeSql(false);
$scopeAll = ''; // no type/qty filter

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0
  AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
";

foreach ([
    'Posted S qty>0 (current)' => $scopeS,
    'No scope (all types)' => $scopeAll,
    'Type S only' => " AND COALESCE(t.fld_type_alias, N'') = N'S' ",
    'Qty>0 only' => ' AND CAST(d.fld_store_document_quantity AS decimal(24,6)) > 0 ',
] as $label => $extra) {
    $sql = "SELECT SUM({$lineAmount}) AS net, SUM({$gross}) AS gross, COUNT(*) AS lines {$base} {$extra}";
    $r = DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId]);
    echo "{$label}: net={$r->net} gross={$r->gross} lines={$r->lines}\n";
}

// Document types breakdown
$sql = "SELECT COALESCE(t.fld_type_alias, N'(null)') AS typ, SUM({$lineAmount}) AS net {$base} GROUP BY COALESCE(t.fld_type_alias, N'(null)') ORDER BY net DESC";
echo "\nBy document type:\n";
foreach (DB::select($sql, [$dateFrom, $dateTo, $salesmanId]) as $row) {
    echo "  {$row->typ}: {$row->net}\n";
}

// Title date vs other date columns?
$dateCols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_store_document_titles' AND COLUMN_NAME LIKE '%date%'");
echo "\nTitle date columns: ";
foreach ($dateCols as $c) { echo $c->COLUMN_NAME.' '; }
echo "\n";

// Try fld_posting_date if exists
foreach ($dateCols as $c) {
    $col = $c->COLUMN_NAME;
    if ($col === 'fld_store_document_title_date') {
        continue;
    }
    $sql = "SELECT SUM({$lineAmount}) AS net FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invoiceJoin}
        LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
        WHERE CAST(t.[{$col}] AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scopeS}
          AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)";
    try {
        $r = DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId]);
        echo "Date col {$col}: net={$r->net}\n";
    } catch (Throwable $e) {
        echo "Date col {$col}: error\n";
    }
}

echo "\nUser tier sum: ".(465432950+268974700+1669750)."\n";
echo "Our tier sum (M+J+K): ".(447751700.0246+267484650.0026+1669750)."\n";
