@extends('reports.layouts.app')
@section('title', __('Manufacturing Storage'))

@section('content')
@php
    $tab = $filters['tab'] ?? 'stock';
    $dateFrom = $filters['date_from'] ?? now()->startOfMonth()->toDateString();
    $dateTo = $filters['date_to'] ?? now()->toDateString();
    $canDelete = (bool) ($canDelete ?? false);
    $purchaseItemOptions = [];
    foreach ($items as $item) {
        $purchaseItemOptions[] = [
            'id' => (string) $item->id,
            'name' => (string) $item->name,
            'unit' => (string) $item->unit,
        ];
    }
@endphp

<header class="page-header"><h1>{{ __('Manufacturing Storage') }}</h1></header>
<p class="hint">Local stock for manufacturing materials. Purchases add to stock; exports to manufacturing reduce stock. Costs can be IQD or USD.</p>

<div class="subtabs">
    <a href="{{ route('reports.manufacturing.index', ['tab' => 'stock']) }}" class="{{ $tab === 'stock' ? 'active' : '' }}">Stock</a>
    <a href="{{ route('reports.manufacturing.index', ['tab' => 'items']) }}" class="{{ $tab === 'items' ? 'active' : '' }}">Items</a>
    <a href="{{ route('reports.manufacturing.index', ['tab' => 'purchases', 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="{{ $tab === 'purchases' ? 'active' : '' }}">Purchases</a>
    <a href="{{ route('reports.manufacturing.index', ['tab' => 'exports', 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="{{ $tab === 'exports' ? 'active' : '' }}">Exports</a>
</div>

@include('reports.partials.flash-messages')

@if ($tab === 'stock')
    <p class="hint">Current balance = purchased quantity − exported quantity.</p>
    <table>
        <thead>
        <tr>
            <th>Item</th>
            <th>Unit</th>
            <th class="num">Purchased</th>
            <th class="num">Exported</th>
            <th class="num">Balance</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($stock as $row)
            <tr>
                <td>{{ $row->name }}</td>
                <td>{{ $row->unit }}</td>
                <td class="num">{{ display_number($row->purchased_qty) }}</td>
                <td class="num">{{ display_number($row->exported_qty) }}</td>
                <td class="num"><strong>{{ display_number($row->balance) }}</strong></td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No manufacturing items yet. Add items on the Items tab.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($tab === 'items')
    <div class="lab-card" style="margin-bottom:16px;">
        <h3 class="section-title">Add item</h3>
        <form method="POST" action="{{ route('reports.manufacturing.items.store') }}" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="items">
            <div>
                <label for="item_name">Item name</label>
                <input type="text" id="item_name" name="name" value="{{ old('name') }}" required maxlength="500">
            </div>
            <div>
                <label for="item_code">Code (optional)</label>
                <input type="text" id="item_code" name="code" value="{{ old('code') }}" maxlength="100" placeholder="SKU or code">
            </div>
            <div>
                <label for="item_unit">Unit</label>
                <input type="text" id="item_unit" name="unit" value="{{ old('unit') }}" required maxlength="100" placeholder="kg, carton, liter…">
            </div>
            <div style="align-self:end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add item', 'type' => 'submit'])
            </div>
        </form>
    </div>

    <div class="lab-card" style="margin-bottom:16px;">
        <h3 class="section-title">Bulk import / update</h3>
        <p class="hint" style="margin-top:0;">
            Load your first item list from a CSV file or by pasting lines below.
            CSV header: <code>name</code>, <code>code</code> (optional), <code>unit</code>.
            Paste one item per line as <code>name, unit</code> or <code>name, code, unit</code>.
            By default duplicate names are skipped; enable <strong>Update existing items</strong> to change unit/code for items that already exist (matched by name).
        </p>
        <form method="POST" action="{{ route('reports.manufacturing.items.bulk') }}" enctype="multipart/form-data" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="items">
            <div class="span-full">
                <label for="bulk_lines">Paste items (one per line)</label>
                <textarea id="bulk_lines" name="bulk_lines" rows="8" maxlength="500000" placeholder="Flour, kg&#10;Sugar, SUG-01, kg&#10;Cooking oil, liter">{{ old('bulk_lines') }}</textarea>
            </div>
            <div class="span-full">
                <label for="csv_file">Or upload CSV</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv">
            </div>
            <div class="span-full">
                <label style="display:flex;align-items:center;gap:8px;font-weight:normal;">
                    <input type="checkbox" name="update_existing" value="1" @checked(old('update_existing'))>
                    Update existing items (match by name)
                </label>
            </div>
            <div style="align-self:end;">
                @include('reports.partials.icon-button', ['action' => 'load', 'label' => 'Import items', 'type' => 'submit'])
            </div>
        </form>
        <p class="muted" style="margin:8px 0 0;">CSV example: <code>name,code,unit</code> then <code>Steel rod,SR-01,kg</code></p>
    </div>

    <table>
        <thead>
        <tr>
            <th>Item name</th>
            <th>Code</th>
            <th>Unit</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($items as $item)
            <tr>
                <td colspan="4">
                    <form method="POST" action="{{ route('reports.manufacturing.items.update', ['item' => (int) $item->id]) }}" class="mini-grid" style="margin:0;">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="tab" value="items">
                        <div>
                            <label class="sr-only" for="item_name_{{ $item->id }}">Item name</label>
                            <input type="text" id="item_name_{{ $item->id }}" name="name" value="{{ $item->name }}" required maxlength="500">
                        </div>
                        <div>
                            <label class="sr-only" for="item_code_{{ $item->id }}">Code</label>
                            <input type="text" id="item_code_{{ $item->id }}" name="code" value="{{ $item->code ?? '' }}" maxlength="100" placeholder="Code">
                        </div>
                        <div>
                            <label class="sr-only" for="item_unit_{{ $item->id }}">Unit</label>
                            <input type="text" id="item_unit_{{ $item->id }}" name="unit" value="{{ $item->unit }}" required maxlength="100">
                        </div>
                        <div style="display:flex;gap:8px;align-items:end;">
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save', 'type' => 'submit'])
                        </div>
                    </form>
                    @if ($canDelete)
                        <form method="POST" action="{{ route('reports.manufacturing.items.destroy', ['item' => (int) $item->id]) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this item?');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="tab" value="items">
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete', 'type' => 'submit', 'class' => 'btn btn--danger'])
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No items yet.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($tab === 'purchases')
    <form method="GET" action="{{ route('reports.manufacturing.index') }}" id="mfgPurchasesFilterForm" class="filters-panel-form" style="margin-bottom:12px;">
        <input type="hidden" name="tab" value="purchases">
        <div class="filters-row">
            <div>
                <label for="purchases_date_from">{{ __('From') }}</label>
                <input type="date" id="purchases_date_from" name="date_from" value="{{ $dateFrom }}" required>
            </div>
            <div>
                <label for="purchases_date_to">{{ __('To') }}</label>
                <input type="date" id="purchases_date_to" name="date_to" value="{{ $dateTo }}" required>
            </div>
            <div style="align-self:end;display:flex;gap:8px;flex-wrap:wrap;">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply', 'type' => 'submit'])
                <span class="btn-group">
                    <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.manufacturing.export.purchases.pdf') }}">Export PDF</a>
                    <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.manufacturing.export.purchases.csv') }}">Export CSV</a>
                </span>
            </div>
        </div>
        @include('reports.partials.quick-date-buttons', ['dateFromId' => 'purchases_date_from', 'dateToId' => 'purchases_date_to'])
    </form>

    <div class="lab-card" style="margin-bottom:16px;">
        <h3 class="section-title">Add purchase</h3>
        <p class="hint">Choose IQD or USD for cost. Put exchange rate details in the note if needed.</p>
        <form method="POST" action="{{ route('reports.manufacturing.purchases.store') }}" class="mini-grid" id="mfgPurchaseAddForm">
            @csrf
            <input type="hidden" name="tab" value="purchases">
            <input type="hidden" name="date_from" value="{{ $dateFrom }}">
            <input type="hidden" name="date_to" value="{{ $dateTo }}">
            <div>
                <label for="purchase_date">{{ __('Date') }}</label>
                <input type="date" id="purchase_date" name="purchase_date" value="{{ old('purchase_date', now()->toDateString()) }}" required>
            </div>
            <div style="grid-column: span 2; position: relative;">
                <label for="purchase_item_search">Item</label>
                <input type="text" id="purchase_item_search" placeholder="Type item name to search…" autocomplete="off" value="">
                <input type="hidden" name="item_id" id="purchase_item_id" value="{{ old('item_id') }}" required>
                <div class="mfg-suggest" id="purchase_item_suggest" hidden></div>
            </div>
            <div>
                <label for="purchase_unit_display">Unit</label>
                <input type="text" id="purchase_unit_display" value="" readonly>
            </div>
            <div>
                <label for="purchase_quantity">Quantity</label>
                <input type="number" id="purchase_quantity" name="quantity" step="0.01" min="0.01" value="{{ old('quantity') }}" required>
            </div>
            <div>
                <label for="purchase_cost">Cost</label>
                <input type="number" id="purchase_cost" name="cost_amount" step="0.01" min="0" value="{{ old('cost_amount') }}" required>
            </div>
            <div>
                <label for="purchase_currency">Currency</label>
                <select id="purchase_currency" name="currency" required>
                    <option value="IQD" @selected(old('currency', 'IQD') === 'IQD')>IQD</option>
                    <option value="USD" @selected(old('currency') === 'USD')>USD ($)</option>
                </select>
            </div>
            <div>
                <label for="purchase_supplier">Supplier</label>
                <input type="text" id="purchase_supplier" name="supplier_name" value="{{ old('supplier_name') }}" required maxlength="500">
            </div>
            <div>
                <label for="purchase_note">Note</label>
                <input type="text" id="purchase_note" name="note" value="{{ old('note') }}" maxlength="2000" placeholder="Optional — e.g. USD rate">
            </div>
            <div style="align-self:end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add purchase', 'type' => 'submit'])
            </div>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Item</th>
            <th>Unit</th>
            <th class="num">Qty</th>
            <th class="num">Cost</th>
            <th>Currency</th>
            <th>Supplier</th>
            <th>Note</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($purchases as $row)
            <tr>
                <td>{{ $row->purchase_date }}</td>
                <td>{{ $row->item_name }}</td>
                <td>{{ $row->item_unit }}</td>
                <td class="num">{{ display_number($row->quantity) }}</td>
                <td class="num">{{ display_number($row->cost_amount) }}</td>
                <td>{{ strtoupper((string) $row->currency) }}</td>
                <td>{{ $row->supplier_name }}</td>
                <td>{{ $row->note }}</td>
                <td>
                    <details>
                        <summary>Edit</summary>
                        <form method="POST" action="{{ route('reports.manufacturing.purchases.update', ['row' => (int) $row->id]) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="purchases">
                            <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                            <input type="hidden" name="date_to" value="{{ $dateTo }}">
                            <div>
                                <label>{{ __('Date') }}</label>
                                <input type="date" name="purchase_date" value="{{ $row->purchase_date }}" required>
                            </div>
                            <div>
                                <label>Item</label>
                                <select name="item_id" required>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected((int) $row->item_id === (int) $item->id)>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Quantity</label>
                                <input type="number" name="quantity" step="0.01" min="0.01" value="{{ $row->quantity }}" required>
                            </div>
                            <div>
                                <label>Cost</label>
                                <input type="number" name="cost_amount" step="0.01" min="0" value="{{ $row->cost_amount }}" required>
                            </div>
                            <div>
                                <label>Currency</label>
                                <select name="currency" required>
                                    <option value="IQD" @selected(strtoupper((string)$row->currency)==='IQD')>IQD</option>
                                    <option value="USD" @selected(strtoupper((string)$row->currency)==='USD')>USD</option>
                                </select>
                            </div>
                            <div>
                                <label>Supplier</label>
                                <input type="text" name="supplier_name" value="{{ $row->supplier_name }}" required maxlength="500">
                            </div>
                            <div>
                                <label>Note</label>
                                <input type="text" name="note" value="{{ $row->note }}" maxlength="2000" placeholder="Optional — e.g. USD rate">
                            </div>
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save', 'type' => 'submit'])
                        </form>
                        @if ($canDelete)
                            <form method="POST" action="{{ route('reports.manufacturing.purchases.destroy', ['row' => (int) $row->id]) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this purchase?');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="tab" value="purchases">
                                <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                                <input type="hidden" name="date_to" value="{{ $dateTo }}">
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete', 'type' => 'submit', 'class' => 'btn btn--danger'])
                            </form>
                        @endif
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted">No purchases in this date range.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($tab === 'exports')
    <form method="GET" action="{{ route('reports.manufacturing.index') }}" id="mfgExportsFilterForm" class="filters-panel-form" style="margin-bottom:12px;">
        <input type="hidden" name="tab" value="exports">
        <div class="filters-row">
            <div>
                <label for="exports_date_from">{{ __('From') }}</label>
                <input type="date" id="exports_date_from" name="date_from" value="{{ $dateFrom }}" required>
            </div>
            <div>
                <label for="exports_date_to">{{ __('To') }}</label>
                <input type="date" id="exports_date_to" name="date_to" value="{{ $dateTo }}" required>
            </div>
            <div style="align-self:end;display:flex;gap:8px;flex-wrap:wrap;">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply', 'type' => 'submit'])
                <span class="btn-group">
                    <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.manufacturing.export.exports.pdf') }}">Export PDF</a>
                    <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.manufacturing.export.exports.csv') }}">Export CSV</a>
                </span>
            </div>
        </div>
        @include('reports.partials.quick-date-buttons', ['dateFromId' => 'exports_date_from', 'dateToId' => 'exports_date_to'])
    </form>

    <div class="lab-card" style="margin-bottom:16px;">
        <h3 class="section-title">Export to manufacturing</h3>
        <form method="POST" action="{{ route('reports.manufacturing.exports.store') }}" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="exports">
            <input type="hidden" name="date_from" value="{{ $dateFrom }}">
            <input type="hidden" name="date_to" value="{{ $dateTo }}">
            <div>
                <label for="export_date">{{ __('Date') }}</label>
                <input type="date" id="export_date" name="export_date" value="{{ old('export_date', now()->toDateString()) }}" required>
            </div>
            <div>
                <label for="export_item_id">Item</label>
                <select id="export_item_id" name="item_id" required data-unit-target="export_unit_display">
                    <option value="">Select item…</option>
                    @foreach ($items as $item)
                        <option value="{{ $item->id }}" @selected((string) old('item_id') === (string) $item->id)>{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="export_unit_display">Unit</label>
                <input type="text" id="export_unit_display" value="" readonly>
            </div>
            <div>
                <label for="export_quantity">Quantity</label>
                <input type="number" id="export_quantity" name="quantity" step="0.01" min="0.01" value="{{ old('quantity') }}" required>
            </div>
            <div>
                <label for="export_note">Note</label>
                <input type="text" id="export_note" name="note" value="{{ old('note') }}" maxlength="2000">
            </div>
            <div style="align-self:end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add export', 'type' => 'submit'])
            </div>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Item</th>
            <th>Unit</th>
            <th class="num">Qty</th>
            <th>Note</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($exports as $row)
            <tr>
                <td>{{ $row->export_date }}</td>
                <td>{{ $row->item_name }}</td>
                <td>{{ $row->item_unit }}</td>
                <td class="num">{{ display_number($row->quantity) }}</td>
                <td>{{ $row->note }}</td>
                <td>
                    <details>
                        <summary>Edit</summary>
                        <form method="POST" action="{{ route('reports.manufacturing.exports.update', ['row' => (int) $row->id]) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="exports">
                            <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                            <input type="hidden" name="date_to" value="{{ $dateTo }}">
                            <div>
                                <label>{{ __('Date') }}</label>
                                <input type="date" name="export_date" value="{{ $row->export_date }}" required>
                            </div>
                            <div>
                                <label>Item</label>
                                <select name="item_id" required>
                                    @foreach ($items as $item)
                                        <option value="{{ $item->id }}" @selected((int) $row->item_id === (int) $item->id)>{{ $item->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label>Quantity</label>
                                <input type="number" name="quantity" step="0.01" min="0.01" value="{{ $row->quantity }}" required>
                            </div>
                            <div>
                                <label>Note</label>
                                <input type="text" name="note" value="{{ $row->note }}" maxlength="2000">
                            </div>
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save', 'type' => 'submit'])
                        </form>
                        @if ($canDelete)
                            <form method="POST" action="{{ route('reports.manufacturing.exports.destroy', ['row' => (int) $row->id]) }}" style="margin-top:8px;" onsubmit="return confirm('Delete this export?');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="tab" value="exports">
                                <input type="hidden" name="date_from" value="{{ $dateFrom }}">
                                <input type="hidden" name="date_to" value="{{ $dateTo }}">
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete', 'type' => 'submit', 'class' => 'btn btn--danger'])
                            </form>
                        @endif
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No exports in this date range.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
@endsection

@push('styles')
<style>
.mfg-suggest {
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    border: 1px solid var(--rp-border, #e5e7eb);
    border-radius: 6px;
    max-height: 220px;
    overflow: auto;
    margin-top: 2px;
    background: #fff;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}
.mfg-suggest button {
    display: block;
    width: 100%;
    text-align: left;
    padding: 8px 10px;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    background: #fff;
    cursor: pointer;
    font-size: 14px;
}
.mfg-suggest button:hover,
.mfg-suggest button.is-active {
    background: #eff6ff;
}
.mfg-suggest .muted {
    padding: 8px 10px;
    color: #64748b;
    font-size: 13px;
}
</style>
@endpush

@push('scripts')
<script>
(function () {
    var items = @json($purchaseItemOptions);

    function wirePurchaseItemSearch() {
        var search = document.getElementById('purchase_item_search');
        var hidden = document.getElementById('purchase_item_id');
        var unit = document.getElementById('purchase_unit_display');
        var suggest = document.getElementById('purchase_item_suggest');
        if (!search || !hidden || !unit || !suggest) return;

        function setItem(item) {
            hidden.value = item ? item.id : '';
            search.value = item ? item.name : search.value;
            unit.value = item ? item.unit : '';
            suggest.hidden = true;
            suggest.innerHTML = '';
        }

        function render(list) {
            suggest.innerHTML = '';
            if (!list.length) {
                suggest.innerHTML = '<div class="muted">No matching items</div>';
                suggest.hidden = false;
                return;
            }
            list.slice(0, 40).forEach(function (item) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = item.name + ' (' + item.unit + ')';
                btn.addEventListener('click', function () { setItem(item); });
                suggest.appendChild(btn);
            });
            suggest.hidden = false;
        }

        function filter() {
            var q = String(search.value || '').trim().toLowerCase();
            if (hidden.value) {
                var selected = items.find(function (i) { return i.id === String(hidden.value); });
                if (!selected || selected.name.toLowerCase() !== q) {
                    hidden.value = '';
                    unit.value = '';
                }
            }
            if (q === '') {
                suggest.hidden = true;
                suggest.innerHTML = '';
                return;
            }
            render(items.filter(function (i) {
                return i.name.toLowerCase().indexOf(q) !== -1;
            }));
        }

        // Restore old selection after validation error
        if (hidden.value) {
            var existing = items.find(function (i) { return i.id === String(hidden.value); });
            if (existing) {
                search.value = existing.name;
                unit.value = existing.unit;
            }
        }

        search.addEventListener('input', filter);
        search.addEventListener('focus', function () {
            if (String(search.value || '').trim() !== '') filter();
        });
        document.addEventListener('click', function (e) {
            if (!suggest.contains(e.target) && e.target !== search) {
                suggest.hidden = true;
            }
        });

        var form = document.getElementById('mfgPurchaseAddForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                if (!hidden.value) {
                    e.preventDefault();
                    search.focus();
                    filter();
                    alert('Select an item from the search list.');
                }
            });
        }
    }

    wirePurchaseItemSearch();

    // Exports tab still uses a select for unit auto-fill
    var exportSelect = document.getElementById('export_item_id');
    var exportUnit = document.getElementById('export_unit_display');
    if (exportSelect && exportUnit) {
        function syncExportUnit() {
            var found = items.find(function (i) { return i.id === String(exportSelect.value); });
            exportUnit.value = found ? found.unit : '';
        }
        exportSelect.addEventListener('change', syncExportUnit);
        syncExportUnit();
    }
})();
</script>
@if ($tab === 'purchases')
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'mfgPurchasesFilterForm', 'dateFromId' => 'purchases_date_from', 'dateToId' => 'purchases_date_to'])
    @include('reports.partials.export-from-form-script', ['formId' => 'mfgPurchasesFilterForm'])
@endif
@if ($tab === 'exports')
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'mfgExportsFilterForm', 'dateFromId' => 'exports_date_from', 'dateToId' => 'exports_date_to'])
    @include('reports.partials.export-from-form-script', ['formId' => 'mfgExportsFilterForm'])
@endif
@endpush
