@extends('reports.layouts.app')
@section('title', 'Today PDA')

@section('content')
    @php
        $count = count($vouchers);
        $total = 0.0;
        foreach ($vouchers as $voucher) {
            $total += (float) ($voucher->fld_total ?? 0);
        }
    @endphp
    <header class="page-header">
        <h1>Today</h1>
        <p class="hint">Vouchers you created on {{ $dayLabel }}. Canceled invoices are left out. Other salespeople are not included.</p>
    </header>
    @include('reports.pda.partials.subnav')
    <p class="pda-meta">{{ $count }} {{ $count === 1 ? 'voucher' : 'vouchers' }} · Total {{ display_number($total, 2) }}</p>
    @forelse ($vouchers as $voucher)
        @php
            $type = strtoupper(trim((string) ($voucher->fld_voucher_Type ?? '')));
            $amount = (float) ($voucher->fld_total ?? 0);
            $paid = (float) ($voucher->fld_paid ?? 0);
            $label = match ($type) {
                'SO' => 'Order',
                'SR' => 'Return',
                'S' => ($amount == 0.0 && $paid > 0) ? 'Receipt' : 'Sale',
                default => $type !== '' ? $type : 'Voucher',
            };
            $posted = (int) ($voucher->fld_is_posted ?? 0) === 1;
        @endphp
        <article class="pda-card">
            <strong>{{ $label }} {{ $voucher->fld_no }} · {{ $voucher->fld_customer_name }}</strong>
            <div class="pda-meta">
                {{ $voucher->fld_date }}
                · {{ $posted ? 'Posted' : 'Unposted' }}
                · Total {{ display_number($amount, 2) }}
                · Paid {{ display_number($paid, 2) }}
                @if ($voucher->fld_notes) · {{ $voucher->fld_notes }}@endif
            </div>
        </article>
    @empty
        <p class="muted">No vouchers were created today.</p>
    @endforelse
@endsection
