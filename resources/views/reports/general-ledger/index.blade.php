@extends('reports.layouts.app')
@section('title', __('General ledger'))

@section('content')
@php
    $filters = $filters ?? [];
    $dateFrom = (string) ($filters['date_from'] ?? '');
    $dateTo = (string) ($filters['date_to'] ?? '');
    $yearId = (string) ($filters['year_id'] ?? '');
    $accountId = (string) ($filters['account_id'] ?? '');
    $salesmanId = (string) ($filters['salesman_id'] ?? '');
    $currency = (int) ($filters['currency'] ?? 0);
    $city = (string) ($filters['city'] ?? '');
    $accountType = (int) ($filters['account_type'] ?? 0);
    $showAsSummary = (bool) ($filters['show_as_summary'] ?? false);
    $transactionTypeId = (string) ($filters['transaction_type_id'] ?? '');
    $agentId = (string) ($filters['agent_id'] ?? '');
    $crossAccountId = (string) ($filters['cross_account_id'] ?? '');
    $perPage = (int) ($filters['per_page'] ?? 100);
    $accountLabel = (string) ($accountLabel ?? '');
    $crossAccountLabel = (string) ($crossAccountLabel ?? '');
@endphp

<header class="page-header"><h1>{{ __('General ledger') }}</h1></header>
<p class="hint">
    Account movement for a date range, matching AsanMax
    <code>GeneralLedgerAccount</code>
    (opening balance before the from-date, then period lines with running balance).
    Choose a date range and an account and/or salesman. Read-only.
</p>

@include('reports.partials.flash-messages')

@if (!empty($errorMessage))
    <p class="hint" style="color:#b91c1c;">{{ $errorMessage }}</p>
@endif

<form id="general-ledger-filter-form" method="GET" action="{{ route('reports.general-ledger.index') }}">
    <details class="filters-panel" open>
        <summary>Filters</summary>
        <div class="filters-body">
            <div class="filters-grid filters-grid--compact">
                <div>
                    <label for="date_from">Date from</label>
                    <input type="date" id="date_from" name="date_from" value="{{ $dateFrom }}" required>
                </div>
                <div>
                    <label for="date_to">Date to</label>
                    <input type="date" id="date_to" name="date_to" value="{{ $dateTo }}" required>
                </div>
                <div>
                    <label for="year_id">Fiscal year</label>
                    <select id="year_id" name="year_id">
                        <option value="">All years</option>
                        @foreach (($yearOptions ?? []) as $year)
                            <option value="{{ $year->year_id }}" @selected($yearId === (string) ($year->year_id ?? ''))>
                                {{ $year->year_name ?? $year->year_id }}{{ ((int) ($year->is_current ?? 0) === 1) ? ' (current)' : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="account_search">Account</label>
                    <input type="search" id="account_search" placeholder="Search account…" autocomplete="off" value="{{ $accountLabel }}">
                    <input type="hidden" id="account_id" name="account_id" value="{{ $accountId }}">
                    <div id="account-search-results" class="hint" style="margin-top:4px;"></div>
                </div>
                <div>
                    <label for="salesman_id">Salesman</label>
                    <select id="salesman_id" name="salesman_id">
                        <option value="">All salesmen</option>
                        @foreach (($salesmen ?? []) as $salesman)
                            <option value="{{ $salesman['id'] }}" @selected($salesmanId === ($salesman['id'] ?? ''))>
                                {{ $salesman['name'] ?? '' }}
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
                    <label for="city">City</label>
                    <select id="city" name="city">
                        <option value="">All cities</option>
                        @foreach (($cityOptions ?? []) as $cityOpt)
                            <option value="{{ $cityOpt }}" @selected($city === $cityOpt)>{{ $cityOpt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="account_type">Account type</label>
                    <select id="account_type" name="account_type">
                        <option value="0" @selected($accountType === 0)>All</option>
                        <option value="3" @selected($accountType === 3)>Role 3</option>
                        <option value="5" @selected($accountType === 5)>Role 5</option>
                    </select>
                </div>
                <div>
                    <label for="transaction_type_id">Document type</label>
                    <select id="transaction_type_id" name="transaction_type_id">
                        <option value="">All types</option>
                        @foreach (($transactionTypes ?? []) as $docType)
                            <option value="{{ $docType->type_id }}" @selected($transactionTypeId === (string) ($docType->type_id ?? ''))>
                                {{ $docType->type_name ?? $docType->type_id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="agent_id">Branch / agent</label>
                    <select id="agent_id" name="agent_id">
                        <option value="">All</option>
                        @foreach (($agents ?? []) as $agent)
                            <option value="{{ $agent->agent_id }}" @selected($agentId === (string) ($agent->agent_id ?? ''))>
                                {{ $agent->agent_name ?? $agent->agent_id }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="cross_account_search">Cross account</label>
                    <input type="search" id="cross_account_search" placeholder="Search cross account…" autocomplete="off" value="{{ $crossAccountLabel }}">
                    <input type="hidden" id="cross_account_id" name="cross_account_id" value="{{ $crossAccountId }}">
                    <div id="cross-account-search-results" class="hint" style="margin-top:4px;"></div>
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
                        <input type="checkbox" name="show_as_summary" value="1" @checked($showAsSummary)>
                        Show as summary (one line per document)
                    </label>
                </div>
                <div style="align-self:end;">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Load', 'type' => 'submit', 'class' => ''])
                </div>
            </div>
        </div>
    </details>
</form>

@if (!empty($needsDates) || ($rows === null && empty($errorMessage)))
    <p class="hint">Select a date range and an account and/or salesman, then click Load.</p>
@elseif ($rows !== null)
    <div class="totals-bar" style="margin-top:12px;">
        <div class="total-item"><span>Period</span><strong>{{ $dateFrom }} → {{ $dateTo }}</strong></div>
        <div class="total-item"><span>Account</span><strong>{{ $accountLabel !== '' ? $accountLabel : 'All (scoped)' }}</strong></div>
        <div class="total-item"><span>Salesman</span><strong>{{ ($salesmanName ?? '') !== '' ? $salesmanName : 'All' }}</strong></div>
        <div class="total-item"><span>Year</span><strong>{{ $yearName ?: '—' }}</strong></div>
        <div class="total-item"><span>Currency</span><strong>{{ $currencyLabel ?: '—' }}</strong></div>
        <div class="total-item"><span>Lines</span><strong>{{ number_format($rows->total()) }}</strong></div>
        <div class="total-item"><span>Period debit</span><strong>{{ display_number($periodDebit ?? 0) }}</strong></div>
        <div class="total-item"><span>Period credit</span><strong>{{ display_number($periodCredit ?? 0) }}</strong></div>
        <div class="total-item"><span>Period net</span><strong>{{ display_number(($periodDebit ?? 0) - ($periodCredit ?? 0)) }}</strong></div>
    </div>

    <div class="btn-group" style="margin:12px 0;">
        <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.general-ledger.export.pdf') }}">Export PDF</a>
        <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.general-ledger.export.csv') }}">Export CSV</a>
    </div>

    <table>
        <thead>
        <tr>
            <th>Account</th>
            <th>Type</th>
            <th>Invoice #</th>
            <th>Date</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Balance</th>
            <th>Cross account</th>
            <th>Branch</th>
            <th>Description</th>
            <th>Notes</th>
            <th>Ref 1</th>
            <th>Ref 2</th>
            <th>Payment</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $row)
            @php
                $isOpening = (string) ($row->flag ?? '') === '0';
                $dateVal = $row->register_date ?? null;
                $dateStr = '';
                if ($dateVal !== null && $dateVal !== '') {
                    try { $dateStr = \Illuminate\Support\Carbon::parse((string) $dateVal)->format('Y-m-d'); } catch (\Throwable) { $dateStr = (string) $dateVal; }
                }
            @endphp
            <tr @if ($isOpening) style="background:#f8fafc;font-weight:600;" @endif>
                <td>
                    {{ $row->account_code ?? '' }}
                    @if (($row->account_name ?? '') !== '')
                        <div class="hint" style="margin:0;">{{ $row->account_name }}</div>
                    @endif
                </td>
                <td>{{ $row->document_type ?? '' }}</td>
                <td>{{ $row->document_no ?? '' }}</td>
                <td>{{ $dateStr }}</td>
                <td class="num">{{ display_number((float) ($row->debit ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->credit ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
                <td>{{ $row->cross_account ?? '' }}</td>
                <td>{{ $row->branch ?? '' }}</td>
                <td>{{ $row->description ?? '' }}</td>
                <td>{{ $row->notes ?? '' }}</td>
                <td>{{ $row->ref_no1 ?? '' }}</td>
                <td>{{ $row->ref_no2 ?? '' }}</td>
                <td>{{ $row->payment_type ?? '' }}</td>
            </tr>
        @empty
            <tr><td colspan="14" class="muted">No ledger lines for these filters.</td></tr>
        @endforelse
        </tbody>
        @if ($rows->total() > 0)
            <tfoot>
            <tr>
                <th colspan="4">Period totals (excludes opening)</th>
                <th class="num">{{ display_number($periodDebit ?? 0) }}</th>
                <th class="num">{{ display_number($periodCredit ?? 0) }}</th>
                <th class="num">{{ display_number(($periodDebit ?? 0) - ($periodCredit ?? 0)) }}</th>
                <th colspan="7"></th>
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
    function bindAccountSearch(inputId, hiddenId, resultsId) {
        var input = document.getElementById(inputId);
        var hidden = document.getElementById(hiddenId);
        var results = document.getElementById(resultsId);
        if (!input || !hidden || !results) return;
        var timer = null;

        input.addEventListener('input', function () {
            hidden.value = '';
            var q = input.value.trim();
            clearTimeout(timer);
            timer = setTimeout(function () {
                fetch(@json(route('reports.general-ledger.api.accounts')) + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' }
                }).then(function (r) { return r.json(); }).then(function (data) {
                    results.innerHTML = '';
                    if (!data || !data.ok || !data.rows || !data.rows.length) {
                        results.textContent = q ? 'No accounts found.' : '';
                        return;
                    }
                    data.rows.forEach(function (row) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn--secondary';
                        btn.style.margin = '2px 4px 2px 0';
                        btn.textContent = (row.code ? row.code + ' — ' : '') + (row.name || row.id);
                        btn.addEventListener('click', function () {
                            hidden.value = row.id || '';
                            input.value = (row.code ? row.code + ' — ' : '') + (row.name || '');
                            results.innerHTML = '';
                        });
                        results.appendChild(btn);
                    });
                }).catch(function () {
                    results.textContent = 'Account search failed.';
                });
            }, 250);
        });
    }

    bindAccountSearch('account_search', 'account_id', 'account-search-results');
    bindAccountSearch('cross_account_search', 'cross_account_id', 'cross-account-search-results');

    document.querySelectorAll('.report-export-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var form = document.getElementById('general-ledger-filter-form');
            if (!form) return;
            var params = new URLSearchParams(new FormData(form));
            window.location.href = link.getAttribute('data-export-base') + '?' + params.toString();
        });
    });
})();
</script>
@endsection
