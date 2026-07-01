<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Repositories\SalesByItemReportRepository;
use App\Repositories\SalesBySalesmanReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Support\SalesItemPriceTiers;

$dateFrom = '2026-06-01';
$dateTo = '2026-06-30';

$visits = app(VisitsReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$salesmanRepo = app(SalesBySalesmanReportRepository::class);

$labelToTier = [];
foreach (SalesItemPriceTiers::LABELS as $tier => $label) {
    $labelToTier[$label] = $tier;
}

echo "Cross-check: Sales by salesman (per client) vs Sales by item (tier totals)\n";
echo "Period: {$dateFrom} .. {$dateTo}\n\n";

$mismatches = [];
$noSales = [];

foreach ($visits->getSalesmanOptions() as $sm) {
    $id = (string) ($sm['id'] ?? '');
    $name = (string) ($sm['name'] ?? '');
    if ($id === '') {
        continue;
    }

    $byGroup = [];
    foreach ($salesmanRepo->exportRows($dateFrom, $dateTo, $id) as $row) {
        $g = trim((string) ($row->client_price_group ?? ''));
        if ($g === '') {
            $g = '(empty)';
        }
        $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) ($row->amount ?? 0);
    }

    $gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], []);
    $totalItem = (float) ($gt->total_amt ?? 0);
    $totalSalesman = array_sum($byGroup);

    if ($totalItem < 0.01 && $totalSalesman < 0.01) {
        $noSales[] = $name;
        continue;
    }

    $tierFromSalesman = [];
    foreach ($byGroup as $label => $amt) {
        if ($label === '(empty)') {
            $tierFromSalesman[0] = ($tierFromSalesman[0] ?? 0) + $amt;
            continue;
        }
        $tier = $labelToTier[$label] ?? null;
        if ($tier === null) {
            $tierFromSalesman[-1] = ($tierFromSalesman[-1] ?? 0) + $amt;
            continue;
        }
        $tierFromSalesman[$tier] = ($tierFromSalesman[$tier] ?? 0) + $amt;
    }

    $ok = true;
    $details = [];
    for ($t = 1; $t <= 5; $t++) {
        $itemAmt = (float) ($gt->{'p'.$t.'_amt'} ?? 0);
        $smAmt = (float) ($tierFromSalesman[$t] ?? 0);
        if (abs($itemAmt - $smAmt) > 0.01) {
            $ok = false;
            $details[] = "p{$t}: item={$itemAmt} salesman={$smAmt}";
        }
    }
    $unmatchedItem = (float) ($gt->unmatched_amt ?? 0);
    $unmatchedSm = (float) ($tierFromSalesman[0] ?? 0);
    if (abs($unmatchedItem - $unmatchedSm) > 0.01) {
        $ok = false;
        $details[] = "unmatched: item={$unmatchedItem} salesman={$unmatchedSm}";
    }
    if (abs($totalItem - $totalSalesman) > 0.01) {
        $ok = false;
        $details[] = "total: item={$totalItem} salesman={$totalSalesman}";
    }

    if (! $ok) {
        $mismatches[] = ['name' => $name, 'details' => $details];
    }
}

if ($mismatches === []) {
    echo "All salesmen with June sales: Sales by salesman and Sales by item totals MATCH.\n";
} else {
    echo "MISMATCHES:\n";
    foreach ($mismatches as $m) {
        echo "  {$m['name']}: ".implode('; ', $m['details'])."\n";
    }
}

echo "\nSalesmen with no June sales: ".implode(', ', $noSales)."\n";
