@extends('reports.layouts.app')
@section('title', 'Sales by salesman')

@section('content')
<header class="page-header"><h1>Sales by salesman</h1></header>
<p class="hint">
        Per-client totals from store document lines for the selected <strong>salesman</strong> and date range.
        <strong>Amount</strong> = posted sales invoices (<code>S</code>) with discount-aware line totals (same basis as the main Sales report).
        <strong>Client price group</strong> maps the account’s numeric tier <strong>0–4</strong> (same order as storage sale prices 1–5, DB value is one less):
        <em>0 وكيل</em>, <em>1 وكيل 2</em>, <em>2 ماركيت</em>, <em>3 جملة</em>, <em>4 كي</em> (see
        <a href="{{ route('reports.storage-items.index') }}">Storage items</a> sale prices 1–5).
        @if (!empty($priceGroupColumn))
            Tier source: <code>{{ $priceGroupColumn }}</code> (account and/or <code>dbo.tbl_accounting_account_details</code>, COALESCE + MAX per account).
        @else
            No matching column was auto-detected; set <code>REPORTING_ACCOUNT_CLIENT_PRICE_GROUP_COLUMN</code> in <code>.env</code> if needed.
        @endif
    </p>

    <form id="sales-by-salesman-form" method="GET" action="{{ route('reports.sales-by-salesman.index') }}">
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
            <label for="salesman_id">Salesman</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">— Select —</option>
                @foreach ($salesmen as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
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
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.sales-by-salesman.index'])
                    @if (!empty($filters['salesman_id']) && !($needsSalesman ?? false))
                        <span class="muted">Export:</span>
                        <a href="#" class="sbs-export-link export-link" data-export-base="{{ route('reports.sales-by-salesman.export.csv') }}">CSV</a>
                        <a href="#" class="sbs-export-link export-link" data-export-base="{{ route('reports.sales-by-salesman.export.pdf') }}">PDF</a>
                    @endif
                </div>
            </div>
        </details>
    </form>

    @if (($needsSalesman ?? false))
        <p class="muted">Select a salesman and click <strong>Apply</strong> to load clients and totals.</p>
    @elseif ($rows)
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Client name</th>
                <th>Client price group</th>
                <th class="num">Number of invoices</th>
                <th class="num">Quantity of sales</th>
                <th class="num">Amount of sales</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ (($rows->currentPage() - 1) * $rows->perPage()) + $loop->iteration }}</td>
                    <td>{{ $row->client_name ?? '' }}</td>
                    <td>{{ $row->client_price_group ?? '' }}</td>
                    <td class="num">@displayNumber((float) ($row->invoice_count ?? 0))</td>
                    <td class="num">@displayNumber((float) ($row->quantity_sold ?? 0))</td>
                    <td class="num">@displayNumber((float) ($row->amount ?? 0))</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No sales in this period for this salesman.</td></tr>
            @endforelse
            </tbody>
            @if (isset($grandTotals) && $rows->total() > 0)
                <tfoot>
                <tr>
                    <td colspan="3">Total (all clients)</td>
                    <td class="num">@displayNumber((float) ($grandTotals->sum_invoice_count ?? 0))</td>
                    <td class="num">@displayNumber((float) ($grandTotals->sum_quantity_sold ?? 0))</td>
                    <td class="num">@displayNumber((float) ($grandTotals->sum_amount ?? 0))</td>
                </tr>
                </tfoot>
            @endif
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif

    @include('reports.partials.quick-date-buttons-script', ['formId' => 'sales-by-salesman-form'])
    @include('reports.partials.export-from-form-script', ['formId' => 'sales-by-salesman-form', 'linkClass' => 'sbs-export-link'])
@endsection

@push('styles')
<style>
table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        tfoot td { font-weight: 700; background: #f1f5f9; border-top: 2px solid #cbd5e1; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
</style>
@endpush

