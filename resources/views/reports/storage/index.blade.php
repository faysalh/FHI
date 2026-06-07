@extends('reports.layouts.app')
@section('title', 'Storage report')
@section('container-class', 'report-container--wide')

@section('content')
<header class="page-header"><h1>Storage report</h1></header>
<p class="hint">Inventory as of selected date. Zero-quantity items hidden. <a href="{{ route('reports.storage-items.index') }}">Storage items</a> for sales and pricing.</p>

    <form id="storage-filter-form" method="GET" action="{{ route('reports.storage.index') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                <div class="filters-grid">
                    <div>
                        <label for="as_of_date">As of date</label>
                        <input type="date" id="as_of_date" name="as_of_date" value="{{ $filters['as_of_date'] }}">
                    </div>
                    <div>
                        <label for="saved_governorate_id">Governorate</label>
                        <select id="saved_governorate_id" name="saved_governorate_id">
                            <option value="">None</option>
                            @foreach (($savedGovernorates ?? []) as $gov)
                                <option value="{{ (int) ($gov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($gov->id ?? 0))>{{ $gov->name ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="per_page">Rows / page</label>
                        <select id="per_page" name="per_page">
                            @foreach ([10, 25, 50, 100, 250] as $size)
                                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 250) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Storage</label>
                        <div class="multi-picker" id="picker-storages" data-input-name="storages[]" data-placeholder="Search storage…"></div>
                    </div>
                    <div>
                        <label>Categories</label>
                        <div class="multi-picker" id="picker-categories" data-input-name="categories[]" data-placeholder="Search category…"></div>
                    </div>
                    <div>
                        <label>Exclude categories</label>
                        <div class="multi-picker" id="picker-exclude-categories" data-input-name="exclude_categories[]" data-placeholder="Exclude category…"></div>
                    </div>
                    <div>
                        <label>Store cities</label>
                        <div class="multi-picker @if(empty($hasStoreCityColumn)) is-disabled @endif" id="picker-cities" data-input-name="cities[]" data-placeholder="Search city…" @if(empty($hasStoreCityColumn)) data-disabled="1" @endif></div>
                    </div>
                    <div class="span-2">
                        <label>Items</label>
                        <div class="multi-picker" id="picker-items" data-input-name="items[]" data-placeholder="Search item…"></div>
                    </div>
                    <div class="span-2">
                        <label>Exclude items</label>
                        <div class="multi-picker" id="picker-exclude-items" data-input-name="exclude_items[]" data-placeholder="Exclude item…"></div>
                    </div>
                    <div class="span-2">
                        <span class="filter-group-label">Columns</span>
                        <div class="column-toggles">
                            <label class="column-toggle">
                                <input type="checkbox" name="show_category" value="1" @checked($filters['show_category'] ?? false)>
                                Category
                            </label>
                            <label class="column-toggle">
                                <input type="checkbox" name="show_item_code" value="1" @checked($filters['show_item_code'] ?? false)>
                                Item code
                            </label>
                        </div>
                    </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.storage.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage.export.csv') }}">CSV</a>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage.export.pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @if ($rows)
        <div class="totals-bar">
            <div class="total-item">
                <span>Total quantity (carton)</span>
                <strong class="num">{{ display_number($totals['quantity_total'] ?? 0) }}</strong>
            </div>
            <div class="total-item">
                <span>Total weight (kg)</span>
                <strong class="num">{{ display_number($totals['weight_total'] ?? 0) }}</strong>
            </div>
            <div class="muted" style="align-self:center;">{{ $rows->total() }} items · {{ count($categoryTotals ?? []) }} categories</div>
        </div>

        @php
            $showCategory = (bool) ($filters['show_category'] ?? false);
            $showItemCode = (bool) ($filters['show_item_code'] ?? false);
            $labelColspan = 1 + ($showCategory ? 1 : 0) + ($showItemCode ? 1 : 0);
        @endphp
        <table>
            <thead>
            <tr>
                <th>#</th>
                @if ($showCategory)<th>Category</th>@endif
                @if ($showItemCode)<th>Item code</th>@endif
                <th>Item name</th>
                <th class="num">Quantity (carton)</th>
                <th class="num">Weight (kg)</th>
            </tr>
            </thead>
            <tbody>
            @php
                $categoryTotalsMap = $categoryTotals ?? [];
                $rowItems = $rows->items();
            @endphp
            @foreach ($rowItems as $index => $row)
                @php
                    $cat = trim((string) ($row->category_name ?? ''));
                    if ($cat === '') {
                        $cat = '(uncategorized)';
                    }
                    $next = $rowItems[$index + 1] ?? null;
                    $nextCat = $next ? trim((string) ($next->category_name ?? '')) : '';
                    if ($nextCat === '') {
                        $nextCat = $next ? '(uncategorized)' : '';
                    }
                    $showCategorySubtotal = ($next === null && ! $rows->hasMorePages())
                        || ($next !== null && $nextCat !== $cat);
                @endphp
                <tr>
                    <td>{{ (($rows->currentPage() - 1) * $rows->perPage()) + $loop->iteration }}</td>
                    @if ($showCategory)<td>{{ $row->category_name ?? '' }}</td>@endif
                    @if ($showItemCode)<td>{{ $row->item_code ?? '' }}</td>@endif
                    <td>{{ $row->item_name ?? '' }}</td>
                    <td class="num">{{ display_number((float) ($row->quantity_total ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->weight_total ?? 0)) }}</td>
                </tr>
                @if ($showCategorySubtotal)
                    @php $catSum = $categoryTotalsMap[$cat] ?? ['quantity_total' => 0, 'weight_total' => 0]; @endphp
                    <tr class="category-subtotal">
                        <td></td>
                        <td colspan="{{ $labelColspan }}">{{ $cat }} — subtotal</td>
                        <td class="num">{{ display_number($catSum['quantity_total'] ?? 0) }}</td>
                        <td class="num">{{ display_number($catSum['weight_total'] ?? 0) }}</td>
                    </tr>
                @endif
            @endforeach
            </tbody>
            <tfoot>
            <tr class="grand-total">
                <td colspan="{{ 1 + $labelColspan }}">Grand total (all matching items)</td>
                <td class="num">{{ display_number($totals['quantity_total'] ?? 0) }}</td>
                <td class="num">{{ display_number($totals['weight_total'] ?? 0) }}</td>
            </tr>
            </tfoot>
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @else
        <p class="report-empty">No stock rows match these filters. Try clearing item or category filters, or pick a different as-of date.</p>
    @endif

@php
    $storagePickerConfig = [
        'options' => $pickerOptions ?? [],
        'initial' => [
            'storages' => $filters['storages'] ?? [],
            'categories' => $filters['categories'] ?? [],
            'exclude_categories' => $filters['exclude_categories'] ?? [],
            'items' => $filters['items'] ?? [],
            'exclude_items' => $filters['exclude_items'] ?? [],
            'cities' => $filters['cities'] ?? [],
        ],
    ];
@endphp
<script type="application/json" id="storage-picker-config">@json($storagePickerConfig)</script>
<script>
(function () {
    var form = document.getElementById('storage-filter-form');
    if (!form) return;

    var configEl = document.getElementById('storage-picker-config');
    var pickerConfig = { options: {}, initial: {} };
    try {
        pickerConfig = JSON.parse(configEl ? (configEl.textContent || '{}') : '{}');
    } catch (e) {
        pickerConfig = { options: {}, initial: {} };
    }

    function initMultiPicker(root) {
        if (!root) return;
        var inputName = root.getAttribute('data-input-name') || '';
        var placeholder = root.getAttribute('data-placeholder') || 'Type to search…';
        var disabled = root.getAttribute('data-disabled') === '1';
        var optionKey = root.id ? root.id.replace(/^picker-/, '').replace(/-/g, '_') : '';

        var allOptions = (pickerConfig.options && pickerConfig.options[optionKey]) ? pickerConfig.options[optionKey] : [];
        var initial = (pickerConfig.initial && pickerConfig.initial[optionKey]) ? pickerConfig.initial[optionKey] : [];

        root.innerHTML = ''
            + '<div class="multi-picker-chips"></div>'
            + '<div class="multi-picker-hidden"></div>'
            + '<div class="multi-picker-search-wrap">'
            + '  <input type="text" class="multi-picker-search" autocomplete="off" placeholder="' + placeholder.replace(/"/g, '&quot;') + '"' + (disabled ? ' disabled' : '') + '>'
            + '  <ul class="multi-picker-suggestions" role="listbox"></ul>'
            + '</div>';

        var chipsEl = root.querySelector('.multi-picker-chips');
        var hiddenEl = root.querySelector('.multi-picker-hidden');
        var searchInput = root.querySelector('.multi-picker-search');
        var listEl = root.querySelector('.multi-picker-suggestions');
        var selectedIds = new Set();
        var byId = {};
        allOptions.forEach(function (o) {
            if (o && o.id) byId[o.id] = o.name || o.id;
        });

        function renderChips() {
            if (!chipsEl || !hiddenEl) return;
            chipsEl.innerHTML = '';
            hiddenEl.innerHTML = '';
            selectedIds.forEach(function (id) {
                var name = byId[id] || id;
                var chip = document.createElement('span');
                chip.className = 'multi-picker-chip';
                var label = document.createElement('span');
                label.textContent = name;
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.setAttribute('aria-label', 'Remove ' + name);
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    selectedIds.delete(id);
                    renderChips();
                    if (searchInput) searchInput.focus();
                });
                chip.appendChild(label);
                chip.appendChild(rm);
                chipsEl.appendChild(chip);

                var hi = document.createElement('input');
                hi.type = 'hidden';
                hi.name = inputName;
                hi.value = id;
                hiddenEl.appendChild(hi);
            });
        }

        function closeSuggestions() {
            if (!listEl) return;
            listEl.classList.remove('is-open');
            listEl.innerHTML = '';
        }

        function highlightActive(activeIndex) {
            if (!listEl) return;
            var items = listEl.querySelectorAll('li:not(.muted-suggest)');
            items.forEach(function (li, i) { li.classList.toggle('is-active', i === activeIndex); });
        }

        function showSuggestions(matches, activeIndex) {
            if (!listEl) return;
            listEl.innerHTML = '';
            if (matches.length === 0) {
                var li = document.createElement('li');
                li.className = 'muted-suggest';
                li.textContent = 'No matches.';
                listEl.appendChild(li);
            } else {
                matches.forEach(function (opt) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.textContent = opt.name;
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    li.addEventListener('click', function () {
                        selectedIds.add(opt.id);
                        renderChips();
                        if (searchInput) searchInput.value = '';
                        closeSuggestions();
                        if (searchInput) searchInput.focus();
                    });
                    listEl.appendChild(li);
                });
            }
            listEl.classList.add('is-open');
            highlightActive(activeIndex);
        }

        function filterOptions(q) {
            var needle = (q || '').trim().toLowerCase();
            if (needle === '') return [];
            var out = [];
            for (var i = 0; i < allOptions.length; i++) {
                var o = allOptions[i];
                if (!o || !o.id || selectedIds.has(o.id)) continue;
                var name = (o.name || '').toLowerCase();
                var id = (o.id || '').toLowerCase();
                if (name.indexOf(needle) !== -1 || id.indexOf(needle) !== -1) {
                    out.push(o);
                    if (out.length >= 50) break;
                }
            }
            return out;
        }

        initial.forEach(function (id) { if (id) selectedIds.add(id); });
        renderChips();

        if (disabled || !searchInput || !listEl) return;

        var activeIndex = -1;
        searchInput.addEventListener('input', function () {
            var q = searchInput.value;
            if (q.trim() === '') {
                closeSuggestions();
                return;
            }
            var matches = filterOptions(q);
            activeIndex = matches.length ? 0 : -1;
            showSuggestions(matches, activeIndex);
        });
        searchInput.addEventListener('keydown', function (e) {
            var items = listEl.querySelectorAll('li:not(.muted-suggest)');
            if (!listEl.classList.contains('is-open') || items.length === 0) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                highlightActive(activeIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlightActive(activeIndex);
            } else if (e.key === 'Enter') {
                if (activeIndex >= 0 && items[activeIndex]) {
                    e.preventDefault();
                    items[activeIndex].click();
                }
            } else if (e.key === 'Escape') {
                closeSuggestions();
            }
        });
        document.addEventListener('click', function (e) {
            if (!root.contains(e.target)) closeSuggestions();
        });
    }

    document.querySelectorAll('.multi-picker').forEach(initMultiPicker);
})();
</script>
@include('reports.partials.export-from-form-script', ['formId' => 'storage-filter-form', 'linkClass' => 'storage-export-link'])
@endsection

@push('styles')
<style>
table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals-bar {
            display: flex; flex-wrap: wrap; gap: 16px 24px; align-items: baseline;
            background: #0f172a; color: #f8fafc; padding: 12px 16px; border-radius: 8px; margin-bottom: 12px;
        }
        .totals-bar .total-item strong { font-size: 18px; font-variant-numeric: tabular-nums; }
        .totals-bar .total-item span { font-size: 11px; color: #94a3b8; text-transform: uppercase; display: block; margin-bottom: 2px; }
        tr.category-subtotal td {
            background: #f1f5f9; font-weight: 700; border-top: 2px solid #cbd5e1;
        }
        tr.grand-total td { background: #e0f2fe; font-weight: 700; border-top: 2px solid #2563eb; }
        .multi-picker-suggestions li:hover, .multi-picker-suggestions li.is-active { background: #ecfdf5; }
        .multi-picker-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 12px; }
        .multi-picker.is-disabled .multi-picker-search { background: #f1f5f9; cursor: not-allowed; }
        .filter-group-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        .column-toggles { display: flex; flex-wrap: wrap; gap: 12px 20px; }
        .column-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; cursor: pointer; }
        .column-toggle input { margin: 0; }
</style>
@endpush

