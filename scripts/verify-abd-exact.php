<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = '660E9168-7A24-4529-9CDE-F9B9B20C6913';
$itemRepo = $app->make(App\Repositories\SalesByItemReportRepository::class);
$salesmanRepo = $app->make(App\Repositories\SalesBySalesmanReportRepository::class);

$gt = $itemRepo->getGrandTotals('2026-06-01', '2026-06-30', $id, [], null, []);
$labels = [3 => 'ماركيت', 4 => 'جملة', 5 => 'كي'];

echo "Sales by item tier amounts:\n";
foreach ([3, 4, 5] as $t) {
    $amt = (float) ($gt->{'p'.$t.'_amt'} ?? 0);
    echo '  '.$labels[$t].': '.$amt.PHP_EOL;
}

$byGroup = [];
foreach ($salesmanRepo->exportRows('2026-06-01', '2026-06-30', $id) as $row) {
    $g = (string) ($row->client_price_group ?? '');
    $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) ($row->amount ?? 0);
}
echo "\nSales by salesman by price group:\n";
foreach ($byGroup as $g => $amt) {
    echo "  {$g}: {$amt}\n";
}

echo "\nExpected: ماركيت=465432950, جملة=268974700, كي=1669750\n";
