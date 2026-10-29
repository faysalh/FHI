@extends('reports.layouts.app')
@section('title', 'PDA locations')

@section('content')
    @php
        $filters = $filters ?? ['date_from' => '', 'date_to' => '', 'salesman_user_id' => '', 'client_name' => ''];
        $salesmen = $salesmen ?? [];
        $hasFilters = ($filters['date_from'] ?? '') !== ''
            || ($filters['date_to'] ?? '') !== ''
            || ($filters['salesman_user_id'] ?? '') !== ''
            || ($filters['client_name'] ?? '') !== '';
        $locationLimit = (int) ($locationLimit ?? 200);
    @endphp
    <header class="page-header">
        <h1>Locations</h1>
        <p class="hint">Latest handheld points filter by date and salesman. Client name filters the voucher locations below.</p>
    </header>
    @include('reports.pda.partials.subnav')

    <form id="pda-locations-filter-form" method="get" action="{{ route('reports.pda.locations') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                @include('reports.partials.quick-date-buttons')
                <div class="filters-grid">
                    <div>
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
                    </div>
                    <div>
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
                    </div>
                    <div>
                        <label for="salesman_user_id">Salesman</label>
                        <select id="salesman_user_id" name="salesman_user_id">
                            <option value="">All salesmen</option>
                            @foreach ($salesmen as $salesmanOption)
                                <option value="{{ $salesmanOption['user_id'] }}" @selected(($filters['salesman_user_id'] ?? '') === $salesmanOption['user_id'])>{{ $salesmanOption['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="client_name">Client name</label>
                        <input type="search" id="client_name" name="client_name" value="{{ $filters['client_name'] }}" placeholder="Customer name" autocomplete="off">
                        <p class="field-help">Matches the customer saved on a voucher.</p>
                    </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.pda.locations'])
                </div>
            </div>
        </details>
    </form>
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'pda-locations-filter-form'])

    <h2>Latest point</h2>
    @forelse ($points as $point)
        <article class="pda-card">
            <strong>{{ $point->fld_account_name ?? 'Salesman' }}</strong>
            <div class="pda-meta">
                {{ $point->fld_date ?? '' }}
                @if (!empty($point->fld_vehicle_name)) · {{ $point->fld_vehicle_name }}@endif
                <br>{{ $point->Lat ?? '' }}, {{ $point->Long ?? '' }}
            </div>
            @if (($point->Lat ?? null) !== null && ($point->Long ?? null) !== null)
                <a href="https://maps.google.com/?q={{ $point->Lat }},{{ $point->Long }}" target="_blank" rel="noopener">Open map</a>
            @endif
        </article>
    @empty
        <p class="muted">{{ $hasFilters ? 'No GPS point matches these filters.' : 'No GPS point yet.' }}</p>
    @endforelse
    <h2>Saved with vouchers</h2>
    @if (count($stored) >= $locationLimit)
        <p class="hint">Showing the latest {{ $locationLimit }} voucher locations. Narrow the date range to see a shorter list.</p>
    @endif
    @forelse ($stored as $row)
        <article class="pda-card">
            <strong>{{ $row->customer_name }} · {{ $row->voucher_type }}</strong>
            <div class="pda-meta">
                {{ $row->captured_at }}
                @if ($row->salesman_name) · {{ $row->salesman_name }}@endif
                <br>{{ $row->latitude }}, {{ $row->longitude }}
                @if (($row->accuracy ?? null) !== null) · accuracy {{ display_number($row->accuracy, 0) }} m @endif
            </div>
            <a href="https://maps.google.com/?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" rel="noopener">Open map</a>
        </article>
    @empty
        <p class="muted">{{ $hasFilters ? 'No voucher locations match these filters.' : 'No voucher locations stored yet.' }}</p>
    @endforelse
@endsection
