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
    $city = (string) ($filters['city'] ?? '');
    $showAsSummary = (bool) ($filters['show_as_summary'] ?? false);
    $accountLabel = (string) ($accountLabel ?? '');
    $dateRangeLabel = $dateFrom !== '' && $dateTo !== '' ? $dateFrom.' – '.$dateTo : '';
@endphp

<header class="page-header"><h1>{{ __('General ledger') }}</h1></header>

@include('reports.partials.flash-messages')

@if (!empty($errorMessage))
    <p class="hint" style="color:#b91c1c;">{{ $errorMessage }}</p>
@endif

<form id="general-ledger-filter-form" method="GET" action="{{ route('reports.general-ledger.index') }}">
    <details class="filters-panel" open>
        <summary>Filters</summary>
        <div class="filters-body">
            <div class="filters-grid filters-grid--compact">
                <div class="gl-range">
                    <label for="date_range">Date</label>
                    <button type="button" class="gl-range__field" id="date_range" aria-haspopup="dialog" aria-controls="date_range_pop" aria-expanded="false">{{ $dateRangeLabel !== '' ? $dateRangeLabel : 'Choose a range' }}</button>
                    <input type="hidden" id="date_from" name="date_from" value="{{ $dateFrom }}" required>
                    <input type="hidden" id="date_to" name="date_to" value="{{ $dateTo }}" required>
                    <div class="gl-range__pop" id="date_range_pop" hidden role="dialog" aria-label="Date range">
                        <div class="gl-range__nav">
                            <button type="button" data-month="-1" aria-label="Previous month">‹</button>
                            <strong data-month-label></strong>
                            <button type="button" data-month="1" aria-label="Next month">›</button>
                        </div>
                        <div class="gl-range__week" aria-hidden="true">
                            <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                        </div>
                        <div class="gl-range__grid" data-grid></div>
                        <p class="gl-range__hint">Select the start date, then the end date.</p>
                    </div>
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
                    <label for="city">City</label>
                    <select id="city" name="city">
                        <option value="">All cities</option>
                        @foreach (($cityOptions ?? []) as $cityOpt)
                            <option value="{{ $cityOpt }}" @selected($city === $cityOpt)>{{ $cityOpt }}</option>
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

@if ($rows !== null)
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

<style>
    .gl-range { position: relative; }
    .gl-range__field {
        display: flex; align-items: center; width: 100%; min-height: 38px;
        padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px;
        background: #fff; color: #0f172a; font: inherit; text-align: left; cursor: pointer;
    }
    .gl-range__pop {
        position: absolute; z-index: 30; top: calc(100% + 4px); left: 0;
        width: min(320px, calc(100vw - 48px)); padding: 10px;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
    }
    .gl-range__nav, .gl-range__week, .gl-range__grid {
        display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; align-items: center;
    }
    .gl-range__nav { grid-template-columns: 32px 1fr 32px; margin-bottom: 6px; }
    .gl-range__nav strong { text-align: center; font-size: 14px; }
    .gl-range__nav button, .gl-range__day {
        min-height: 34px; border: 0; border-radius: 6px; background: transparent;
        font: inherit; cursor: pointer;
    }
    .gl-range__nav button { background: #f1f5f9; }
    .gl-range__week span { text-align: center; font-size: 11px; color: #64748b; font-weight: 700; }
    .gl-range__day.is-out { color: #cbd5e1; }
    .gl-range__day.is-in { background: #e0e7ff; }
    .gl-range__day.is-end { background: #4f46e5; color: #fff; }
    .gl-range__hint { margin: 8px 0 0; font-size: 12px; color: #64748b; }
</style>
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

    var rangeButton = document.getElementById('date_range');
    var rangePop = document.getElementById('date_range_pop');
    var fromInput = document.getElementById('date_from');
    var toInput = document.getElementById('date_to');
    if (rangeButton && rangePop && fromInput && toInput) {
        var grid = rangePop.querySelector('[data-grid]');
        var monthLabel = rangePop.querySelector('[data-month-label]');
        var view = new Date();
        var from = fromInput.value || '';
        var to = toInput.value || '';
        if (from) {
            var seed = new Date(from + 'T00:00:00');
            if (!isNaN(seed.getTime())) view = seed;
        }
        var pickingStart = true;

        function iso(date) {
            var m = String(date.getMonth() + 1).padStart(2, '0');
            var d = String(date.getDate()).padStart(2, '0');
            return date.getFullYear() + '-' + m + '-' + d;
        }

        function paintLabel() {
            rangeButton.textContent = from && to ? from + ' – ' + to : 'Choose a range';
            fromInput.value = from;
            toInput.value = to;
        }

        function render() {
            var year = view.getFullYear();
            var month = view.getMonth();
            monthLabel.textContent = view.toLocaleString(undefined, { month: 'long', year: 'numeric' });
            grid.innerHTML = '';
            var first = new Date(year, month, 1);
            var startPad = first.getDay();
            var days = new Date(year, month + 1, 0).getDate();
            var cursor = new Date(year, month, 1 - startPad);
            for (var i = 0; i < 42; i++) {
                var day = new Date(cursor.getTime());
                var value = iso(day);
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'gl-range__day';
                button.textContent = String(day.getDate());
                button.setAttribute('data-date', value);
                if (day.getMonth() !== month) button.classList.add('is-out');
                if (from && to && value >= from && value <= to) button.classList.add('is-in');
                if (value === from || value === to) button.classList.add('is-end');
                grid.appendChild(button);
                cursor.setDate(cursor.getDate() + 1);
            }
        }

        function setOpen(open) {
            rangePop.hidden = !open;
            rangeButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) render();
        }

        rangeButton.addEventListener('click', function () {
            setOpen(rangePop.hidden);
        });
        rangePop.addEventListener('click', function (event) {
            event.stopPropagation();
            var shift = event.target.closest('[data-month]');
            if (shift) {
                view.setMonth(view.getMonth() + Number(shift.getAttribute('data-month')));
                render();
                return;
            }
            var day = event.target.closest('[data-date]');
            if (!day) return;
            var value = day.getAttribute('data-date');
            if (pickingStart) {
                from = value;
                to = value;
                pickingStart = false;
            } else {
                to = value;
                if (to < from) {
                    var swap = from;
                    from = to;
                    to = swap;
                }
                pickingStart = true;
                setOpen(false);
            }
            paintLabel();
            render();
        });
        document.addEventListener('click', function (event) {
            if (!rangePop.hidden && !rangePop.contains(event.target) && event.target !== rangeButton) {
                setOpen(false);
            }
        });
        paintLabel();
    }

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
