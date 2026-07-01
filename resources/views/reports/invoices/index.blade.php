@extends('reports.layouts.app')
@section('title', 'Invoices report')

@section('content')
<header class="page-header"><h1>Invoices report</h1></header>
<p class="hint">Filter <strong>sales invoices</strong> only (<code>fld_type_alias = S</code>) — purchase and settlement documents are excluded. Filter by store, date, city, salesman, and text. Expand a row to inspect invoice/client/salesman metadata and invoice line items.</p>

    <form method="GET" action="{{ route('reports.invoices.index') }}" id="invoices-filter-form">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                @include('reports.partials.quick-date-buttons')
                <div class="filters-grid">
        <div>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div>
            <label for="store">Store (optional)</label>
            <select id="store" name="store">
                <option value="">All stores</option>
                @foreach (($storeOptions ?? []) as $st)
                    <option value="{{ $st }}" @selected(($filters['store'] ?? '') === $st)>{{ $st }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="salesman_id">Salesman (optional)</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach (($salesmen ?? []) as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q">Text search (optional)</label>
            <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="invoice/client/store/salesman">
        </div>
        <div class="span-full">
            <label>Cities (optional)</label>
            @include('reports.partials.city-picker', [
                'pickerId' => 'invoices',
                'cityOptions' => $cityOptionsForPicker ?? [],
                'selectedCities' => $filters['cities'] ?? [],
            ])
        </div>
        <div>
            <label for="per_page">Rows per page</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 25, 50, 100, 250] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 250) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.invoices.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="invoices-export-link export-link" data-export-base="{{ route('reports.invoices.export.list-csv') }}">CSV</a>
                    <a href="#" class="invoices-export-link export-link" data-export-base="{{ route('reports.invoices.export.list-pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @if ($rows)
        @if (!empty($grandTotals))
            <div class="totals-bar" role="region" aria-label="Report totals">
                <div class="total-item">
                    <span>Quantity (pcs)</span>
                    <strong class="num">{{ display_number($grandTotals->quantity_total ?? 0) }}</strong>
                </div>
                <div class="total-item">
                    <span>Invoice amount (IQD)</span>
                    <strong class="num">{{ display_number($grandTotals->invoice_amount ?? 0) }}</strong>
                </div>
                <div class="total-item">
                    <span>Client due (IQD)</span>
                    <strong class="num">{{ display_number($grandTotals->client_due_amount ?? 0) }}</strong>
                </div>
                <div class="muted" style="align-self:center;">All matching filters (not just this page)</div>
            </div>
        @endif
        <table id="invoices-table">
            <thead>
            <tr>
                <th class="select-col">Pick</th>
                <th>Invoice no</th>
                <th>Date</th>
                <th>Last print date</th>
                <th>Client</th>
                <th>Salesman</th>
                <th>Store</th>
                <th class="num">Quantity (pcs)</th>
                <th class="num">Invoice amount (IQD)</th>
                <th class="num">Client due (IQD)</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                @php
                    $isPicked = !empty(($selectedInvoices ?? [])[$row->invoice_id]);
                    $invoiceActionParams = [
                        'invoice_id' => $row->invoice_id,
                        'picked' => $isPicked ? 1 : 0,
                    ];
                @endphp
                <tr class="invoice-row" data-invoice-id="{{ $row->invoice_id }}" data-last-print-date="{{ $row->last_print_date ?? '' }}">
                    <td class="select-col">
                        <input type="checkbox" class="invoice-select" aria-label="Select invoice {{ $row->invoice_no }}" @checked($isPicked)>
                    </td>
                    <td>{{ $row->invoice_no }}</td>
                    <td>
                        <div>{{ $row->invoice_date }}</div>
                        <div class="meta">{{ $row->created_at }}</div>
                    </td>
                    <td>{{ $row->last_print_date ?? '' }}</td>
                    <td>
                        <div>{{ $row->client_name }}</div>
                        <div class="meta">{{ $row->client_code }} | {{ $row->city_name }}</div>
                    </td>
                    <td>{{ $row->salesman_name }}</td>
                    <td>{{ $row->store_name }}</td>
                    <td class="num">{{ display_number((float) ($row->quantity_total ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->invoice_amount ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->client_due_amount ?? 0)) }}</td>
                    <td>
                        <div class="action-links">
                            @include('reports.partials.icon-button', ['action' => 'view', 'label' => 'View invoice', 'type' => 'button', 'class' => 'details-btn'])
                            <a class="invoice-action-link" href="{{ route('reports.invoices.print', $invoiceActionParams) }}" target="_blank" rel="noopener" title="Print invoice">Print</a>
                            <a class="invoice-action-link" href="{{ route('reports.invoices.export.pdf', $invoiceActionParams) }}" title="Export invoice PDF">PDF</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="meta">No rows match the filters.</td></tr>
            @endforelse
            </tbody>
            @if (!empty($grandTotals) && $rows->count() > 0)
                <tfoot>
                <tr class="grand-total">
                    <td colspan="7"><strong>Total (all matching filters)</strong></td>
                    <td class="num"><strong>{{ display_number($grandTotals->quantity_total ?? 0) }}</strong></td>
                    <td class="num"><strong>{{ display_number($grandTotals->invoice_amount ?? 0) }}</strong></td>
                    <td class="num"><strong>{{ display_number($grandTotals->client_due_amount ?? 0) }}</strong></td>
                    <td></td>
                </tr>
                </tfoot>
            @endif
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif
</div>

<script>
(function () {
    var table = document.getElementById('invoices-table');
    if (!table) return;
    var endpoint = @json(route('reports.invoices.items'));
    var selectionEndpoint = @json(route('reports.invoices.selection'));
    var csrfToken = @json(csrf_token());
    var selections = @json($selectedInvoices ?? []);
    var openRow = null;

    function setRowSelected(row, isSelected, selections) {
        if (!row) return;
        var invoiceId = row.getAttribute('data-invoice-id') || '';
        if (!invoiceId) return;
        var checkbox = row.querySelector('.invoice-select');
        if (checkbox) checkbox.checked = !!isSelected;
        selections[invoiceId] = !!isSelected;
    }

    function persistSelection(invoiceId, selected) {
        return fetch(selectionEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({
                invoice_id: invoiceId,
                selected: !!selected
            })
        }).catch(function () {
            // Ignore network failures; UI still reflects local intent.
        });
    }

    table.querySelectorAll('.invoice-row').forEach(function (row) {
        var invoiceId = row.getAttribute('data-invoice-id') || '';
        var checkbox = row.querySelector('.invoice-select');
        if (!invoiceId || !checkbox) return;
        checkbox.checked = !!selections[invoiceId];
        checkbox.addEventListener('change', function () {
            selections[invoiceId] = !!checkbox.checked;
            persistSelection(invoiceId, checkbox.checked);
        });
    });

    function fmt(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(n));
    }
    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function buildItemsTable(rows) {
        var html = '<table class="mini-table"><thead><tr><th>Item</th><th class="num">Quantity (pcs)</th><th class="num">Amount (IQD)</th></tr></thead><tbody>';
        if (!rows.length) {
            html += '<tr><td colspan="3" class="meta">No item rows.</td></tr>';
        } else {
            rows.forEach(function (r) {
                html += '<tr><td>' + esc(r.item_name) + '</td><td class="num">' + fmt(r.quantity) + '</td><td class="num">' + fmt(r.amount) + '</td></tr>';
            });
        }
        html += '</tbody></table>';
        return html;
    }

    table.querySelectorAll('.invoice-row .details-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.invoice-row');
            if (!row) return;
            var invoiceId = row.getAttribute('data-invoice-id') || '';
            if (!invoiceId) return;

            if (openRow && openRow.previousElementSibling === row) {
                openRow.remove();
                openRow = null;
                return;
            }
            if (openRow) {
                openRow.remove();
                openRow = null;
            }

            var holder = document.createElement('tr');
            holder.className = 'details-row';
            var td = document.createElement('td');
            td.colSpan = 11;
            td.innerHTML = '<div class="details-card"><div class="meta">Loading invoice details...</div></div>';
            holder.appendChild(td);
            row.insertAdjacentElement('afterend', holder);
            openRow = holder;

            fetch(endpoint + '?invoice_id=' + encodeURIComponent(invoiceId), { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        td.innerHTML = '<div class="details-card"><div class="meta">Could not load invoice details.</div></div>';
                        return;
                    }
                    td.innerHTML = '<div class="details-card">' + buildItemsTable(data.rows || []) + '</div>';
                })
                .catch(function () {
                    td.innerHTML = '<div class="details-card"><div class="meta">Could not load invoice details.</div></div>';
                });
        });
    });

    table.querySelectorAll('.invoice-action-link').forEach(function (link) {
        link.addEventListener('click', function () {
            var row = link.closest('.invoice-row');
            if (!row) return;
            var invoiceId = row.getAttribute('data-invoice-id') || '';
            var checkbox = row.querySelector('.invoice-select');
            var isPickedNow = !!(checkbox && checkbox.checked);

            try {
                var url = new URL(link.href, window.location.href);
                url.searchParams.set('picked', isPickedNow ? '1' : '0');
                link.href = url.toString();
            } catch (e) {
                var pickedPart = 'picked=' + (isPickedNow ? '1' : '0');
                if (link.href.indexOf('picked=') >= 0) {
                    link.href = link.href.replace(/picked=[01]/, pickedPart);
                } else {
                    link.href += (link.href.indexOf('?') === -1 ? '?' : '&') + pickedPart;
                }
            }

            setRowSelected(row, true, selections);
        });
    });
})();
</script>
@include('reports.partials.city-picker-script', ['pickerId' => 'invoices', 'selectedCities' => $filters['cities'] ?? []])
@include('reports.partials.quick-date-buttons-script', ['formId' => 'invoices-filter-form'])
@include('reports.partials.export-from-form-script', ['formId' => 'invoices-filter-form', 'linkClass' => 'invoices-export-link'])
@endsection

@push('styles')
<style>
table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .meta { color: #64748b; font-size: 12px; }
        .action-links { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .action-links a {
            background: #1d4ed8; color: #fff; border-radius: 6px; padding: 6px 10px; text-decoration: none; font-size: 12px;
        }
        .select-col { width: 42px; text-align: center; }
        .invoice-select { width: 16px; height: 16px; cursor: pointer; }
        .details-row td { background: #f8fafc; }
        .details-card { padding: 10px; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-bottom: 8px; }
        .detail-key { color: #64748b; font-size: 12px; }
        .detail-val { font-size: 13px; }
        .mini-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        .mini-table th, .mini-table td { border: 1px solid #dbe5ef; padding: 6px; }
</style>
@endpush

