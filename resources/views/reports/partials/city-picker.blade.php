@php
    $pickerId = $pickerId ?? 'city';
    $cityOptions = $cityOptions ?? [];
    $selectedCities = $selectedCities ?? [];
    $note = $note ?? 'Add cities one at a time. Leave empty for all cities.';
    $disabled = ! empty($disabled);
@endphp
<script type="application/json" id="{{ $pickerId }}-city-options-json">@json($cityOptions)</script>
<div class="customer-picker" id="{{ $pickerId }}-city-picker">
    <div class="customer-chips" id="{{ $pickerId }}-city-chips" aria-live="polite"></div>
    <div id="{{ $pickerId }}-city-hidden-inputs"></div>
    <div class="customer-search-wrap">
        <label for="{{ $pickerId }}-city-search">Search city</label>
        <input type="text"
               id="{{ $pickerId }}-city-search"
               autocomplete="off"
               placeholder="Type a city name…"
               @disabled($disabled)>
        <ul class="customer-suggestions" id="{{ $pickerId }}-city-suggestions" role="listbox" aria-label="Matching cities"></ul>
    </div>
    @if ($note !== '')
        <p class="muted pie-city-picker-note">{{ $note }}</p>
    @endif
</div>
