<?php

declare(strict_types=1);

namespace App\Repositories\Concerns;

use App\Support\SalesDocumentMetricsSql;

trait UsesPostedSalesDocumentMetrics
{
    private ?SalesDocumentMetricsSql $postedSalesMetricsSql = null;

    protected function postedSalesMetrics(): SalesDocumentMetricsSql
    {
        return $this->postedSalesMetricsSql ??= new SalesDocumentMetricsSql;
    }

    /**
     * @return array{
     *     postedSalesScopeSql: string,
     *     invoiceJoin: string,
     *     lineQtyExpr: string,
     *     lineAmountExpr: string,
     *     lineNetAmountExpr: string,
     *     lineWeightExpr: string,
     *     weightSubquery: string,
     *     weightSub: string
     * }
     */
    protected function postedSalesQueryContext(string $weightAlias = 'w'): array
    {
        $metrics = $this->postedSalesMetrics();
        $fragments = $metrics->metricFragments($weightAlias);

        return [
            'postedSalesScopeSql' => $metrics->postedSalesScopeSql(false),
            'invoiceJoin' => $fragments['invoiceJoin'],
            'lineQtyExpr' => $fragments['lineQty'],
            'lineAmountExpr' => $fragments['lineAmount'],
            'lineNetAmountExpr' => $fragments['lineNetAmount'],
            'lineWeightExpr' => $fragments['lineWeight'],
            'weightSubquery' => $fragments['weightSubquery'],
            'weightSub' => $fragments['weightSubquery'],
        ];
    }
}
