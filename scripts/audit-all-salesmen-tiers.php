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

$visits = app(VisitsReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$salesmanRepo = app(SalesBySalesmanReportRepository::class);
$metrics = new SalesDocumentMetricsSql();

$tierJoin = $salesmanRepo->priceTierJoinSql();
$tierIdx = $salesmanRepo->clientPriceTierIndexSql();
$scope = $metrics->postedSalesScopeSql(false);
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$erpExpr = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');

$gross = $metrics->lineGrossAmountExpr('d');
$hdrBoth = $metrics->salesLineAmountExpr('d', 'inv');
$hdrOnly = $metrics->salesLineHeaderDiscountAmountExpr('d', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$oldMarket = 'CAST(ROUND(('.$hdrBoth.'), 0) AS decimal(24, 6))';

$base = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
";

function sumExpr(string $expr, string $base, string $from, string $to, string $id, ?int $tier = null): float
{
    $tierSql = $tier !== null ? ' AND '.app(SalesBySalesmanReportRepository::class)->clientPriceTierIndexSql().' = '.$tier : '';
    $sql = "SELECT COALESCE(SUM({$expr}), 0) AS amt {$base} {$tierSql}";
    return (float) (DB::selectOne($sql, [$from, $to, $id])->amt ?? 0);
}

$salesmen = $visits->getSalesmanOptions();
echo 'Auditing '.count($salesmen)." salesmen ({$dateFrom} .. {$dateTo})\n\n";

$issues = [];
$appMismatch = [];
$oldFormulaGap = [];

foreach ($salesmen as $sm) {
    $id = (string) ($sm['id'] ?? '');
    $name = (string) ($sm['name'] ?? '');
    if ($id === '') {
        continue;
    }

    $erpTotal = sumExpr($erpExpr, $base, $dateFrom, $dateTo, $id);
    $accountTotal = 0.0;
    $accountSql = str_replace('t.fld_sales_man_id_ref', 'a.fld_sales_man_id_ref', $base);
    $accountTotal = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$erpExpr}), 0) AS amt {$accountSql}",
        [$dateFrom, $dateTo, $id]
    )->amt ?? 0);

    $titleAccountGap = round($accountTotal - $erpTotal, 2);
    if (abs($titleAccountGap) > 0.01) {
        $issues[] = [
            'type' => 'title_vs_account',
            'name' => $name,
            'id' => $id,
            'title' => $erpTotal,
            'account' => $accountTotal,
            'gap' => $titleAccountGap,
        ];
    }

    $marketErp = sumExpr($erpExpr, $base, $dateFrom, $dateTo, $id, 3);
    $marketOld = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$oldMarket}), 0) AS amt {$base} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->amt ?? 0);
    $marketHdrOnly = (float) (DB::selectOne(
        "SELECT COALESCE(SUM(CAST(ROUND(({$hdrOnly}), 0) AS decimal(24, 6))), 0) AS amt {$base} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->amt ?? 0);
    $marketLineNet = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$lineNet}), 0) AS amt {$base} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->amt ?? 0);

    $extraGap = round($marketOld - $marketHdrOnly, 2);
    if (abs($extraGap) > 0.01) {
        $oldFormulaGap[] = [
            'name' => $name,
            'id' => $id,
            'old_both_discount' => $marketOld,
            'hdr_only' => $marketHdrOnly,
            'extra_discount_gap' => $extraGap,
        ];
    }

    if (abs($marketErp - $marketHdrOnly) > 0.01) {
        $appMismatch[] = [
            'name' => $name,
            'erp_expr' => $marketErp,
            'hdr_only' => $marketHdrOnly,
        ];
    }

    $gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], []);
    $appMarket = (float) ($gt->p3_amt ?? 0);
    if (abs($appMarket - $marketErp) > 0.01) {
        $appMismatch[] = [
            'name' => $name,
            'issue' => 'repository_vs_sql',
            'app' => $appMarket,
            'sql' => $marketErp,
        ];
    }
}

echo "=== Title vs account salesman (should use title; gap = account - title) ===\n";
if ($issues === []) {
    echo "  None with sales in period, or all match.\n";
} else {
    usort($issues, static fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
    foreach ($issues as $row) {
        if (abs($row['gap']) < 0.01) {
            continue;
        }
        echo sprintf(
            "  %s: title=%s account=%s gap=%s\n",
            $row['name'],
            number_format($row['title'], 0, '.', ','),
            number_format($row['account'], 0, '.', ','),
            number_format($row['gap'], 0, '.', ',')
        );
    }
}

echo "\n=== ماركيت: old (hdr+extra) vs current (hdr only) ===\n";
if ($oldFormulaGap === []) {
    echo "  No ماركيت sales, or extra discount never allocated to ماركيت lines.\n";
} else {
    usort($oldFormulaGap, static fn ($a, $b) => abs($b['extra_discount_gap']) <=> abs($a['extra_discount_gap']));
    foreach ($oldFormulaGap as $row) {
        if (abs($row['extra_discount_gap']) < 0.01) {
            continue;
        }
        echo sprintf(
            "  %s: was %s now %s (fixed %+s)\n",
            $row['name'],
            number_format($row['old_both_discount'], 0, '.', ','),
            number_format($row['hdr_only'], 0, '.', ','),
            number_format($row['extra_discount_gap'], 0, '.', ',')
        );
    }
}

echo "\n=== App repository mismatches ===\n";
if ($appMismatch === []) {
    echo "  All salesmen: getGrandTotals matches SQL erp formula.\n";
} else {
    foreach ($appMismatch as $row) {
        echo '  '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    }
}

// Per-tier formula check for all salesmen with any sales
echo "\n=== Non-ماركيت tiers: erp should equal line_net ===\n";
$jumlaMismatch = 0;
foreach ($salesmen as $sm) {
    $id = (string) ($sm['id'] ?? '');
    if ($id === '') {
        continue;
    }
    foreach ([4, 5] as $tier) {
        $erp = sumExpr($erpExpr, $base, $dateFrom, $dateTo, $id, $tier);
        $net = (float) (DB::selectOne(
            "SELECT COALESCE(SUM({$lineNet}), 0) AS amt {$base} AND {$tierIdx} = {$tier}",
            [$dateFrom, $dateTo, $id]
        )->amt ?? 0);
        if ($erp > 0 && abs($erp - $net) > 0.01) {
            $jumlaMismatch++;
            echo "  {$sm['name']} tier {$tier}: erp={$erp} line_net={$net}\n";
        }
    }
}
if ($jumlaMismatch === 0) {
    echo "  All OK for جملة and كي.\n";
}

echo "\nDone.\n";
