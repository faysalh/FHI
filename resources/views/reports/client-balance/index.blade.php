@extends('reports.layouts.app')
@section('title', __('Client balance'))

@section('content')
@php
    $filters = $filters ?? [];
    $salesmanId = (string) ($filters['salesman_id'] ?? '');
    $yearId = (string) ($filters['year_id'] ?? '');
    $currency = (int) ($filters['currency'] ?? 0);
    $hideZero = (bool) ($filters['hide_zero'] ?? false);
    $perPage = (int) ($filters['per_page'] ?? 100);
@endphp

<header class="page-header"><h1>{{ __('Client balance') }}</h1></header>
<p class="hint">
    Client account balances for one salesman, using the same logic as
    <code>SP_Get_Account_Balance</code> (debit − credit for the account tree in the selected fiscal year).
    Choose a salesman to load balances. Read-only.
</p>

@include('reports.partials.flash-messages')

@if (!empty($errorMessage))
    <p class="hint" style="color:#b91c1c;">{{ $errorMessage }}</p>
@endif

<form id="client-balance-filter-form" method="GET" action="{{ route('reports.client-balance.index') }}">
    <details class="filters-panel" open>
        <summary>Filters</summary>
        <div class="filters-body">
            <div class="filters-grid filters-grid--compact">
                <div>
                    <label for="salesman_id">Salesman</label>
                    <select id="salesman_id" name="salesman_id" required>
                        <option value="">Choose salesman…</option>
                        @foreach (($salesmen ?? []) as $salesman)
                            <option value="{{ $salesman['id'] }}" @selected($salesmanId === ($salesman['id'] ?? ''))>
                                {{ $salesman['name'] ?? '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="year_id">Fiscal year</label>
                    <select id="year_id" name="year_id">
                        @foreach (($yearOptions ?? []) as $year)
                            <option value="{{ $year->year_id }}" @selected($yearId === (string) ($year->year_id ?? ''))>
                                {{ $year->year_name ?? $year->year_id }}{{ ((int) ($year->is_current ?? 0) === 1) ? ' (current)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="currency">Currency mode</label>
                    <select id="currency" name="currency">
                        <option value="0" @selected($currency === 0)>Base (convert all)</option>
                        @foreach (($currencyOptions ?? []) as $cur)
                            <option value="{{ (int) ($cur->currency_id ?? 0) }}" @selected($currency === (int) ($cur->currency_id ?? 0))>
                                {{ $cur->currency_name ?? ('Currency '.$cur->currency_id) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="per_page">Rows / page</label>
                    <select id="per_page" name="per_page">
                        @foreach ([25, 50, 100, 250] as $size)
                            <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="span-full">
                    <label class="chk-label" style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                        <input type="checkbox" name="hide_zero" value="1" @checked($hideZero)>
                        Hide zero balances
                    </label>
                </div>
                <div style="align-self:end;">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Load', 'type' => 'submit'])
                </div>
            </div>
        </div>
    </details>
</form>

@if (!empty($needsSalesman))
    <p class="hint">Select a salesman and click Load to show client balances.</p>
@elseif ($rows !== null)
    <div class="totals-bar" style="margin-top:12px;">
        <div class="total-item"><span>Salesman</span><strong>{{ $salesmanName ?: '—' }}</strong></div>
        <div class="total-item"><span>Year</span><strong>{{ $yearName ?: '—' }}</strong></div>
        <div class="total-item"><span>Currency</span><strong>{{ $currencyLabel ?: '—' }}</strong></div>
        <div class="total-item"><span>Clients</span><strong>{{ number_format($rows->total()) }}</strong></div>
        <div class="total-item"><span>Total balance</span><strong>{{ display_number($grandTotal ?? 0) }}</strong></div>
    </div>

    <div class="btn-group" style="margin:12px 0;">
        <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.client-balance.export.pdf') }}">Export PDF</a>
        <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.client-balance.export.csv') }}">Export CSV</a>
    </div>

    <table>
        <thead>
        <tr>
            <th>Client code</th>
            <th>Client name</th>
            <th>Salesman</th>
            <th class="num">Balance</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row->client_code ?? '' }}</td>
                <td>{{ $row->client_name ?? '' }}</td>
                <td>{{ $row->salesman_name ?? '' }}</td>
                <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No clients found for this salesman.</td></tr>
        @endforelse
        </tbody>
        @if ($rows->total() > 0)
            <tfoot>
            <tr>
                <th colspan="3">Total (all pages)</th>
                <th class="num">{{ display_number($grandTotal ?? 0) }}</th>
            </tr>
            </tfoot>
        @endif
    </table>

    @if (method_exists($rows, 'links'))
        <div style="margin-top:12px;">{{ $rows->links() }}</div>
    @endif
@endif

<script>
(function () {
    document.querySelectorAll('.report-export-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var form = document.getElementById('client-balance-filter-form');
            if (!form) return;
            var params = new URLSearchParams(new FormData(form));
            window.location.href = link.getAttribute('data-export-base') + '?' + params.toString();
        });
    });
})();
</script>
@endsection
