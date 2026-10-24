@extends('reports.layouts.app')
@section('title', $customer['name'])

@section('content')
    <header class="page-header">
        <h1>{{ $customer['name'] }}</h1>
        <p class="hint">
            @if ($customer['code'] !== ''){{ $customer['code'] }} · @endif
            Price group {{ $customer['price_group'] }} · {{ \App\Support\SalesItemPriceTiers::label((int) $customer['price_group']) }}
            @if ($customer['phone'] !== '') · {{ $customer['phone'] }}@endif
        </p>
    </header>
    @include('reports.pda.partials.subnav')
    <p class="pda-meta">
        {{ $customer['address'] }}
        @if ($customer['zone'] !== '' || $customer['route'] !== '')
            <br>{{ $customer['zone'] }} {{ $customer['route'] }}
        @endif
        <br>Price group {{ $customer['price_group'] }} · {{ \App\Support\SalesItemPriceTiers::label((int) $customer['price_group']) }}
        <br>Balance {{ display_number($customer['balance'], 2) }}
        @if ($customer['discount'] > 0) · Discount {{ display_number($customer['discount'], 2) }}%@endif
        @if ($customer['last_date'] !== '')
            <br>Last invoice {{ $customer['last_date'] }} · {{ display_number($customer['last_amount'], 2) }}
        @endif
    </p>
    @if ($customer['lat'] !== null && $customer['lng'] !== null && (float) $customer['lat'] !== 0.0)
        <p><a class="pda-btn" href="https://maps.google.com/?q={{ $customer['lat'] }},{{ $customer['lng'] }}" target="_blank" rel="noopener">Open map</a></p>
    @endif
    <div class="pda-sell">
        <form method="POST" action="{{ route('reports.pda.visits.store') }}">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $customer['id'] }}">
            <input type="hidden" name="next" value="sale">
            <button class="pda-btn pda-btn--primary pda-btn--sell" type="submit">Sell</button>
        </form>
        <div class="pda-sell__more">
            @foreach (['order' => 'Order', 'return' => 'Return', 'receipt' => 'Receipt'] as $next => $label)
                <form method="POST" action="{{ route('reports.pda.visits.store') }}">
                    @csrf
                    <input type="hidden" name="customer_id" value="{{ $customer['id'] }}">
                    <input type="hidden" name="next" value="{{ $next }}">
                    <button class="pda-btn" type="submit">{{ $label }}</button>
                </form>
            @endforeach
        </div>
    </div>
    <h2>Open invoices</h2>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>No</th>
                    <th>Total</th>
                    <th>Received</th>
                    <th>Remain</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $invoice)
                    <tr>
                        <td>{{ $invoice->fld_store_document_title_date ?? '' }}</td>
                        <td>{{ $invoice->fld_store_document_title_no ?? '' }}</td>
                        <td>{{ display_number($invoice->fld_store_document_title_total_amount ?? 0, 2) }}</td>
                        <td>{{ display_number($invoice->fld_received ?? 0, 2) }}</td>
                        <td>{{ display_number($invoice->Remain ?? $invoice->remain ?? 0, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No open invoices.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
