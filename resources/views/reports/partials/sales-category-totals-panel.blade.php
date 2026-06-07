{{--
    Grand total bar plus per-category sums (sales report, include-items mode).
    Requires: $grandTotals, $categoryTotalsList
--}}
@php
    $categoryTotalsList = $categoryTotalsList ?? [];
    $showQuantity = $showQuantity ?? true;
    $showAmount = $showAmount ?? true;
    $showWeight = $showWeight ?? true;
@endphp
<div class="sales-totals-with-categories">
    @include('reports.partials.metric-grand-totals-bar', [
        'grandTotals' => $grandTotals ?? null,
        'grandTotalsNote' => $grandTotalsNote ?? null,
        'showQuantity' => $showQuantity,
        'showAmount' => $showAmount,
        'showWeight' => $showWeight,
    ])
    @if ($categoryTotalsList !== [])
        <div class="category-totals-panel" role="region" aria-label="Totals by category">
            <div class="category-totals-panel__title">By category</div>
            <table class="category-totals-summary">
                <thead>
                <tr>
                    <th>Category</th>
                    @if ($showQuantity)
                        <th class="num">Quantity (pcs)</th>
                    @endif
                    @if ($showAmount)
                        <th class="num">Amount (IQD)</th>
                    @endif
                    @if ($showWeight)
                        <th class="num">Weight (kg)</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach ($categoryTotalsList as $catRow)
                    <tr>
                        <td>{{ $catRow['category'] ?? '' }}</td>
                        @if ($showQuantity)
                            <td class="num">{{ display_number($catRow['units_sold'] ?? 0) }}</td>
                        @endif
                        @if ($showAmount)
                            <td class="num">{{ display_number($catRow['amount'] ?? 0) }}</td>
                        @endif
                        @if ($showWeight)
                            <td class="num">{{ display_number($catRow['weight_total'] ?? 0, 1) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
