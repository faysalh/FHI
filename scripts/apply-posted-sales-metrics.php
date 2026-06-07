<?php

declare(strict_types=1);

/**
 * Apply posted-sales metric SQL (type S, invoice discounts) across sales repositories.
 */

$root = dirname(__DIR__);

$grossQty = 'SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6)))';
$grossAmt = 'SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                )';
$grossWt = 'SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                )';
$grossWtS = 'SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(s.fld_weight, 0) AS float)
                )';

$coalesceGrossQty = 'COALESCE(SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))), 0)';
$coalesceGrossAmt = 'COALESCE(SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ), 0)';
$coalesceGrossWt = 'COALESCE(SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ), 0)';
$coalesceGrossWtS = 'COALESCE(SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(s.fld_weight, 0) AS float)
                ), 0)';

$files = [
    $root.'/app/Repositories/CitiesReportRepository.php',
    $root.'/app/Repositories/ComparisonReportRepository.php',
    $root.'/app/Repositories/SalesBySalesmanReportRepository.php',
    $root.'/app/Repositories/SalesByItemAverageReportRepository.php',
];

foreach ($files as $path) {
    $f = file_get_contents($path);
    if ($f === false) {
        echo "Skip missing {$path}\n";
        continue;
    }

    if (! str_contains($f, 'UsesPostedSalesDocumentMetrics')) {
        $f = str_replace(
            "namespace App\Repositories;\n\n",
            "namespace App\Repositories;\n\nuse App\Repositories\Concerns\UsesPostedSalesDocumentMetrics;\n\n",
            $f
        );
        $f = preg_replace(
            '/(class \w+\n\{)/',
            "$1\n    use UsesPostedSalesDocumentMetrics;\n",
            $f,
            1
        ) ?? $f;
    }

    if (! str_contains($f, 'postedSalesScopeSql')) {
        $f = str_replace(
            'AND ISNULL(d.fld_is_cancelled, 0) = 0',
            'AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}',
            $f
        );
    }

    if (! str_contains($f, '$postedSalesScopeSql =')) {
        $f = preg_replace(
            '/(public function \w+\([^)]*\)[^{]*\{)/',
            "$1\n        \$postedSalesScopeSql = \$this->postedSalesMetrics()->postedSalesScopeSql(false);\n",
            $f,
            1
        ) ?? $f;
    }

    if (! str_contains($f, '{$invoiceJoin}')) {
        $f = preg_replace(
            '/(ON t\.fld_store_document_title_id = d\.fld_store_document_title_id_ref)\s*\n(\s*(?!{\$invoiceJoin}))/',
            "$1\n            {\$invoiceJoin}\n$2",
            $f
        ) ?? $f;
    }

    $replacements = [
        $grossQty => 'SUM({$lineQtyExpr})',
        $grossAmt => 'SUM({$lineAmountExpr})',
        $grossWt => 'SUM({$lineWeightExpr})',
        $grossWtS => 'SUM({$lineWeightExpr})',
        $coalesceGrossQty => 'COALESCE(SUM({$lineQtyExpr}), 0)',
        $coalesceGrossAmt => 'COALESCE(SUM({$lineAmountExpr}), 0)',
        $coalesceGrossWt => 'COALESCE(SUM({$lineWeightExpr}), 0)',
        $coalesceGrossWtS => 'COALESCE(SUM({$lineWeightExpr}), 0)',
        'SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS units_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(w.fld_weight, 0) AS float)
                ) AS weight_total' => 'SUM({$lineQtyExpr}) AS units_sold,
                SUM({$lineAmountExpr}) AS amount,
                SUM({$lineWeightExpr}) AS weight_total',
        'SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_sold,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount' => 'SUM({$lineQtyExpr}) AS quantity_sold,
                SUM({$lineAmountExpr}) AS amount',
        'SUM(CAST(d.fld_store_document_quantity AS decimal(24, 6))) AS quantity_total,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(d.fld_store_document_unit_price AS decimal(24, 6))
                ) AS amount_total,
                SUM(
                    CAST(d.fld_store_document_quantity AS decimal(24, 6))
                    * CAST(COALESCE(s.fld_weight, 0) AS float)
                ) AS weight_total' => 'SUM({$lineQtyExpr}) AS quantity_total,
                SUM({$lineAmountExpr}) AS amount_total,
                SUM({$lineWeightExpr}) AS weight_total',
    ];

    foreach ($replacements as $from => $to) {
        $f = str_replace($from, $to, $f);
    }

    file_put_contents($path, $f);
    $remaining = substr_count($f, 'fld_store_document_unit_price');
    echo basename($path).": done, unit_price refs left: {$remaining}\n";
}

// SalesBySalesman: special baseFrom patch
$path = $root.'/app/Repositories/SalesBySalesmanReportRepository.php';
$f = file_get_contents($path);
if ($f !== false && ! str_contains($f, '{$invoiceJoin}')) {
    $f = str_replace(
        'ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            LEFT JOIN '.self::ACCOUNTS,
        'ON t.fld_store_document_title_id = d.fld_store_document_title_id_ref
            {$invoiceJoin}
            LEFT JOIN '.self::ACCOUNTS,
        $f
    );
    $f = str_replace(
        'AND ISNULL(d.fld_is_cancelled, 0) = 0
              AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)',
        'AND ISNULL(d.fld_is_cancelled, 0) = 0
              {$postedSalesScopeSql}
              AND a.fld_sales_man_id_ref = CAST(? AS UNIQUEIDENTIFIER)',
        $f
    );
    if (! str_contains($f, 'private function clientDetailBaseFrom')) {
        // add scope variable in methods - use lazy in baseFrom
    }
    $f = str_replace(
        'private function clientDetailBaseFrom(string $tierJoin): string
    {
        return \'',
        'private function clientDetailBaseFrom(string $tierJoin): string
    {
        $postedSalesScopeSql = $this->postedSalesMetrics()->postedSalesScopeSql(false);
        $sqlMetrics = $this->postedSalesMetrics()->metricFragments(\'w\');
        $invoiceJoin = $sqlMetrics[\'invoiceJoin\'];
        $lineQtyExpr = $sqlMetrics[\'lineQty\'];
        $lineAmountExpr = $sqlMetrics[\'lineAmountExpr\'];

        return \'',
        $f
    );
    file_put_contents($path, $f);
    echo "SalesBySalesmanReportRepository: baseFrom patched\n";
}

echo "Finished.\n";
