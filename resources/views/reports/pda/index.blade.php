@extends('reports.layouts.app')
@section('title', 'PDA')

@section('content')
    <header class="page-header">
        <h1>PDA</h1>
        <p class="hint">{{ $salesman['name'] }}@if ($companyName !== '') · {{ $companyName }}@endif</p>
    </header>
    @include('reports.pda.partials.subnav')
    <form method="GET" action="{{ route('reports.pda.index') }}" class="pda-search">
        <input type="search" name="q" value="{{ $search }}" placeholder="Search name, code, or phone" aria-label="Search customers">
        <button class="pda-btn" type="submit">Search</button>
    </form>
    @if ($customerCount > count($customers))
        <p class="hint">Showing {{ count($customers) }} of {{ $customerCount }}. Search to narrow the list.</p>
    @endif
    @forelse ($customers as $customer)
        <a class="pda-card" href="{{ route('reports.pda.customers.show', ['customer' => $customer['id']]) }}">
            <strong>{{ $customer['name'] !== '' ? $customer['name'] : 'Customer' }}</strong>
            <div class="pda-meta">
                @if ($customer['code'] !== ''){{ $customer['code'] }} · @endif
                @if ($customer['phone'] !== ''){{ $customer['phone'] }} · @endif
                Balance {{ display_number($customer['balance'], 2) }}
                @if ($customer['route'] !== '') · {{ $customer['route'] }}@endif
            </div>
        </a>
    @empty
        <p class="muted">No customers matched.</p>
    @endforelse
@endsection
