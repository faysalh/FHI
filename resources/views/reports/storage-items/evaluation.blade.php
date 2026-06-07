@extends('reports.layouts.app')
@section('title', 'Storage items evaluation')
@section('container-class', 'report-container--wide')

@section('content')
@php $q = request()->query(); @endphp
    <div class="subtabs">
        <a href="{{ route('reports.storage-items.index', $q) }}">Inventory & sales</a>
        <a href="{{ route('reports.storage-items.evaluation', $q) }}" class="active">Evaluation (price / KG)</a>
    </div>

    <header class="page-header">
        <h1>Storage items — evaluation</h1>
    </header>
        @php $wdUi = max(1, min(366, (int) ($filters['working_days'] ?? 1))); @endphp
        <p class="hint">
        Same data as <a href="{{ route('reports.storage-items.index', $q) }}">Inventory & sales</a>, plus <strong>weight</strong>, editable <strong>price per KG (IQD)</strong>, and <strong>total value</strong> (weight × price). Drag rows to set priority; use category shortcuts to fill prices.
        <strong>Forecast</strong> under 5: row <span class="forecast-legend forecast-legend--critical">red</span>; under 10 (≥ 5): <span class="forecast-legend forecast-legend--warning">orange</span>.
        </p>

    @if ($errors->has('exclude_categories'))
        <div class="error">{{ $errors->first('exclude_categories') }}</div>
    @endif

    <form id="storage-items-filter-form" method="GET" action="{{ route('reports.storage-items.evaluation') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                <div class="filters-grid">
        @include('reports.storage-items.partials.sales-period-filters', ['filters' => $filters, 'wdUi' => $wdUi])
        <div>
            <label for="storage">Storage (optional)</label>
            <select id="storage" name="storage">
                <option value="">All storages</option>
                @foreach (($storageOptions ?? []) as $st)
                    <option value="{{ $st }}" @selected(($filters['storage'] ?? '') === $st)>{{ $st }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="category">Category (optional)</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                @foreach (($categoryOptions ?? []) as $cat)
                    <option value="{{ $cat }}" @selected(($filters['category'] ?? '') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        @php $excludedSet = ($filters['exclude_categories'] ?? []); @endphp
        <div class="span-full">
            <label>Exclude categories (optional)</label>
            <div class="exclude-categories-box" aria-label="Categories to exclude from inventory and sales">
                @foreach (($categoryOptions ?? []) as $cat)
                    <label>
                        <input type="checkbox" name="exclude_categories[]" value="{{ $cat }}" @checked(is_array($excludedSet) && in_array($cat, $excludedSet, true))>
                        <span>{{ $cat }}</span>
                    </label>
                @endforeach
                @if (empty($categoryOptions ?? []))
                    <span class="muted">No categories loaded</span>
                @endif
            </div>
            <div class="muted exclude-categories-hint">Checked categories are omitted from inventory and period sales totals.</div>
        </div>
        <div>
            <label for="item">Item filter (optional)</label>
            <input type="text" id="item" name="item" value="{{ $filters['item'] ?? '' }}" placeholder="item name / code / category">
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
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.storage-items.evaluation'])
                    <span class="muted">Export:</span>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage-items.export.csv') }}">CSV</a>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage-items.export.pdf') }}">PDF</a>
                    <button type="button" id="evaluation-export-csv" class="export-link" title="Export evaluation CSV" aria-label="Export evaluation CSV">Eval CSV</button>
                </div>
            </div>
        </details>
    </form>

    @if ($rows)
        <div class="eval-category-price">
            <strong style="display:block;margin-bottom:8px;">Set Price/KG by category</strong>
            <div id="eval-category-price-grid" class="eval-category-price-grid"></div>
        </div>

        <table class="eval-table" id="evaluation-table">
            <thead>
            <tr>
                <th class="drag-handle">↕</th>
                <th>Category</th>
                <th>Item code</th>
                <th>Item name</th>
                <th class="num">Carton</th>
                <th class="num">Sales</th>
                <th class="num">Sales average</th>
                <th class="num">Forecast</th>
                <th class="num">Weight (kg)</th>
                <th class="num">Price/KG (IQD)</th>
                <th class="num">Total value (IQD)</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php
                    $weight = (float) ($row->weight_total ?? 0);
                    $quantity = (float) ($row->quantity_total ?? 0);
                    $sold = (float) ($row->sold_quantity_period ?? 0);
                    $avgWd = $sold / $wdUi;
                    $cover = ($sold > 0) ? ($quantity / $avgWd) : null;
                    $categoryName = trim((string) ($row->category_name ?? ''));
                @endphp
                <tr draggable="true"
                    @class([
                        'forecast-below-5' => $cover !== null && $cover < 5,
                        'forecast-below-10' => $cover !== null && $cover >= 5 && $cover < 10,
                    ])
                    data-category="{{ $categoryName }}"
                    data-weight="{{ $weight }}"
                    data-quantity-storage="{{ $quantity }}"
                    data-sold-period="{{ $sold }}"
                    data-avg-per-wd="{{ $avgWd }}"
                    data-cover="{{ $cover !== null ? $cover : '' }}">
                    <td class="drag-handle">⋮⋮</td>
                    <td>{{ $categoryName }}</td>
                    <td>{{ $row->item_code ?? '' }}</td>
                    <td>{{ $row->item_name ?? '' }}</td>
                    <td class="num">{{ display_number($quantity) }}</td>
                    <td class="num">{{ display_number($sold) }}</td>
                    <td class="num">{{ display_number($avgWd) }}</td>
                    <td class="num">@if ($cover !== null){{ display_number($cover) }}@else<span class="dash">—</span>@endif</td>
                    <td class="num eval-weight">{{ display_number($weight) }}</td>
                    <td class="num"><input type="number" class="eval-input eval-price" step="0.01" min="0" value=""></td>
                    <td class="num eval-value">0</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="eval-category-sum">
            <table>
                <thead>
                <tr>
                    <th>Category subtotal</th>
                    <th class="num">Carton</th>
                    <th class="num">Sales</th>
                    <th class="num">Weight (kg)</th>
                    <th class="num">Total value (IQD)</th>
                </tr>
                </thead>
                <tbody id="eval-category-totals-body"></tbody>
            </table>
        </div>
        <div class="eval-total">
            <div><strong>Total weight:</strong> <span id="eval-total-weight">0</span> kg</div>
            <div><strong>Total value:</strong> <span id="eval-total-value">0</span> IQD</div>
        </div>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif
</div>

@include('reports.partials.quick-date-buttons-script', [
    'formId' => 'storage-items-filter-form',
    'fromId' => 'sales_date_from',
    'toId' => 'sales_date_to',
])
@include('reports.partials.export-from-form-script', ['formId' => 'storage-items-filter-form', 'linkClass' => 'storage-export-link'])
@if ($rows)
<script>
(function () {
    var workingDaysUi = Number({{ json_encode($wdUi) }});

    var table = document.getElementById('evaluation-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    var totalWeightEl = document.getElementById('eval-total-weight');
    var totalValueEl = document.getElementById('eval-total-value');
    var categoryTotalsBody = document.getElementById('eval-category-totals-body');
    var categoryPriceGrid = document.getElementById('eval-category-price-grid');

    function fmt(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(n));
    }

    function recalcTotals() {
        var totalWeight = 0;
        var totalValue = 0;
        var categoryTotals = {};
        var categoryOrder = [];
        tbody.querySelectorAll('tr').forEach(function (row) {
            var weight = Number(row.getAttribute('data-weight') || 0);
            var qtySt = Number(row.getAttribute('data-quantity-storage') || 0);
            var soldP = Number(row.getAttribute('data-sold-period') || 0);
            var category = (row.getAttribute('data-category') || '').trim();
            var priceInput = row.querySelector('.eval-price');
            var valueCell = row.querySelector('.eval-value');
            var price = Number((priceInput && priceInput.value) || 0);
            var value = weight * price;
            totalWeight += weight;
            totalValue += value;
            if (category !== '') {
                if (!categoryTotals[category]) {
                    categoryTotals[category] = { qtyStorage: 0, soldPeriod: 0, weight: 0, value: 0 };
                    categoryOrder.push(category);
                }
                categoryTotals[category].qtyStorage += qtySt;
                categoryTotals[category].soldPeriod += soldP;
                categoryTotals[category].weight += weight;
                categoryTotals[category].value += value;
            }
            if (valueCell) valueCell.textContent = fmt(value);
        });
        if (totalWeightEl) totalWeightEl.textContent = fmt(totalWeight);
        if (totalValueEl) totalValueEl.textContent = fmt(totalValue);
        if (categoryTotalsBody) {
            categoryTotalsBody.innerHTML = '';
            categoryOrder.forEach(function (category) {
                var sums = categoryTotals[category];
                var tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + category + '</td>' +
                    '<td class="num">' + fmt(sums.qtyStorage) + '</td>' +
                    '<td class="num">' + fmt(sums.soldPeriod) + '</td>' +
                    '<td class="num">' + fmt(sums.weight) + '</td>' +
                    '<td class="num">' + fmt(sums.value) + '</td>';
                categoryTotalsBody.appendChild(tr);
            });
        }
    }

    function buildCategoryPriceInputs() {
        if (!categoryPriceGrid) return;
        var seen = {};
        var categories = [];
        tbody.querySelectorAll('tr').forEach(function (row) {
            var category = (row.getAttribute('data-category') || '').trim();
            if (category === '' || seen[category]) return;
            seen[category] = true;
            categories.push(category);
        });

        categoryPriceGrid.innerHTML = '';
        categories.forEach(function (category) {
            var wrap = document.createElement('div');
            wrap.className = 'eval-category-price-item';
            var lab = document.createElement('label');
            lab.textContent = 'Category: ' + category;
            var inp = document.createElement('input');
            inp.type = 'number';
            inp.step = '0.01';
            inp.min = '0';
            inp.setAttribute('data-category-price', category);
            inp.placeholder = 'Set one price for all items';
            wrap.appendChild(lab);
            wrap.appendChild(inp);
            categoryPriceGrid.appendChild(wrap);
        });

        categoryPriceGrid.querySelectorAll('input[data-category-price]').forEach(function (input) {
            function applyCategoryPrice() {
                var category = input.getAttribute('data-category-price') || '';
                if (category === '') return;
                tbody.querySelectorAll('tr').forEach(function (row) {
                    if ((row.getAttribute('data-category') || '').trim() !== category) return;
                    var priceInput = row.querySelector('.eval-price');
                    if (!priceInput) return;
                    priceInput.value = input.value;
                });
                recalcTotals();
            }
            input.addEventListener('change', applyCategoryPrice);
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                applyCategoryPrice();
            });
        });
    }

    tbody.querySelectorAll('.eval-price').forEach(function (input) {
        input.addEventListener('input', recalcTotals);
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var inputs = Array.prototype.slice.call(tbody.querySelectorAll('.eval-price'));
            var idx = inputs.indexOf(input);
            if (idx === -1 || inputs.length === 0) return;
            var next = inputs[(idx + 1) % inputs.length];
            if (next) {
                next.focus();
                next.select();
            }
        });
    });

    tbody.querySelectorAll('tr').forEach(function (row) {
        row.addEventListener('dragstart', function () {
            window._storageEvalDraggingRow = row;
            row.style.opacity = '0.5';
        });
        row.addEventListener('dragend', function () {
            row.style.opacity = '1';
            window._storageEvalDraggingRow = null;
        });
        row.addEventListener('dragover', function (e) {
            e.preventDefault();
        });
        row.addEventListener('drop', function (e) {
            e.preventDefault();
            var draggingRow = window._storageEvalDraggingRow;
            if (!draggingRow || draggingRow === row) return;
            var rect = row.getBoundingClientRect();
            var shouldInsertBefore = (e.clientY - rect.top) < (rect.height / 2);
            if (shouldInsertBefore) {
                tbody.insertBefore(draggingRow, row);
            } else {
                tbody.insertBefore(draggingRow, row.nextSibling);
            }
        });
    });

    buildCategoryPriceInputs();
    recalcTotals();

    function csvEscape(value) {
        var s = String(value === null || value === undefined ? '' : value);
        if (s.indexOf('"') >= 0 || s.indexOf(',') >= 0 || s.indexOf('\n') >= 0) {
            return '"' + s.replace(/"/g, '""') + '"';
        }
        return s;
    }

    function exportEvaluationCsv() {
        var header = ['Category', 'Item code', 'Item name', 'Carton', 'Sales', 'Working days (divisor)',
            'Sales average', 'Forecast', 'Weight (kg)', 'Price/KG (IQD)', 'Total value (IQD)'];
        var wd = Number(workingDaysUi);
        if (isNaN(wd) || wd < 1) wd = 1;
        var rows = [header];

        tbody.querySelectorAll('tr').forEach(function (row) {
            var priceInput = row.querySelector('.eval-price');
            var valueCell = row.querySelector('.eval-value');
            var category = (row.getAttribute('data-category') || '').trim();
            var qSt = Number(row.getAttribute('data-quantity-storage') || 0);
            var soldP = Number(row.getAttribute('data-sold-period') || 0);
            var avgWd = Number(row.getAttribute('data-avg-per-wd') || 0);
            var covRaw = row.getAttribute('data-cover');
            var weight = Number(row.getAttribute('data-weight') || 0);
            var cov = covRaw !== null && covRaw !== '' ? Number(covRaw) : '';
            var tds = row.querySelectorAll('td');
            var itemCode = (tds[2] && tds[2].textContent || '').trim();
            var itemName = (tds[3] && tds[3].textContent || '').trim();

            rows.push([
                category,
                itemCode,
                itemName,
                fmt(qSt),
                fmt(soldP),
                String(wd),
                fmt(avgWd),
                cov === '' || isNaN(Number(cov)) ? '' : fmt(Number(cov)),
                fmt(weight),
                (priceInput && priceInput.value ? priceInput.value : '0').trim(),
                (valueCell && valueCell.textContent || '').trim().replace(/,/g, '')
            ]);
        });

        var catTotalsMap = {};
        var catOrder = [];
        tbody.querySelectorAll('tr').forEach(function (row) {
            var cat = (row.getAttribute('data-category') || '').trim();
            if (cat === '') return;
            if (!catTotalsMap[cat]) {
                catTotalsMap[cat] = { qty: 0, sold: 0, w: 0, val: 0 };
                catOrder.push(cat);
            }
            catTotalsMap[cat].qty += Number(row.getAttribute('data-quantity-storage') || 0);
            catTotalsMap[cat].sold += Number(row.getAttribute('data-sold-period') || 0);
            catTotalsMap[cat].w += Number(row.getAttribute('data-weight') || 0);
            var vc = row.querySelector('.eval-value');
            catTotalsMap[cat].val += Number((vc && vc.textContent || '0').replace(/,/g, ''));
        });
        rows.push([]);
        rows.push(['Category totals']);
        rows.push(['Category', 'Carton', 'Sales', 'Weight (kg)', 'Total value (IQD)']);
        catOrder.forEach(function (c) {
            var s = catTotalsMap[c];
            rows.push([c, fmt(s.qty), fmt(s.sold), fmt(s.w), fmt(s.val)]);
        });
        rows.push([]);
        rows.push(['Total weight', (totalWeightEl ? totalWeightEl.textContent : '0') + ' kg']);
        rows.push(['Total value', (totalValueEl ? totalValueEl.textContent : '0') + ' IQD']);

        var inventoryAsOf = @json($filters['as_of_date'] ?? now()->toDateString());
        var csv = rows.map(function (cols) { return cols.map(csvEscape).join(','); }).join('\r\n');
        var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'storage-items-evaluation-' + inventoryAsOf + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    var exportButton = document.getElementById('evaluation-export-csv');
    if (exportButton) {
        exportButton.addEventListener('click', function () {
            recalcTotals();
            exportEvaluationCsv();
        });
    }
})();
</script>
@endif
@endsection

@push('scripts')
@include('reports.storage-items.partials.sales-period-filters-script')
@endpush

@push('styles')
<style>
.subtabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .subtabs a { padding: 8px 14px; border-radius: 6px; text-decoration: none; background: #e2e8f0; color: #1e293b; font-size: 13px; font-weight: 600; }
        .subtabs a.active { background: #0f766e; color: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .eval-table tbody tr { cursor: move; }
        .drag-handle { color: #64748b; font-size: 16px; text-align: center; width: 28px; }
        .eval-input { width: 110px; text-align: right; }
        .eval-total { margin-top: 10px; padding: 10px; border-radius: 6px; background: #f8fafc; display: flex; gap: 18px; flex-wrap: wrap; font-size: 14px; }
        .eval-category-sum { margin-top: 10px; }
        .eval-category-sum table { font-size: 13px; }
        .eval-category-price { margin-top: 10px; padding: 10px; border-radius: 6px; background: #f8fafc; }
        .eval-category-price-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .eval-category-price-item
        .eval-category-price-item input { width: 100%; }
        .dash { color: #94a3b8; }
        .eval-table tbody tr.forecast-below-5 td { background-color: #fecaca; }
        .eval-table tbody tr.forecast-below-10 td { background-color: #ffedd5; }
        .exclude-categories-hint { margin-top: 4px; font-size: 11px; }
</style>
@endpush

@push('head')
@include('reports.partials.compact-filters-styles')
@endpush
