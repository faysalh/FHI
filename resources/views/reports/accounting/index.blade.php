@extends('reports.layouts.app')
@section('title', 'Accounting')

@section('content')
@php
    $tab = $filters['tab'] ?? 'cash';
    $selectedDate = $filters['date'] ?? now()->toDateString();
    $cashSheet = $cashBundle['sheet'] ?? null;
    $cashRows = $cashBundle['rows'] ?? [];
    $cashSpent = (float) ($cashBundle['spent'] ?? 0);
    $cashRemaining = (float) ($cashBundle['remaining'] ?? 0);
    $cashOpening = $cashSheet !== null ? (float) ($cashSheet->opening_amount ?? 0) : 0;
@endphp

<header class="page-header"><h1>Accounting</h1></header>

<div class="subtabs">
    <a href="{{ route('reports.accounting.index', ['tab' => 'cash', 'date' => $selectedDate]) }}" class="{{ $tab === 'cash' ? 'active' : '' }}">Money tracker</a>
    <a href="{{ route('reports.accounting.index', ['tab' => 'transfers', 'date' => $selectedDate]) }}" class="{{ $tab === 'transfers' ? 'active' : '' }}">Transfers</a>
    <a href="{{ route('reports.accounting.index', ['tab' => 'receipts']) }}" class="{{ $tab === 'receipts' ? 'active' : '' }}">Receipts</a>
    <a href="{{ route('reports.accounting.index', ['tab' => 'reports', 'date_from' => $filters['date_from'] ?? '', 'date_to' => $filters['date_to'] ?? '']) }}" class="{{ $tab === 'reports' ? 'active' : '' }}">Reports</a>
</div>

@include('reports.partials.flash-messages')

@if ($tab === 'cash')
    <p class="hint">Track daily cash in IQD: enter how much you received, then add spend rows. Remaining balance updates automatically. Pick any date to review or correct past sheets.</p>

    <form method="GET" action="{{ route('reports.accounting.index') }}" class="accounting-date-form">
        <input type="hidden" name="tab" value="cash">
        <label for="cash_sheet_date">Sheet date</label>
        <input type="date" id="cash_sheet_date" name="date" value="{{ $selectedDate }}" required>
        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Load date', 'type' => 'submit'])
        <a href="{{ route('reports.accounting.export.cash.pdf', ['date' => $selectedDate]) }}" class="btn btn--secondary">Export PDF</a>
    </form>

    <div class="totals-bar" style="margin-top:12px;">
        <div class="total-item"><span>Received (IQD)</span><strong>{{ display_number($cashOpening) }}</strong></div>
        <div class="total-item"><span>Spent (IQD)</span><strong>{{ display_number($cashSpent) }}</strong></div>
        <div class="total-item"><span>Remaining (IQD)</span><strong>{{ display_number($cashRemaining) }}</strong></div>
    </div>

    <div class="lab-card">
        <h3 class="section-title">Opening amount for {{ $selectedDate }}</h3>
        <form method="POST" action="{{ route('reports.accounting.cash.sheet') }}" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="cash">
            <input type="hidden" name="sheet_date" value="{{ $selectedDate }}">
            <div>
                <label for="opening_amount">IQD received</label>
                <input type="number" id="opening_amount" name="opening_amount" min="0" step="1" required value="{{ old('opening_amount', $cashOpening) }}">
            </div>
            <div class="inline-action-row" style="align-items:flex-end;">
                @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save opening amount'])
            </div>
        </form>
    </div>

    <div class="lab-card">
        <h3 class="section-title">Spend rows</h3>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th class="num">Amount (IQD)</th>
                    <th>To whom</th>
                    <th>Note</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($cashRows as $index => $row)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                        <td>{{ $row->paid_to ?? '' }}</td>
                        <td>{{ $row->note ?? '' }}</td>
                        <td>
                            <details class="inline-edit-details">
                                <summary class="btn btn--secondary btn--sm">Edit</summary>
                                <form method="POST" action="{{ route('reports.accounting.cash.rows.update', ['row' => (int) $row->id, 'date' => $selectedDate, 'tab' => 'cash']) }}" class="mini-grid" style="margin-top:8px;">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="date" value="{{ $selectedDate }}">
                                    <div>
                                        <label>Amount (IQD)</label>
                                        <input type="number" name="amount" min="0" step="1" required value="{{ (float) ($row->amount ?? 0) }}">
                                    </div>
                                    <div>
                                        <label>To whom</label>
                                        <input type="text" name="paid_to" maxlength="500" required value="{{ $row->paid_to ?? '' }}">
                                    </div>
                                    <div>
                                        <label>Note</label>
                                        <input type="text" name="note" maxlength="2000" value="{{ $row->note ?? '' }}">
                                    </div>
                                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Update row', 'type' => 'submit'])
                                </form>
                                <form method="POST" action="{{ route('reports.accounting.cash.rows.destroy', ['row' => (int) $row->id, 'date' => $selectedDate, 'tab' => 'cash']) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this spend row?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No spend rows yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <h4 class="tasks-subtitle">Add spend row</h4>
        <form method="POST" action="{{ route('reports.accounting.cash.rows.store') }}" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="cash">
            <input type="hidden" name="sheet_date" value="{{ $selectedDate }}">
            <div>
                <label for="cash_row_amount">Amount (IQD)</label>
                <input type="number" id="cash_row_amount" name="amount" min="0" step="1" required value="{{ old('amount') }}">
            </div>
            <div>
                <label for="cash_row_paid_to">To whom</label>
                <input type="text" id="cash_row_paid_to" name="paid_to" maxlength="500" required value="{{ old('paid_to') }}">
            </div>
            <div>
                <label for="cash_row_note">Note</label>
                <input type="text" id="cash_row_note" name="note" maxlength="2000" value="{{ old('note') }}">
            </div>
            <div class="inline-action-row" style="align-items:flex-end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add row'])
            </div>
        </form>
    </div>

@elseif ($tab === 'transfers')
    <p class="hint">Log incoming bank transfers. Default currency is IQD. For USD transfers, enter the exchange rate used for that transaction.</p>

    <form method="GET" action="{{ route('reports.accounting.index') }}" class="accounting-date-form">
        <input type="hidden" name="tab" value="transfers">
        <label for="transfer_sheet_date">Transfer date</label>
        <input type="date" id="transfer_sheet_date" name="date" value="{{ $selectedDate }}" required>
        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Load date', 'type' => 'submit'])
        <a href="{{ route('reports.accounting.export.transfers.pdf', ['date' => $selectedDate]) }}" class="btn btn--secondary">Export PDF</a>
    </form>

    <div class="lab-card">
        <h3 class="section-title">Transfers on {{ $selectedDate }}</h3>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th class="num">Amount</th>
                    <th>Currency</th>
                    <th class="num">USD rate</th>
                    <th class="num">IQD equivalent</th>
                    <th>From</th>
                    <th>Note</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @php $transferIqdDayTotal = 0.0; @endphp
                @forelse ($transferRows as $index => $row)
                    @php
                        $rowCurrency = strtoupper((string) ($row->currency ?? 'IQD'));
                        $rowAmount = (float) ($row->amount ?? 0);
                        $iqdEq = $rowCurrency === 'USD' ? $rowAmount * (float) ($row->usd_rate ?? 0) : $rowAmount;
                        $transferIqdDayTotal += $iqdEq;
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                        <td>{{ strtoupper((string) ($row->currency ?? 'IQD')) }}</td>
                        <td class="num">{{ strtoupper((string) ($row->currency ?? '')) === 'USD' ? display_number((float) ($row->usd_rate ?? 0)) : '—' }}</td>
                        <td class="num">{{ display_number($iqdEq) }}</td>
                        <td>{{ $row->person_name ?? '' }}</td>
                        <td>{{ $row->note ?? '' }}</td>
                        <td>
                            <details class="inline-edit-details">
                                <summary class="btn btn--secondary btn--sm">Edit</summary>
                                <form method="POST" action="{{ route('reports.accounting.transfers.rows.update', ['row' => (int) $row->id, 'date' => $selectedDate, 'tab' => 'transfers']) }}" class="mini-grid accounting-transfer-form" style="margin-top:8px;">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="date" value="{{ $selectedDate }}">
                                    <div>
                                        <label>Date</label>
                                        <input type="date" name="transfer_date" required value="{{ $row->transfer_date ?? $selectedDate }}">
                                    </div>
                                    <div>
                                        <label>Amount</label>
                                        <input type="number" name="amount" min="0" step="0.01" required value="{{ (float) ($row->amount ?? 0) }}">
                                    </div>
                                    <div>
                                        <label>Currency</label>
                                        <select name="currency" class="transfer-currency-select">
                                            <option value="IQD" @selected(strtoupper((string) ($row->currency ?? 'IQD')) === 'IQD')>IQD</option>
                                            <option value="USD" @selected(strtoupper((string) ($row->currency ?? '')) === 'USD')>USD</option>
                                        </select>
                                    </div>
                                    <div class="transfer-rate-field" @if(strtoupper((string) ($row->currency ?? 'IQD')) !== 'USD') hidden @endif>
                                        <label>USD rate (IQD per 1 USD)</label>
                                        <input type="number" name="usd_rate" min="0" step="0.01" value="{{ $row->usd_rate ?? '' }}">
                                    </div>
                                    <div>
                                        <label>From (person)</label>
                                        <input type="text" name="person_name" maxlength="500" required value="{{ $row->person_name ?? '' }}">
                                    </div>
                                    <div>
                                        <label>Note</label>
                                        <input type="text" name="note" maxlength="2000" value="{{ $row->note ?? '' }}">
                                    </div>
                                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Update row', 'type' => 'submit'])
                                </form>
                                <form method="POST" action="{{ route('reports.accounting.transfers.rows.destroy', ['row' => (int) $row->id, 'date' => $selectedDate, 'tab' => 'transfers']) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this transfer row?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No transfers logged for this date.</td></tr>
                @endforelse
                </tbody>
                @if (count($transferRows) > 0)
                    <tfoot>
                    <tr class="grand-total">
                        <td colspan="4">Day total (IQD equivalent)</td>
                        <td class="num">{{ display_number($transferIqdDayTotal) }}</td>
                        <td colspan="3"></td>
                    </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        <h4 class="tasks-subtitle">Add transfer</h4>
        <form method="POST" action="{{ route('reports.accounting.transfers.rows.store') }}" class="mini-grid accounting-transfer-form" id="transferAddForm">
            @csrf
            <input type="hidden" name="tab" value="transfers">
            <div>
                <label for="transfer_date">Date</label>
                <input type="date" id="transfer_date" name="transfer_date" required value="{{ old('transfer_date', $selectedDate) }}">
            </div>
            <div>
                <label for="transfer_amount">Amount</label>
                <input type="number" id="transfer_amount" name="amount" min="0" step="0.01" required value="{{ old('amount') }}">
            </div>
            <div>
                <label for="transfer_currency">Currency</label>
                <select id="transfer_currency" name="currency" class="transfer-currency-select">
                    <option value="IQD" @selected(old('currency', 'IQD') === 'IQD')>IQD</option>
                    <option value="USD" @selected(old('currency') === 'USD')>USD</option>
                </select>
            </div>
            <div class="transfer-rate-field" id="transferRateField" @if(old('currency', 'IQD') !== 'USD') hidden @endif>
                <label for="transfer_usd_rate">USD rate (IQD per 1 USD)</label>
                <input type="number" id="transfer_usd_rate" name="usd_rate" min="0" step="0.01" value="{{ old('usd_rate') }}">
            </div>
            <div>
                <label for="transfer_person_name">From (person)</label>
                <input type="text" id="transfer_person_name" name="person_name" maxlength="500" required value="{{ old('person_name') }}">
            </div>
            <div>
                <label for="transfer_note">Note</label>
                <input type="text" id="transfer_note" name="note" maxlength="2000" value="{{ old('note') }}">
            </div>
            <div class="inline-action-row" style="align-items:flex-end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add transfer'])
            </div>
        </form>
    </div>

@elseif ($tab === 'receipts')
    @include('reports.accounting.partials.receipts')

@elseif ($tab === 'reports')
    <p class="hint">Summaries for cash sheets and incoming transfers across a date range. Export uses the filters below.</p>

    <form method="GET" action="{{ route('reports.accounting.index') }}" id="accountingReportsFilterForm" class="filters-panel-form">
        <input type="hidden" name="tab" value="reports">
        <div class="filters-grid">
            <div>
                <label for="reports_date_from">From</label>
                <input type="date" id="reports_date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" required>
            </div>
            <div>
                <label for="reports_date_to">To</label>
                <input type="date" id="reports_date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" required>
            </div>
        </div>
        @include('reports.partials.quick-date-buttons', ['presets' => ['this-month', 'last-30', 'last-month']])
        <div class="inline-action-row" style="margin-top:10px;">
            @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply', 'type' => 'submit'])
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.accounting.export.summary.pdf') }}">Export PDF</a>
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.accounting.export.summary.csv') }}">Export CSV</a>
        </div>
    </form>

    <div class="lab-card">
        <h3 class="section-title">Cash summary</h3>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th class="num">Opening IQD</th>
                    <th class="num">Spent IQD</th>
                    <th class="num">Remaining IQD</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($cashSummary as $row)
                    <tr>
                        <td>{{ $row->sheet_date ?? '' }}</td>
                        <td class="num">{{ display_number((float) ($row->opening_amount ?? 0)) }}</td>
                        <td class="num">{{ display_number((float) ($row->spent_total ?? 0)) }}</td>
                        <td class="num">{{ display_number((float) ($row->remaining_total ?? 0)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No cash sheets in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="lab-card">
        <h3 class="section-title">Transfers summary</h3>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Date</th>
                    <th class="num">Rows</th>
                    <th class="num">IQD equivalent</th>
                    <th class="num">USD rows</th>
                    <th class="num">USD amount total</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transferSummary as $row)
                    <tr>
                        <td>{{ $row->transfer_date ?? '' }}</td>
                        <td class="num">{{ display_number((int) ($row->row_count ?? 0)) }}</td>
                        <td class="num">{{ display_number((float) ($row->iqd_total ?? 0)) }}</td>
                        <td class="num">{{ display_number((int) ($row->usd_row_count ?? 0)) }}</td>
                        <td class="num">{{ display_number((float) ($row->usd_amount_total ?? 0)) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No transfers in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

@push('scripts')
@if ($tab === 'transfers')
<script>
(function () {
    function syncRateField(form) {
        var select = form.querySelector('.transfer-currency-select');
        var rateField = form.querySelector('.transfer-rate-field');
        if (!select || !rateField) return;
        var isUsd = select.value === 'USD';
        rateField.hidden = !isUsd;
        var input = rateField.querySelector('input[name="usd_rate"]');
        if (input) input.required = isUsd;
    }
    document.querySelectorAll('.accounting-transfer-form').forEach(function (form) {
        var select = form.querySelector('.transfer-currency-select');
        if (select) {
            select.addEventListener('change', function () { syncRateField(form); });
            syncRateField(form);
        }
    });
})();
</script>
@endif
@if ($tab === 'reports')
@include('reports.partials.quick-date-buttons-script', ['formId' => 'accountingReportsFilterForm', 'dateFromId' => 'reports_date_from', 'dateToId' => 'reports_date_to'])
@include('reports.partials.export-from-form-script', ['formId' => 'accountingReportsFilterForm'])
@endif
@endpush
