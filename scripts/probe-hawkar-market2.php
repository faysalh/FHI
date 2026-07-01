<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\SalesDocumentMetricsSql;
use Illuminate\Support\Facades\DB;

$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';
$salesmanId = '4A974891-50A3-4872-986A-B84906B43540';

$repo = app(SalesBySalesmanReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$tierJoin = $repo->priceTierJoinSql();
$tierIdx = $repo->clientPriceTierIndexSql();
$metrics = new SalesDocumentMetricsSql();
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$scope = $metrics->postedSalesScopeSql(false);

$hdr = $metrics->salesLineAmountExpr('d', 'inv');
$hdrOnly = $metrics->salesLineHeaderDiscountAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$gross = $metrics->lineGrossAmountExpr('d');
$erpAmount = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');
$roundHdrLine = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24,6))';
$roundHdrBothLine = 'CAST(ROUND(('.$hdr.'), 0) AS decimal(24,6))';

$formulas = [
    'erp_current' => $erpAmount,
    'round_hdr_only_line' => $roundHdrLine,
    'round_hdr_both_line' => $roundHdrBothLine,
    'hdr_only_raw' => $hdrOnly,
    'hdr_both_raw' => $hdr,
    'line_net' => $lineNet,
    'gross' => $gross,
    'round_line_net' => 'CAST(ROUND(('.$lineNet.'), 0) AS decimal(24,6))',
    'invoice_round_hdr_only' => 'CAST(ROUND(SUM('.$hdrOnly.'), 0) AS decimal(24,6))',
];

$filter = 'AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)';

echo "Hawkar ماركيت June 2026\n\n";
$gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $salesmanId, [], null, [], []);
echo 'App p3_amt: '.($gt->p3_amt ?? 0)."\n";
$erpTarget = (float) ($gt->p3_amt ?? 0) - 162249;
echo "Implied ERP (app - 162249): {$erpTarget}\n\n";

foreach ($formulas as $name => $expr) {
    if (str_contains($name, 'invoice_round')) {
        $sql = "
        SELECT SUM(x.amt) AS amt FROM (
            SELECT {$expr} AS amt
            FROM dbo.tbl_store_document_detail d
            INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
            {$tierJoin}
            WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
              AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
              {$filter}
              AND {$tierIdx} = 3
            GROUP BY t.fld_store_document_title_id
        ) x";
    } else {
        $sql = "
        SELECT SUM({$expr}) AS amt
        FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invoiceJoin}
        LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
        {$tierJoin}
        WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
          {$filter}
          AND {$tierIdx} = 3";
    }
    $amt = (float) (DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId])->amt ?? 0);
    $diff = $amt - $erpTarget;
    $flag = abs($diff) < 0.01 ? ' <-- MATCH' : (abs($amt - (float)$gt->p3_amt) < 0.01 ? ' (app)' : '');
    echo sprintf("  %-24s %15s  diff_vs_erp %12s%s\n", $name, number_format($amt, 0, '.', ','), number_format($diff, 0, '.', ','), $flag);
}

// Hybrid: market uses line_net when no header discount, else hdr_only rounded
$hybrid = "(CASE WHEN COALESCE(inv.inv_hdr, 0) = 0 THEN {$lineNet} ELSE {$roundHdrLine} END)";
$sql = "SELECT SUM({$hybrid}) AS amt FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3";
$hybridAmt = (float) (DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId])->amt ?? 0);
echo sprintf("  %-24s %15s  diff_vs_erp %12s\n", 'hybrid_no_hdr=net', number_format($hybridAmt, 0, '.', ','), number_format($hybridAmt - $erpTarget, 0, '.', ','));

// Lines where tier index from account vs from line unit price differ
echo "\n--- Tier source probes ---\n";
$priceTierSql = $repo->clientPriceTierIndexSql();
// Check if unit price tier differs from account tier for market lines
$unitPriceTier = 'CAST(ROUND(CAST(d.fld_store_document_unit_price AS FLOAT), 0) AS int)';
// probably not right - need to read how tiers map to prices

// Clients with tier 3 but invoice uses different price column?
$row = DB::selectOne("
    SELECT COUNT(*) AS c, COALESCE(SUM({$erpAmount}),0) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3
      AND COALESCE(inv.inv_hdr, 0) > 0
", [$dateFrom, $dateTo, $salesmanId]);
echo "Market lines with header discount: count={$row->c} amt={$row->amt}\n";

$row2 = DB::selectOne("
    SELECT COUNT(*) AS c, COALESCE(SUM({$erpAmount}),0) AS amt
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3
      AND COALESCE(inv.inv_hdr, 0) = 0
", [$dateFrom, $dateTo, $salesmanId]);
echo "Market lines without header discount: count={$row2->c} amt={$row2->amt}\n";

// Sum where hdr_only rounded > line_net (overcount vs net)
$row3 = DB::selectOne("
    SELECT COALESCE(SUM({$erpAmount}),0) AS erp,
           COALESCE(SUM({$lineNet}),0) AS net,
           COALESCE(SUM({$roundHdrLine}),0) AS hdr_r
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3
      AND ({$roundHdrLine}) > ({$lineNet})
", [$dateFrom, $dateTo, $salesmanId]);
echo "Lines where round(hdr_only) > line_net: erp={$row3->erp} net={$row3->net} hdr_r={$row3->hdr_r} excess=".((float)$row3->hdr_r - (float)$row3->net)."\n";

$row4 = DB::selectOne("
    SELECT COALESCE(SUM({$erpAmount}),0) AS erp,
           COALESCE(SUM({$lineNet}),0) AS net
    FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3
      AND ({$roundHdrLine}) <= ({$lineNet})
", [$dateFrom, $dateTo, $salesmanId]);
echo "Lines where round(hdr_only) <= line_net: erp={$row4->erp} net={$row4->net}\n";

// Per-invoice: ERP might use MIN(line_net, round_hdr) or line_net when discount small?
$minExpr = "(CASE WHEN {$roundHdrLine} < {$lineNet} THEN {$roundHdrLine} ELSE {$lineNet} END)";
$sql = "SELECT SUM({$minExpr}) AS amt FROM dbo.tbl_store_document_detail d
    INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
    {$invoiceJoin}
    LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
    {$tierJoin}
    WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
      AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope}
      {$filter} AND {$tierIdx} = 3";
$minAmt = (float) (DB::selectOne($sql, [$dateFrom, $dateTo, $salesmanId])->amt ?? 0);
echo "\nmin(round_hdr, line_net) sum: {$minAmt} diff_vs_erp=".($minAmt - $erpTarget)."\n";

// line_net for all market (if ERP uses line net for market hawkar)
echo "line_net diff from app: ".((float)$gt->p3_amt - (float) DB::selectOne("SELECT SUM({$lineNet}) AS a FROM dbo.tbl_store_document_detail d INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref {$invoiceJoin} LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref {$tierJoin} WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ? AND ISNULL(t.fld_is_cancelled,0)=0 AND ISNULL(d.fld_is_cancelled,0)=0 {$scope} {$filter} AND {$tierIdx} = 3", [$dateFrom, $dateTo, $salesmanId])->a)."\n";
