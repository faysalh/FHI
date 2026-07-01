<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$itemRepo = app(App\Repositories\SalesByItemReportRepository::class);

$cases = [
    ['660E9168-7A24-4529-9CDE-F9B9B20C6913', 'Abd', [3 => 465432950, 4 => 268974700, 5 => 1669750]],
    ['E8940EFE-9B37-4063-8162-748314D7B32F', 'Muhanad', [3 => 264981500]],
    ['4A974891-50A3-4872-986A-B84906B43540', 'Hawkar', [3 => 192575200]],
    ['D52AED4F-9A96-460C-8D30-090BE3FBD17E', 'Bashdar', [3 => 147773250]],
];

foreach ($cases as [$id, $name, $targets]) {
    $gt = $itemRepo->getGrandTotals('2026-06-01', '2026-06-30', $id, [], null, [], []);
    echo "=== {$name} ===\n";
    foreach ($targets as $tier => $target) {
        $amt = (float) ($gt->{'p'.$tier.'_amt'} ?? 0);
        $ok = abs($amt - $target) < 0.01 ? 'OK' : (abs($amt - $target) <= 1.01 ? 'OK (~1 IQD)' : 'MISMATCH');
        echo "  tier {$tier}: {$amt} (target {$target}) {$ok}\n";
    }
}
