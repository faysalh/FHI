@extends('reports.layouts.app')
@section('title', 'Unposted PDA')

@section('content')
    <header class="page-header">
        <h1>Unposted</h1>
        <p class="hint">These vouchers are waiting for the existing PDA sync page to post them into accounting.</p>
    </header>
    @include('reports.pda.partials.subnav')
    @forelse ($vouchers as $voucher)
        <article class="pda-card">
            <strong>{{ $voucher->fld_voucher_Type }} {{ $voucher->fld_no }} · {{ $voucher->fld_customer_name }}</strong>
            <div class="pda-meta">
                {{ $voucher->fld_date }}
                · Total {{ display_number($voucher->fld_total ?? 0, 2) }}
                · Paid {{ display_number($voucher->fld_paid ?? 0, 2) }}
                @if ($voucher->fld_notes) · {{ $voucher->fld_notes }}@endif
                @if ($voucher->fld_lat !== null)
                    <br>{{ $voucher->fld_lat }}, {{ $voucher->fld_long }}
                @endif
            </div>
        </article>
    @empty
        <p class="muted">Nothing is waiting to sync.</p>
    @endforelse
@endsection