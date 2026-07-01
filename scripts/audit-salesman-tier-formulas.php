<?php

declare(strict_types=1);

/**
 * Full salesman tier formula audit — all salesmen, all price tiers.
 *
 * One unified ERP rule set (not per-salesman):
 *   - Salesman filter: invoice title fld_sales_man_id_ref
 *   - Tiers 1,2,4,5: line net (qty × price after line %)
 *   - Tier 3 ماركيت: line net when inv_hdr = 0; else header discount only, rounded per line
 *
 * Usage:
 *   php scripts/audit-salesman-tier-formulas.php [date_from] [date_to]
 *   php scripts/audit-salesman-tier-formulas.php 2026-06-01 2026-06-30 --verbose
 *   php scripts/audit-salesman-tier-formulas.php 2026-06-01 2026-06-30 --targets=scripts/tier-targets.json
 *
 * Optional targets JSON: { "salesman-uuid": { "3": 123456, "4": 789 }, ... }
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
$verbose = in_array('--verbose', $argv, true);
$targetsFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--targets=')) {
        $targetsFile = substr($arg, strlen('--targets='));
    }
}

/** @var array<string, array<string, float>> $erpTargets */
$erpTargets = [];
if ($targetsFile !== null && is_file($targetsFile)) {
    $decoded = json_decode((string) file_get_contents($targetsFile), true);
    if (is_array($decoded)) {
        $erpTargets = $decoded;
    }
}

$visits = app(VisitsReportRepository::class);
$itemRepo = app(SalesByItemReportRepository::class);
$salesmanRepo = app(SalesBySalesmanReportRepository::class);
$metrics = new SalesDocumentMetricsSql();

$tierJoin = $salesmanRepo->priceTierJoinSql();
$tierIdx = $salesmanRepo->clientPriceTierIndexSql();
$scope = $metrics->postedSalesScopeSql(false);
$invoiceJoin = $metrics->invoiceDiscountJoinSql('t', 'inv');
$lineNet = $metrics->lineNetAmountExpr('d');
$hdrOnly = $metrics->salesLineHeaderDiscountAmountExpr('d', 'inv');
$hdrBoth = $metrics->salesLineAmountExpr('d', 'inv');
$roundHdrOnly = 'CAST(ROUND(('.$hdrOnly.'), 0) AS decimal(24, 6))';
$roundHdrBoth = 'CAST(ROUND(('.$hdrBoth.'), 0) AS decimal(24, 6))';

// Current app (unified) formula
$appExpr = $metrics->salesmanTierReportLineAmountExpr($tierIdx, 'd', 'inv');

// Legacy / alternate formulas for diagnosis only
$formulaVariants = [
    'app_current' => $appExpr,
    'market_hdr_only_always' => '(CASE WHEN ('.$tierIdx.') = 3 THEN '.$roundHdrOnly.' ELSE '.$lineNet.' END)',
    'market_hdr_both' => '(CASE WHEN ('.$tierIdx.') = 3 THEN '.$roundHdrBoth.' ELSE '.$lineNet.' END)',
    'all_line_net' => $lineNet,
    'all_gross' => $metrics->lineGrossAmountExpr('d'),
];

$invoiceHdrRoundSub = "
    SELECT COALESCE(SUM(inv_amt), 0) FROM (
        SELECT CAST(ROUND(SUM({$hdrOnly}), 0) AS decimal(24, 6)) AS inv_amt
        FROM dbo.tbl_store_document_detail d
        INNER JOIN dbo.tbl_store_document_titles t ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
        {$invoiceJoin}
        LEFT JOIN dbo.tbl_accounting_accounts a ON a.fld_account_id = t.fld_account_id_ref
        {$tierJoin}
        WHERE CAST(t.fld_store_document_title_date AS date) BETWEEN ? AND ?
          AND ISNULL(t.fld_is_cancelled, 0) = 0 AND ISNULL(d.fld_is_cancelled, 0) = 0 {$scope}
          AND t.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)
          AND {$tierIdx} = 3 AND COALESCE(inv.inv_hdr, 0) > 0
        GROUP BY t.fld_store_document_title_id
    ) x";

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
$tierLabels = SalesItemPriceTiers::LABELS;

function tierSum(string $expr, string $base, string $tierIdx, int $tier, string $from, string $to, string $id): float
{
    return (float) (DB::selectOne(
        "SELECT COALESCE(SUM({$expr}), 0) AS a {$base} AND {$tierIdx} = ?",
        [$from, $to, $id, $tier]
    )->a ?? 0);
}

$salesmen = $visits->getSalesmanOptions();
echo "=== Salesman tier formula audit ===\n";
echo "Period: {$dateFrom} .. {$dateTo}\n";
echo 'Salesmen: '.count($salesmen)."\n";
echo "Unified rules (same for everyone):\n";
echo "  • Filter: t.fld_sales_man_id_ref (invoice salesman)\n";
echo "  • Tiers 1,2,4,5: line net\n";
echo "  • Tier 3 ماركيت: line net if inv_hdr=0; else ROUND(header discount only per line)\n\n";

$noSales = [];
$titleAccountGaps = [];
$repoItemGaps = [];
$crosscheckFails = [];
$erpMismatches = [];
$roundingOnly = [];
$needsDifferentFormula = [];

foreach ($salesmen as $sm) {
    $id = (string) ($sm['id'] ?? '');
    $name = (string) ($sm['name'] ?? '');
    if ($id === '') {
        continue;
    }

    $titleTotal = (float) (DB::selectOne("SELECT COALESCE(SUM({$appExpr}), 0) AS a {$titleBase}", [$dateFrom, $dateTo, $id])->a ?? 0);
    $accountTotal = (float) (DB::selectOne("SELECT COALESCE(SUM({$appExpr}), 0) AS a {$accountBase}", [$dateFrom, $dateTo, $id])->a ?? 0);

    if ($titleTotal < 0.01 && $accountTotal < 0.01) {
        $noSales[] = $name;
        continue;
    }

    if (abs($accountTotal - $titleTotal) > 0.01) {
        $titleAccountGaps[] = ['name' => $name, 'gap' => $accountTotal - $titleTotal];
    }

    $gt = $itemRepo->getGrandTotals($dateFrom, $dateTo, $id, [], null, [], []);
    if (abs((float) ($gt->total_amt ?? 0) - $titleTotal) > 0.01) {
        $repoItemGaps[] = "{$name}: repo ".number_format((float) $gt->total_amt, 0).' vs sql '.number_format($titleTotal, 0);
    }

    // Sales by salesman cross-check per tier
    $byGroup = [];
    foreach ($salesmanRepo->exportRows($dateFrom, $dateTo, $id) as $row) {
        $g = trim((string) ($row->client_price_group ?? '')) ?: '(empty)';
        $byGroup[$g] = ($byGroup[$g] ?? 0) + (float) ($row->amount ?? 0);
    }
    $tierSm = [];
    foreach ($byGroup as $label => $amt) {
        if (isset($labelToTier[$label])) {
            $t = (int) $labelToTier[$label];
            $tierSm[$t] = ($tierSm[$t] ?? 0) + $amt;
        }
    }

    $smErpRows = [];
    for ($tier = 1; $tier <= 5; $tier++) {
        $appAmt = (float) ($gt->{'p'.$tier.'_amt'} ?? 0);
        $sqlAmt = tierSum($appExpr, $titleBase, $tierIdx, $tier, $dateFrom, $dateTo, $id);
        $smAmt = (float) ($tierSm[$tier] ?? 0);

        if (abs($appAmt - $sqlAmt) > 0.01) {
            $repoItemGaps[] = "{$name} tier {$tier}: item {$appAmt} != sql {$sqlAmt}";
        }
        if (abs($appAmt - $smAmt) > 0.01) {
            $crosscheckFails[] = "{$name} tier {$tier} (item {$appAmt} vs salesman {$smAmt})";
        }

        // ماركيت diagnostic: how much does hybrid save vs hdr-only?
        if ($tier === 3 && $verbose) {
            $hdrOnlyAmt = tierSum($formulaVariants['market_hdr_only_always'], $titleBase, $tierIdx, 3, $dateFrom, $dateTo, $id);
            $hybridGap = $hdrOnlyAmt - $appAmt;
            if (abs($hybridGap) > 0.01) {
                echo sprintf("  [%s] ماركيت hybrid saves %s vs hdr-only (app=%s)\n", $name, number_format($hybridGap, 0, '.', ','), number_format($appAmt, 0, '.', ','));
            }
        }

        // ERP targets if provided
        $targetKey = (string) $tier;
        if (isset($erpTargets[$id][$targetKey]) || isset($erpTargets[$id][$tier])) {
            $target = (float) ($erpTargets[$id][$targetKey] ?? $erpTargets[$id][$tier]);
            $diff = $appAmt - $target;
            $absDiff = abs($diff);
            if ($absDiff <= 1.01) {
                $roundingOnly[] = ['name' => $name, 'tier' => $tier, 'app' => $appAmt, 'erp' => $target, 'diff' => $diff];
            } elseif ($absDiff > 0.01) {
                $erpMismatches[] = ['name' => $name, 'tier' => $tier, 'label' => $tierLabels[$tier] ?? '', 'app' => $appAmt, 'erp' => $target, 'diff' => $diff];
                // Which alternate formula is closest?
                $best = ['variant' => 'app_current', 'diff' => $absDiff];
                foreach ($formulaVariants as $vName => $vExpr) {
                    if ($vName === 'app_current') {
                        continue;
                    }
                    $vAmt = tierSum($vExpr, $titleBase, $tierIdx, $tier, $dateFrom, $dateTo, $id);
                    $vDiff = abs($vAmt - $target);
                    if ($vDiff < $best['diff'] - 0.01) {
                        $best = ['variant' => $vName, 'diff' => $vDiff, 'amt' => $vAmt];
                    }
                }
                if ($tier === 3 && abs($absDiff) <= 25) {
                    $noHdrPart = (float) (DB::selectOne("
                        SELECT COALESCE(SUM({$lineNet}), 0) AS a {$titleBase}
                          AND {$tierIdx} = 3 AND COALESCE(inv.inv_hdr, 0) = 0
                    ", [$dateFrom, $dateTo, $id])->a ?? 0);
                    $invHdrPart = (float) (DB::selectOne("SELECT ({$invoiceHdrRoundSub}) AS a", [$dateFrom, $dateTo, $id])->a ?? 0);
                    $invRoundAmt = $noHdrPart + $invHdrPart;
                    $invDiff = abs($invRoundAmt - $target);
                    if ($invDiff < $best['diff'] - 0.01) {
                        $best = ['variant' => 'invoice_round_hdr (diagnostic)', 'diff' => $invDiff, 'amt' => $invRoundAmt];
                    }
                }
                if ($best['variant'] !== 'app_current' && ($best['diff'] ?? $absDiff) > 1.01) {
                    $needsDifferentFormula[] = "{$name} tier {$tier} ({$tierLabels[$tier]}): ERP closest to «{$best['variant']}» (diff ".number_format($best['diff'], 0).')';
                } elseif ($best['variant'] !== 'app_current' && ($best['diff'] ?? 0) <= 1.01) {
                    $roundingOnly[] = ['name' => $name, 'tier' => $tier, 'app' => $appAmt, 'erp' => $target, 'diff' => $diff, 'note' => "ERP matches {$best['variant']} (line-round vs invoice-round)"];
                }
            }
        }

        $smErpRows[] = [
            'tier' => $tier,
            'label' => $tierLabels[$tier] ?? '',
            'app' => $appAmt,
            'sql' => $sqlAmt,
            'salesman' => $smAmt,
        ];
    }

    if ($verbose) {
        echo "\n--- {$name} ---\n";
        foreach ($smErpRows as $r) {
            if ($r['app'] < 0.01) {
                continue;
            }
            echo sprintf("  tier %d %-8s app=%15s sql=%15s salesman=%15s\n",
                $r['tier'], "({$r['label']})", number_format($r['app'], 0, '.', ','), number_format($r['sql'], 0, '.', ','), number_format($r['salesman'], 0, '.', ','));
        }
    }
}

echo "=== Summary ===\n\n";

echo "1) No sales: ".($noSales === [] ? '(none)' : implode(', ', $noSales))."\n\n";

echo "2) Title vs account salesman (should use title; gaps show mis-attribution if account were used):\n";
if ($titleAccountGaps === []) {
    echo "   (none with sales)\n";
} else {
    usort($titleAccountGaps, static fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
    foreach ($titleAccountGaps as $r) {
        echo sprintf("   %s: %+s IQD (account − title)\n", $r['name'], number_format($r['gap'], 0, '.', ','));
    }
}
echo "\n";

echo "3) Repository / SQL / cross-report consistency:\n";
if ($repoItemGaps === [] && $crosscheckFails === []) {
    echo "   OK — Sales by item, SQL, and Sales by salesman all match (unified formula).\n";
} else {
    foreach ($repoItemGaps as $line) {
        echo "   {$line}\n";
    }
    foreach ($crosscheckFails as $line) {
        echo "   CROSS: {$line}\n";
    }
}
echo "\n";

echo "4) Per-salesman formula overrides needed:\n";
if ($needsDifferentFormula === []) {
    echo "   None — one unified rule set fits all salesmen/tiers checked.\n";
} else {
    foreach ($needsDifferentFormula as $line) {
        echo "   {$line}\n";
    }
}
echo "\n";

if ($erpTargets !== []) {
    echo "5) ERP target comparison:\n";
    if ($erpMismatches === []) {
        echo "   All targets match within 1 IQD or exactly.\n";
    } else {
        foreach ($erpMismatches as $r) {
            echo sprintf("   %s tier %d (%s): app=%s ERP=%s diff=%+s\n",
                $r['name'], $r['tier'], $r['label'], number_format($r['app'], 0, '.', ','), number_format($r['erp'], 0, '.', ','), number_format($r['diff'], 0, '.', ','));
        }
    }
    if ($roundingOnly !== []) {
        echo "\n   Within 1 IQD of ERP (line-round vs invoice-round on header-discount invoices):\n";
        foreach ($roundingOnly as $r) {
            $note = isset($r['note']) ? " — {$r['note']}" : '';
            echo sprintf("   %s tier %d: app=%s ERP=%s (%+s)%s\n",
                $r['name'], $r['tier'], number_format($r['app'], 0, '.', ','), number_format($r['erp'], 0, '.', ','), number_format($r['diff'], 0, '.', ','), $note);
        }
    }
} else {
    echo "5) ERP targets: not supplied. Pass --targets=path/to.json to compare against ERP numbers.\n";
    echo "   Sample: scripts/tier-targets-june-2026.sample.json\n";
    echo "   JSON shape: { \"<salesman-uuid>\": { \"3\": 123456789, \"4\": ... }, ... }\n";
    echo "   Per-salesman 1 IQD gaps on ماركيت: php scripts/probe-tier-rounding-1iqd.php <uuid> [from] [to] [erp_target]\n";
}

echo "\n6) ماركيت hybrid impact (all salesmen with sales):\n";
$hybridHits = [];
foreach ($salesmen as $sm) {
    $id = (string) ($sm['id'] ?? '');
    $name = (string) ($sm['name'] ?? '');
    if ($id === '') {
        continue;
    }
    $appM = tierSum($appExpr, $titleBase, $tierIdx, 3, $dateFrom, $dateTo, $id);
    if ($appM < 0.01) {
        continue;
    }
    $hdrM = tierSum($formulaVariants['market_hdr_only_always'], $titleBase, $tierIdx, 3, $dateFrom, $dateTo, $id);
    $gap = $hdrM - $appM;
    if (abs($gap) > 0.01) {
        $hybridHits[] = ['name' => $name, 'gap' => $gap, 'app' => $appM];
    }
}
if ($hybridHits === []) {
    echo "   No ماركيت lines benefited from hybrid (no-hdr → line net) rule.\n";
} else {
    usort($hybridHits, static fn ($a, $b) => abs($b['gap']) <=> abs($a['gap']));
    foreach ($hybridHits as $r) {
        echo sprintf("   %s: hdr-only was %+s higher → app=%s\n", $r['name'], number_format($r['gap'], 0, '.', ','), number_format($r['app'], 0, '.', ','));
    }
}

echo "\nDone.\n";
