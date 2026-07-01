<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$cases = [
    ['D52AED4F-9A96-460C-8D30-090BE3FBD17E', 'Bashdar', 147773250],
    ['4A974891-50A3-4872-986A-B84906B43540', 'Hawkar', 192575200],
    ['660E9168-7A24-4529-9CDE-F9B9B20C6913', 'Abd', 465432950],
    ['E8940EFE-9B37-4063-8162-748314D7B32F', 'Muhanad', 264981500],
];

$repo = app(SalesBySalesmanReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$m = new SalesDocumentMetricsSql();
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$invJoin = $m->invoiceDiscountJoinSql('t', 'inv');
$scope = $m->postedSalesScopeSql(false);
$lineNet = $m->lineNetAmountExpr('d');
$hdrOnly = $m->salesLineHeaderDiscountAmountExpr('d', 'inv');
$roundHdrLine = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24, 6))';

// Per-invoice round header discount, then sum (ERP may round once per invoice)
$invoiceHdrRound = "
    SELECT COALESCE(SUM(inv_amt), 0) FROM (
        SELECT CAST(ROUND(SUM({$hdrOnly}), 0) AS decimal(24, 6)) AS inv_amt
        FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invJoin}
        LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
        {$tierJoin}
        WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
          AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
          AND {$tierIdx} = 3
          AND COALESCE(inv.inv_hdr, 0) > 0
        GROUP BY t.fld_store_document_title_id
    ) x";

$noHdrSum = "
    SELECT COALESCE(SUM({$lineNet}), 0)
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
      AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
      AND {$tierIdx} = 3
      AND COALESCE(inv.inv_hdr, 0) = 0";

$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

echo "ماركيت rounding variants (June 2026)\n\n";

foreach ($cases as [$id, $name, $target]) {
    $current = (float) ($itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], [])->p3_amt ?? 0);
    $hdrPart = (float) (DB::selectOne($invoiceHdrRound, [$dateFrom, $dateTo, $id])->{''} ?? DB::selectOne("SELECT ({$invoiceHdrRound}) AS a", [$dateFrom, $dateTo, $id])->a ?? 0);
    // Fix query - use alias
    $hdrPart = (float) (DB::selectOne("SELECT ({$invoiceHdrRound}) AS a", [$dateFrom, $dateTo, $id])->a ?? 0);
    $noHdrPart = (float) (DB::selectOne("SELECT ({$noHdrSum}) AS a", [$dateFrom, $dateTo, $id])->a ?? 0);
    $hybridInvRound = $hdrPart + $noHdrPart;
    $lineRoundHdr = (float) (DB::selectOne("
        SELECT COALESCE(SUM({$roundHdrLine}), 0) AS a
        FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invJoin}
        LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
        {$tierJoin}
        WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
          AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
          AND {$tierIdx} = 3 AND COALESCE(inv.inv_hdr, 0) > 0
    ", [$dateFrom, $dateTo, $id])->a ?? 0);
    $hybridLineRound = $lineRoundHdr + $noHdrPart;

    echo "{$name}: target=".number_format($target, 0)."\n";
    echo "  current (line round hdr): ".number_format($current, 0)." diff ".($current - $target)."\n";
    echo "  invoice round hdr:        ".number_format($hybridInvRound, 0)." diff ".($hybridInvRound - $target)."\n";
    echo "  hdr parts: line={$lineRoundHdr} inv={$hdrPart} no_hdr={$noHdrPart}\n\n";
}
