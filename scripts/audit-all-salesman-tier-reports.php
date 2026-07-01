<?php

declare(strict_types=1);

/**
 * Audit all salesmen: ERP tier amount rules + title vs account salesman filter.
 *
 * Usage: php scripts/audit-all-salesman-tier-reports.php [date_from] [date_to]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\SalesDocumentMetricsSql;
use App\Support\SalesItemPriceTiers;
use Illuminate\Support\Facades\DB;

$dateFrom = $argv[1] ?? '2026-06-01';
$dateTo = $argv[2] ?? '2026-06-30';

$visits = app(VisitsReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$salesmanRepo = app(SalesBySalesmanReportRepository::class);
$metrics = new SalesDocumentMetricsSql();

$tierJoin = $salesmanRepo->priceTierJoinSql();
$tierIdx = $salesmanRepo->clientPriceTierIndexSql();
$scope = $metrics->postedSalesScopeSql(false);
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$erpExpr = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');
$hdrBoth = 'CAST(ROUND(('.$metrics->salesLineAmountExpr('d', 'inv').'), 0) AS decimal(24, 6))';
$hdrOnly = 'CAST(ROUND(('.$metrics->salesLineHeaderDiscountAmountExpr('d', 'inv').'), 0) AS decimal(24, 6))';
$lineNet = $metrics->lineNetAmountExpr('d');

$titleBase = "
FROM dbo.tbl_store_document_detail d
INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
{$invoiceJoin}
LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
{$tierJoin}
WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
  AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
  AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)";

$accountBase = str_replace('t.fld_sales_man_id_ref', 'a.fld_sales_man_id_ref', $titleBase);

$labelToTier = array_flip(array_map('strval', SalesItemPriceTiers::LABELS));

$salesmen = $visits->getSalesmanOptions();
echo "Salesman tier report audit ({$dateFrom} .. {$dateTo}) — ".count($salesmen)." salesmen\n\n";

$titleAccountGaps = [];
$marketExtraGaps = [];
$crosscheckFails = [];
$repoFails = [];
$noSales = [];

foreach ($salesmen as $sm) {
    $id = (string) ($sm['id'] ?? '');
    $name = (string) ($sm['name'] ?? '');
    if ($id === '') {
        continue;
    }

    $titleTotal = (float) (DB::selectOne("SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$titleBase}", [$dateFrom, $dateTo, $id])->a ?? 0);
    $accountTotal = (float) (DB::selectOne("SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$accountBase}", [$dateFrom, $dateTo, $id])->a ?? 0);

    if ($titleTotal < 0.01 && $accountTotal < 0.01) {
        $noSales[] = $name;
        continue;
    }

    $gap = round($accountTotal - $titleTotal, 2);
    if (abs($gap) > 0.01) {
        $titleAccountGaps[] = compact('name', 'titleTotal', 'accountTotal', 'gap');
    }

    $marketErp = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$erpExpr}), 0) AS a {$titleBase} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->a ?? 0);
    $marketOld = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$hdrBoth}), 0) AS a {$titleBase} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->a ?? 0);
    $marketHdr = (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$hdrOnly}), 0) AS a {$titleBase} AND {$tierIdx} = 3",
        [$dateFrom, $dateTo, $id]
    )->a ?? 0);

    $extraGap = round($marketOld - $marketHdr, 2);
    if (abs($extraGap) > 0.01) {
        $marketExtraGaps[] = compact('name', 'marketOld', 'marketHdr', 'extraGap');
    }
    if (abs($marketErp - $marketHdr) > 0.01) {
        $repoFails[] = "{$name}: app ماركيت {$marketErp} != hdr-only {$marketHdr}";
    }

    $gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], []);
    if (abs((float) ($gt->total_amt ?? 0) - $titleTotal) > 0.01) {
        $repoFails[] = "{$name}: item total ".($gt->total_amt ?? 0)." != sql {$titleTotal}";
    }

    $byGroup = [];
    foreach (app(SalesBySalesmanReportRepository::class)->exportRows($dateFrom, $dateTo, $id) as $row) {
        $g = trim((string) ($row->client_price_group ?? '')) ?: '(empty)';
        $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) ($row->amount ?? 0);
    }
    $tierSm = [];
    foreach ($byGroup as $label => $amt) {
        if ($label === '(empty)') {
            $tierSm[0] = ($tierSm[0] ?? 0) + $amt;
        } elseif (isset($labelToTier[$label])) {
            $t = (int) $labelToTier[$label];
            $tierSm[$t] = ($tierSm[$t] ?? 0) + $amt;
        }
    }
    for ($t = 1; $t <= 5; $t++) {
        if (abs((float) ($gt->{'p'.$t.'_amt'} ?? 0) - (float) ($tierSm[$t] ?? 0)) > 0.01) {
            $crosscheckFails[] = "{$name} tier {$t}";
        }
    }
}

echo "1) Using invoice title salesman (current app) — account-vs-title gaps (would be wrong if we used account):\n";
if ($titleAccountGaps === []) {
    echo "   (no sales or all match)\n";
} else {
    usort($titleAccountGaps, static fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
    foreach ($titleAccountGaps as $r) {
        echo sprintf("   %s: title %s | account %s | gap %+s\n", $r['name'], number_format($r['titleTotal'], 0), number_format($r['accountTotal'], 0), number_format($r['gap'], 0));
    }
}

echo "\n2) ماركيت: fixed extra-discount gap (old hdr+extra minus current hdr-only):\n";
if ($marketExtraGaps === []) {
    echo "   None — extra discount did not affect ماركيت in this period.\n";
} else {
    usort($marketExtraGaps, static fn ($a, $b) => abs($b['extraGap']) <=> abs($a['extraGap']));
    foreach ($marketExtraGaps as $r) {
        echo sprintf("   %s: was %s → now %s (fixed %+s)\n", $r['name'], number_format($r['marketOld'], 0), number_format($r['marketHdr'], 0), number_format($r['extraGap'], 0));
    }
}

echo "\n3) Repository / SQL consistency:\n";
echo $repoFails === [] ? "   OK — all salesmen match ERP formula.\n" : implode("\n", array_map(static fn ($l) => "   {$l}", $repoFails))."\n";

echo "\n4) Sales by salesman vs Sales by item (per tier):\n";
echo $crosscheckFails === [] ? "   OK — all match.\n" : '   FAIL: '.implode(', ', $crosscheckFails)."\n";

echo "\nNo sales in period: ".implode(', ', $noSales)."\n";
echo "\nApp rules (all salesmen): title fld_sales_man_id_ref; ماركيت = line net if no header discount else hdr-only rounded per invoice; other tiers = line net.\n";
