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
$scope = $metrics->postedSalesScopeSql(false);

$titleCols = DB::select("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='tbl_store_document_titles' AND (COLUMN_NAME LIKE '%person%' OR COLUMN_NAME LIKE '%sales%' OR COLUMN_NAME LIKE '%man%')");
echo "Title columns:\n";
foreach ($titleCols as $c) { echo '  '.$c->COLUMN_NAME.PHP_EOL; }

// Filter by fld_person_id_ref if exists
foreach ($titleCols as $c) {
    if (! str_contains(strtolower($c->COLUMN_NAME), 'id')) {
        continue;
    }
    $col = $c->COLUMN_NAME;
    $sql = "SELECT SUM({$lineAmount}) AS amt FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invoiceJoin}
        WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
          AND t.[{$col}] = CAST(? AS UNIQUEIDENTIFIER)";
    try {
        $r = DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId]);
        echo "{$col} filter: amt=".($r->amt ?? 'null').PHP_EOL;
    } catch (Throwable $e) {
        // skip
    }
}

// fld_person_name like عبد
$sql = "SELECT SUM({$lineAmount}) AS amt FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      AND t.fld_person_name LIKE N'%عبد%'";
try {
    $r = DB::selectOne($sql, [$dateFrom, $dateTo]);
    echo "fld_person_name LIKE عبد: amt=".($r->amt ?? 'null').PHP_EOL;
} catch (Throwable $e) {
    echo 'person_name error'.PHP_EOL;
}
