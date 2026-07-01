@php
    $f = $filters ?? [];
    $access = $deliveriesAccess ?? \App\Support\DeliveriesReportAccess::full();
    $exclude = $excludeFilterKeys ?? [];
@endphp
@if (! in_array('date_from', $exclude, true))
    <input type="hidden" name="date_from" value="{{ $f['date_from'] ?? '' }}">
@endif
@if (! in_array('date_to', $exclude, true))
    <input type="hidden" name="date_to" value="{{ $f['date_to'] ?? '' }}">
@endif
@if (! in_array('per_page', $exclude, true))
    <input type="hidden" name="per_page" value="{{ $f['per_page'] ?? 250 }}">
@endif
@if (! in_array('storage', $exclude, true))
    <input type="hidden" name="storage" value="{{ $f['storage'] ?? '' }}">
@endif
@if (! in_array('delivery_status', $exclude, true))
    <input type="hidden" name="delivery_status" value="{{ $f['delivery_status'] ?? '' }}">
@endif
@if (! in_array('team_id', $exclude, true))
    <input type="hidden" name="team_id" value="{{ $f['team_id'] ?? '' }}">
@endif
@if (! in_array('team_date', $exclude, true))
    <input type="hidden" name="team_date" value="{{ $f['team_date'] ?? '' }}">
@endif
@if (! in_array('invoice_search', $exclude, true))
    <input type="hidden" name="invoice_search" value="{{ $f['invoice_search'] ?? '' }}">
@endif
@if (! in_array('include_amount', $exclude, true) && !empty($f['include_amount']))
    <input type="hidden" name="include_amount" value="1">
@endif
@if (! in_array('include_weight', $exclude, true) && !empty($f['include_weight']))
    <input type="hidden" name="include_weight" value="1">
@endif
@if ($access->canFilterCity && ! in_array('cities', $exclude, true))
    @foreach (($f['cities'] ?? []) as $city)
        <input type="hidden" name="cities[]" value="{{ $city }}">
    @endforeach
@endif
@if ($access->canFilterSalesman && ! in_array('salesman_ids', $exclude, true))
    @foreach (($f['salesman_ids'] ?? []) as $salesmanId)
        <input type="hidden" name="salesman_ids[]" value="{{ $salesmanId }}">
    @endforeach
@endif
