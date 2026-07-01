@php
    $f = $filters ?? [];
    $access = $deliveriesAccess ?? \App\Support\DeliveriesReportAccess::full();
    $parts = [];
    $parts[] = ($f['date_from'] ?? '').' → '.($f['date_to'] ?? '');
    if (! $access->canFilterStorage && ($f['storage'] ?? '') !== '') {
        $parts[] = 'Storage: '.$f['storage'];
    }
    if ($access->canFilterStatus && ($f['delivery_status'] ?? '') !== '') {
        $parts[] = 'Status: '.str_replace('_', ' ', (string) $f['delivery_status']);
    }
    if ($access->canFilterSalesman && ! empty($f['salesman_ids'])) {
        $parts[] = count($f['salesman_ids']).' salesman filter(s)';
    }
    if (($f['invoice_search'] ?? '') !== '') {
        $parts[] = 'Invoice: '.$f['invoice_search'];
    }
@endphp
@if (count($parts) > 0)
    <p class="muted deliveries-active-filters">Report filters: {{ implode(' · ', $parts) }}</p>
@endif
