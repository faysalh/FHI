{{--
    Table footer row for numeric grand totals.
    Required: $labelColspan, $grandTotals
    Optional: $trailingColspan (default 0), $showAmount, $showWeight, quantity/amount/weight key overrides via partial vars
--}}
@php
    $gt = $grandTotals ?? null;
    $qty = (float) ($quantity ?? ($gt?->units_sold ?? $gt?->quantity ?? $gt?->quantity_total ?? 0));
    $amt = isset($amount) ? (float) $amount : (float) ($gt?->amount ?? $gt?->invoice_amount ?? 0);
    $wgt = (float) ($weight ?? ($gt?->weight_total ?? 0));
    $showQuantity = $showQuantity ?? true;
    $showAmount = $showAmount ?? true;
    $showWeight = $showWeight ?? true;
    $trailingColspan = (int) ($trailingColspan ?? 0);
    $footerLabel = $footerLabel ?? 'Total (all matching filters)';
@endphp
@if (isset($grandTotals) || isset($quantity))
    <tfoot>
    <tr class="grand-total">
        <td colspan="{{ (int) $labelColspan }}"><strong>{{ $footerLabel }}</strong></td>
        @if ($showQuantity)
            <td class="num"><strong>{{ display_number($qty) }}</strong></td>
        @endif
        @if ($showAmount)
            <td class="num"><strong>{{ display_number($amt) }}</strong></td>
        @endif
        @if ($showWeight)
            <td class="num"><strong>{{ display_number($wgt, 1) }}</strong></td>
        @endif
        @if ($trailingColspan > 0)
            <td colspan="{{ $trailingColspan }}"></td>
        @endif
    </tr>
    </tfoot>
@endif
