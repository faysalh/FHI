@extends('reports.layouts.app')
@section('title', 'Visit')

@section('content')
    <header class="page-header">
        <h1>{{ $customer['name'] }}</h1>
        <p class="hint">Visit in progress. Promotions are not calculated on this page.</p>
    </header>
    @include('reports.pda.partials.subnav')
    <div class="pda-actions">
        <a class="pda-btn pda-btn--primary" href="{{ route('reports.pda.vouchers.create', ['visit' => $visitId, 'type' => 'sale']) }}">Sale</a>
        <a class="pda-btn" href="{{ route('reports.pda.vouchers.create', ['visit' => $visitId, 'type' => 'order']) }}">Order</a>
        <a class="pda-btn" href="{{ route('reports.pda.vouchers.create', ['visit' => $visitId, 'type' => 'return']) }}">Return</a>
        <a class="pda-btn" href="{{ route('reports.pda.vouchers.create', ['visit' => $visitId, 'type' => 'receipt']) }}">Receipt</a>
        <a class="pda-btn" href="{{ route('reports.pda.customers.show', ['customer' => $customer['id']]) }}">Invoices</a>
    </div>
    @if ($forms !== [])
        <h2>Questionnaires</h2>
        @foreach ($forms as $form)
            <a class="pda-card" href="{{ route('reports.pda.questionnaires.show', ['questionnaire' => $form['id'], 'customer' => $customer['id'], 'visit' => $visitId]) }}">
                <strong>{{ $form['title'] }}</strong>
                @if ($form['mandatory'])<div class="pda-meta">Required</div>@endif
            </a>
        @endforeach
    @endif
    <h2>Finish visit</h2>
    <form method="POST" action="{{ route('reports.pda.visits.end', ['visit' => $visitId]) }}">
        @csrf
        <input type="hidden" name="failed" value="0">
        <label for="visit-note">Note</label>
        <input id="visit-note" name="note" maxlength="500">
        <button class="pda-btn pda-btn--primary" type="submit">End visit</button>
    </form>
    <form method="POST" action="{{ route('reports.pda.visits.end', ['visit' => $visitId]) }}" style="margin-top:16px;">
        @csrf
        <input type="hidden" name="failed" value="1">
        <label for="visit-reason">No invoice reason</label>
        <select id="visit-reason" name="reason_id">
            <option value="">Choose a reason</option>
            @foreach ($reasons as $reason)
                <option value="{{ $reason->fld_reason_id }}">{{ $reason->fld_reason_name_ar ?: $reason->fld_reason_name }}</option>
            @endforeach
        </select>
        <label for="visit-fail-note">Note</label>
        <input id="visit-fail-note" name="note" maxlength="500">
        <button class="pda-btn" type="submit">End with no invoice</button>
    </form>
@endsection
