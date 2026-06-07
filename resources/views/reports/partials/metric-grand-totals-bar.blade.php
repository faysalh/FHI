{{-- Summary bar for quantity / amount / weight style metrics. Pass $grandTotals object and optional label keys. --}}
@php
    $gt = $grandTotals ?? null;
    $qty = (float) ($quantity ?? ($gt?->units_sold ?? $gt?->quantity ?? $gt?->quantity_total ?? 0));
    $amt = isset($amount) ? (float) $amount : (float) ($gt?->amount ?? $gt?->invoice_amount ?? 0);
    $wgt = (float) ($weight ?? ($gt?->weight_total ?? 0));
    $qtyLabel = $quantityLabel ?? 'Quantity (pcs)';
    $amtLabel = $amountLabel ?? 'Amount (IQD)';
    $wgtLabel = $weightLabel ?? 'Weight (kg)';
    $showQuantity = $showQuantity ?? true;
    $showAmount = $showAmount ?? true;
    $showWeight = $showWeight ?? true;
@endphp
@if (isset($grandTotals) || isset($quantity))
    <div class="totals-bar" role="region" aria-label="Report totals">
        @if ($showQuantity)
            <div class="total-item">
                <span>{{ $qtyLabel }}</span>
                <strong class="num">{{ display_number($qty) }}</strong>
            </div>
        @endif
        @if ($showAmount)
            <div class="total-item">
                <span>{{ $amtLabel }}</span>
                <strong class="num">{{ display_number($amt) }}</strong>
            </div>
        @endif
        @if ($showWeight)
            <div class="total-item">
                <span>{{ $wgtLabel }}</span>
                <strong class="num">{{ display_number($wgt, 1) }}</strong>
            </div>
        @endif
        @if (!empty($grandTotalsNote))
            <div class="muted" style="align-self:center;">{{ $grandTotalsNote }}</div>
        @endif
    </div>
@endif
