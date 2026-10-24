@extends('reports.layouts.app')
@section('title', 'PDA locations')

@section('content')
    <header class="page-header">
        <h1>Locations</h1>
        <p class="hint">Every salesman. Latest points come from the handheld log. Saved voucher locations are listed below.</p>
    </header>
    @include('reports.pda.partials.subnav')
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
        <p class="muted">No GPS point yet.</p>
    @endforelse
    <h2>Saved with vouchers</h2>
    @forelse ($stored as $row)
        <article class="pda-card">
            <strong>{{ $row->customer_name }} · {{ $row->voucher_type }}</strong>
            <div class="pda-meta">
                {{ $row->captured_at }}
                @if ($row->salesman_name) · {{ $row->salesman_name }}@endif
                <br>{{ $row->latitude }}, {{ $row->longitude }}
                @if ($row->accuracy !== null) · accuracy {{ display_number($row->accuracy, 0) }} m @endif
            </div>
            <a href="https://maps.google.com/?q={{ $row->latitude }},{{ $row->longitude }}" target="_blank" rel="noopener">Open map</a>
        </article>
    @empty
        <p class="muted">No voucher locations stored on this server yet.</p>
    @endforelse
@endsection
