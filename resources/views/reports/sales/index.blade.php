@extends('reports.layouts.app')
@section('title', 'Sales report')

@section('content')
<header class="page-header"><h1>Sales report</h1></header>
<p class="hint">
        Sales from store documents for the selected period. Only posted <strong>sales invoices</strong> (<code>fld_type_alias = S</code>) to clients with a salesman count — sales orders (<code>SO</code>) and purchase/inbound lines are excluded. Amount = line total minus this line’s share of invoice header and extra discounts; quantity and weight are summed from line quantities × item weight in settings.
        In <strong>By client</strong> view, click a client name for item-level detail. <strong>Category view → Totals</strong> groups by item description; check <strong>Include items</strong> to list each product under its category. If both category checkboxes are on, <em>by client</em> wins.
        <a href="{{ route('reports.identifier.index') }}#term-item_category">Identifier</a> · read-only.
    </p>

    <form id="sales-filter-form" method="GET" action="{{ route('reports.sales.index') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
                <div class="filters-body">
                    @include('reports.partials.quick-date-buttons')
                    <div class="filters-grid filters-grid--compact">
                    <div>
                        <label for="date_from">From</label>
                        <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
                    </div>
                    <div>
                        <label for="date_to">To</label>
                        <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
                    </div>
                    <div>
                        <label for="saved_governorate_id" title="Saved presets from Cities report">Governorate</label>
                        <select id="saved_governorate_id" name="saved_governorate_id">
                            <option value="">All cities</option>
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
                        <label for="storage">Storage (optional)</label>
                        <select id="storage" name="storage">
                            <option value="">All storages</option>
                            @foreach (($storageOptions ?? []) as $st)
                                <option value="{{ $st }}" @selected(($filters['storage'] ?? '') === $st)>{{ $st }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="salesman_ids" title="Ctrl/Cmd+click for multiple; empty = all">Salesmen</label>
                        <select id="salesman_ids" name="salesman_ids[]" multiple size="3" class="select-compact-multi">
                            @foreach (($salesmanOptions ?? []) as $salesman)
                                <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="group_by_client" title="Ignored when a category checkbox is on">Main view</label>
                        <select id="group_by_client" name="group_by_client">
                            <option value="1" @selected($filters['group_by_client'] ?? true)>By client</option>
                            <option value="0" @selected(!($filters['group_by_client'] ?? true))>Period totals only</option>
                        </select>
                    </div>
                    <div>
                        <label for="q" title="Only when category breakdown is enabled">Category contains</label>
                        <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. wing">
                    </div>
                    <div class="filters-breakdown filters-breakdown--inline">
                        <span class="filter-inline-label">Category view</span>
                        <div class="chk-row">
                            <label class="chk-label">
                                <input type="checkbox" id="breakdown" name="breakdown" value="1" @checked(!empty($filters['breakdown']))>
                                Totals
                            </label>
                            <label class="chk-label">
                                <input type="checkbox" id="breakdown_items" name="breakdown_items" value="1" @checked(!empty($filters['breakdown_items'])) @disabled(empty($filters['breakdown']))>
                                Include items
                            </label>
                            <label class="chk-label">
                                <input type="checkbox" name="breakdown_by_client" value="1" @checked(!empty($filters['breakdown_by_client']))>
                                By client
                            </label>
                        </div>
                    </div>
                    <div class="filters-breakdown filters-breakdown--inline span-full">
                        <span class="filter-inline-label">Show columns</span>
                        <div class="chk-row">
                            <label class="chk-label">
                                <input type="checkbox" name="include_quantity" value="1" @checked($filters['include_quantity'] ?? true)>
                                Quantity (pcs)
                            </label>
                            <label class="chk-label">
                                <input type="checkbox" name="include_amount" value="1" @checked($filters['include_amount'] ?? true)>
                                Amount (IQD)
                            </label>
                            <label class="chk-label">
                                <input type="checkbox" name="include_weight" value="1" @checked($filters['include_weight'] ?? true)>
                                Weight (kg)
                            </label>
                        </div>
                    </div>
                    <div class="span-full filters-customer-row">
                        <label for="customer-search">Clients <span class="muted">(optional — search name or code)</span></label>
                        <script type="application/json" id="customer-options-json">@json($customerOptions ?? [])</script>
                        <div class="customer-picker" id="customer-picker">
                            <div class="customer-chips" id="customer-chips" aria-live="polite"></div>
                            <div id="customer-hidden-inputs"></div>
                            <div class="customer-search-wrap">
                                <input type="text" id="customer-search" autocomplete="off" placeholder="Type to add clients…">
                                <ul class="customer-suggestions" id="customer-suggestions" role="listbox" aria-label="Matching clients"></ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.sales.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="sales-export-link export-link" data-export-base="{{ route('reports.sales.export.csv') }}">CSV</a>
                    <a href="#" class="sales-export-link export-link" data-export-base="{{ route('reports.sales.export.pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @php
        $scopeParts = [];
        if (! empty($governorateLabel ?? '')) {
            $scopeParts[] = 'Governorate: '.$governorateLabel;
        }
        if (! empty($filters['storage'] ?? '')) {
            $scopeParts[] = 'Storage: '.$filters['storage'];
        }
        if (! empty($filters['salesman_ids'] ?? [])) {
            $smNames = [];
            foreach ($salesmanOptions ?? [] as $sm) {
                if (in_array($sm['id'] ?? '', $filters['salesman_ids'], true)) {
                    $smNames[] = $sm['name'] ?? $sm['id'];
                }
            }
            if ($smNames !== []) {
                $scopeParts[] = 'Salesmen: '.implode(', ', $smNames);
            }
        }
        if (! empty($filters['customer_account_ids'] ?? [])) {
            $scopeParts[] = count($filters['customer_account_ids']).' specific client(s)';
        }
    @endphp
    @if ($scopeParts !== [])
        <p class="muted" style="margin:0 0 12px;font-size:12px;"><strong>Active scope:</strong> {{ implode(' · ', $scopeParts) }}</p>
    @endif

    <script>
    (function () {
        var form = document.getElementById('sales-filter-form');
        var root = document.getElementById('customer-picker');
        if (!form || !root) return;

        var jsonEl = document.getElementById('customer-options-json');
        var allCustomers = [];
        try {
            allCustomers = JSON.parse(jsonEl ? (jsonEl.textContent || '[]') : '[]');
        } catch (e) { allCustomers = []; }

        var selectedIds = new Set();
        var chipsEl = document.getElementById('customer-chips');
        var hiddenEl = document.getElementById('customer-hidden-inputs');
        var searchInput = document.getElementById('customer-search');
        var listEl = document.getElementById('customer-suggestions');

        var initialIds = @json($filters['customer_account_ids'] ?? []);
        var byId = {};
        allCustomers.forEach(function (c) { if (c && c.id) byId[c.id] = c.name || c.id; });

        function renderChips() {
            chipsEl.innerHTML = '';
            hiddenEl.innerHTML = '';
            selectedIds.forEach(function (id) {
                var name = byId[id] || id;
                var chip = document.createElement('span');
                chip.className = 'customer-chip';
                chip.setAttribute('data-id', id);
                var label = document.createElement('span');
                label.textContent = name;
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.setAttribute('aria-label', 'Remove ' + name);
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    selectedIds.delete(id);
                    renderChips();
                    searchInput.focus();
                });
                chip.appendChild(label);
                chip.appendChild(rm);
                chipsEl.appendChild(chip);

                var hi = document.createElement('input');
                hi.type = 'hidden';
                hi.name = 'customer_account_ids[]';
                hi.value = id;
                hiddenEl.appendChild(hi);
            });
        }

        initialIds.forEach(function (id) { if (id) selectedIds.add(id); });
        renderChips();

        var activeIndex = -1;

        function closeSuggestions() {
            listEl.classList.remove('is-open');
            listEl.innerHTML = '';
            activeIndex = -1;
        }

        function showSuggestions(matches) {
            listEl.innerHTML = '';
            if (matches.length === 0) {
                var li = document.createElement('li');
                li.className = 'muted-suggest';
                li.textContent = 'No matching clients. Try another spelling.';
                listEl.appendChild(li);
            } else {
                matches.forEach(function (c, idx) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');
                    li.textContent = c.name;
                    li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    li.addEventListener('click', function () {
                        selectedIds.add(c.id);
                        renderChips();
                        searchInput.value = '';
                        closeSuggestions();
                        searchInput.focus();
                    });
                    listEl.appendChild(li);
                });
            }
            listEl.classList.add('is-open');
            activeIndex = matches.length ? 0 : -1;
            highlightActive();
        }

        function highlightActive() {
            var items = listEl.querySelectorAll('li:not(.muted-suggest)');
            items.forEach(function (li, i) {
                li.classList.toggle('is-active', i === activeIndex);
            });
        }

        function filterCustomers(q) {
            var needle = (q || '').trim().toLowerCase();
            if (needle === '') return [];
            var out = [];
            for (var i = 0; i < allCustomers.length; i++) {
                var c = allCustomers[i];
                if (!c || !c.id || selectedIds.has(c.id)) continue;
                var name = (c.name || '').toLowerCase();
                if (name.indexOf(needle) !== -1) {
                    out.push(c);
                    if (out.length >= 50) break;
                }
            }
            return out;
        }

        searchInput.addEventListener('input', function () {
            var q = searchInput.value;
            if (q.trim() === '') {
                closeSuggestions();
                return;
            }
            showSuggestions(filterCustomers(q));
        });

        searchInput.addEventListener('keydown', function (e) {
            var items = listEl.querySelectorAll('li:not(.muted-suggest)');
            if (!listEl.classList.contains('is-open') || items.length === 0) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                highlightActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                highlightActive();
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

        var breakdownCb = document.getElementById('breakdown');
        var breakdownItemsCb = document.getElementById('breakdown_items');
        if (breakdownCb && breakdownItemsCb) {
            breakdownCb.addEventListener('change', function () {
                if (!breakdownCb.checked) {
                    breakdownItemsCb.checked = false;
                    breakdownItemsCb.disabled = true;
                } else {
                    breakdownItemsCb.disabled = false;
                }
            });
        }
    })();
    </script>
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'sales-filter-form'])
    @include('reports.partials.export-from-form-script', ['formId' => 'sales-filter-form', 'linkClass' => 'sales-export-link'])

    @php
        $showQuantity = ! empty($filters['include_quantity']);
        $showAmount = ! empty($filters['include_amount']);
        $showWeight = ! empty($filters['include_weight']);
        $metricColCount = ($showQuantity ? 1 : 0) + ($showAmount ? 1 : 0) + ($showWeight ? 1 : 0);
        $metricFlags = [
            'showQuantity' => $showQuantity,
            'showAmount' => $showAmount,
            'showWeight' => $showWeight,
        ];
    @endphp

    @if ($mode === 'totals' && !empty($grandTotals))
        @include('reports.partials.metric-grand-totals-bar', array_merge([
            'grandTotals' => $grandTotals,
            'grandTotalsNote' => 'Period totals (all matching filters)',
        ], $metricFlags))
    @endif

    @if ($mode === 'by_client' && $rows)
        @include('reports.partials.metric-grand-totals-bar', array_merge(['grandTotals' => $grandTotals ?? null], $metricFlags))
        <table id="sales-client-table" data-drilldown-cols="{{ 2 + $metricColCount }}">
            <thead>
            <tr>
                <th>Client code</th>
                <th>Client name</th>
                @if ($showQuantity)
                    <th class="num">Quantity (pcs)</th>
                @endif
                @if ($showAmount)
                    <th class="num">Amount (IQD)</th>
                @endif
                @if ($showWeight)
                    <th class="num">Weight (kg)</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr class="client-row" data-account-id="{{ $row->client_account_id ?? '' }}">
                    <td>{{ $row->client_code }}</td>
                    <td>
                        @if (!empty($row->client_account_id))
                            <button type="button" class="drilldown-trigger" title="Show client line items">{{ $row->client_name }}</button>
                        @else
                            {{ $row->client_name }}
                        @endif
                    </td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 2,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
        <p class="muted" style="margin-top:8px;">Click a client name to see items sold in this period.</p>
    @endif

    @if ($mode === 'by_category' && $rows)
        @include('reports.partials.metric-grand-totals-bar', array_merge(['grandTotals' => $grandTotals ?? null], $metricFlags))
        <table>
            <thead>
            <tr>
                <th>Category (item description)</th>
                @if ($showQuantity)
                    <th class="num">Quantity (pcs)</th>
                @endif
                @if ($showAmount)
                    <th class="num">Amount (IQD)</th>
                @endif
                @if ($showWeight)
                    <th class="num">Weight (kg)</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->chicken_category ?? '' }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 1,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
        <p class="muted" style="margin-top:8px;">Category breakdown — grouped by <code>dbo.tbl_store_items.fld_description</code> (all clients). Uncategorized lines show as <em>(uncategorized)</em>. Check <strong>Include items</strong> to list each product per category.</p>
    @endif

    @if ($mode === 'by_category_items' && $rows)
        @include('reports.partials.sales-category-totals-panel', array_merge([
            'grandTotals' => $grandTotals ?? null,
            'categoryTotalsList' => $categoryTotalsList ?? [],
        ], $metricFlags))
        <table class="sales-category-items-table">
            <thead>
            <tr>
                <th>Category (item description)</th>
                <th>Item name</th>
                @if ($showQuantity)
                    <th class="num">Quantity (pcs)</th>
                @endif
                @if ($showAmount)
                    <th class="num">Amount (IQD)</th>
                @endif
                @if ($showWeight)
                    <th class="num">Weight (kg)</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @php
                $categoryTotalsMap = $categoryTotalsMap ?? [];
                $rowItems = $rows->items();
            @endphp
            @foreach ($rowItems as $index => $row)
                @php
                    $category = (string) ($row->chicken_category ?? '');
                    $isNewCategory = $index === 0
                        || $category !== (string) ($rowItems[$index - 1]->chicken_category ?? '');
                    $next = $rowItems[$index + 1] ?? null;
                    $nextCategory = $next ? (string) ($next->chicken_category ?? '') : '';
                    $showCategorySubtotal = ($next === null && ! $rows->hasMorePages())
                        || ($next !== null && $nextCategory !== $category);
                    $catSum = $categoryTotalsMap[$category] ?? null;
                @endphp
                <tr @if($isNewCategory) class="category-group-start" @endif>
                    <td>@if($isNewCategory)<strong>{{ $category }}</strong>@endif</td>
                    <td>{{ $row->item_name ?? '' }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
                @if ($showCategorySubtotal && $catSum !== null)
                    <tr class="category-subtotal">
                        <td colspan="2"><strong>{{ $category }} — subtotal</strong></td>
                        @if ($showQuantity)
                            <td class="num"><strong>{{ display_number($catSum['units_sold'] ?? 0) }}</strong></td>
                        @endif
                        @if ($showAmount)
                            <td class="num"><strong>{{ display_number($catSum['amount'] ?? 0) }}</strong></td>
                        @endif
                        @if ($showWeight)
                            <td class="num"><strong>{{ display_number($catSum['weight_total'] ?? 0, 1) }}</strong></td>
                        @endif
                    </tr>
                @endif
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 2,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
        <p class="muted" style="margin-top:8px;">Category breakdown with items — one row per product, grouped by category. Category subtotals and the summary panel use all matching lines (not only this page).</p>
    @endif

    @if ($mode === 'by_category_by_client' && $rows)
        @include('reports.partials.metric-grand-totals-bar', array_merge(['grandTotals' => $grandTotals ?? null], $metricFlags))
        <table id="sales-client-table" data-drilldown-cols="{{ 3 + $metricColCount }}">
            <thead>
            <tr>
                <th>Client code</th>
                <th>Client name</th>
                <th>Category (item description)</th>
                @if ($showQuantity)
                    <th class="num">Quantity (pcs)</th>
                @endif
                @if ($showAmount)
                    <th class="num">Amount (IQD)</th>
                @endif
                @if ($showWeight)
                    <th class="num">Weight (kg)</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr class="client-row" data-account-id="{{ $row->client_account_id ?? '' }}">
                    <td>{{ $row->client_code ?? '' }}</td>
                    <td>
                        @if (!empty($row->client_account_id))
                            <button type="button" class="drilldown-trigger" title="Show client line items">{{ $row->client_name ?? '' }}</button>
                        @else
                            {{ $row->client_name ?? '' }}
                        @endif
                    </td>
                    <td>{{ $row->chicken_category ?? '' }}</td>
                    @if ($showQuantity)
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    @endif
                    @if ($showAmount)
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    @endif
                    @if ($showWeight)
                        <td class="num">{{ display_number($row->weight_total ?? 0, 1) }}</td>
                    @endif
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', array_merge([
                'grandTotals' => $grandTotals ?? null,
                'labelColspan' => 3,
                'trailingColspan' => 0,
            ], $metricFlags))
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
        <p class="muted" style="margin-top:8px;">Category breakdown based on clients — one row per client per category. Click a client name for item detail.</p>
    @endif

    @if (in_array($mode, ['by_client', 'by_category_by_client'], true) && $rows)
    <script>
    (function () {
        var table = document.getElementById('sales-client-table');
        if (!table) return;

        var endpoint = @json(route('reports.sales.client-items'));
        var dateFrom = @json($filters['date_from'] ?? '');
        var dateTo = @json($filters['date_to'] ?? '');
        var showQuantity = @json($showQuantity ?? true);
        var showAmount = @json($showAmount ?? true);
        var showWeight = @json($showWeight ?? true);
        var colSpan = parseInt(table.getAttribute('data-drilldown-cols') || '5', 10) || 5;
        var openDrilldownRow = null;

        function fmt(n) {
            if (n === null || n === undefined || isNaN(n)) return '0';
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(n));
        }
        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        function buildDrilldownTable(rows) {
            var metricCols = (showQuantity ? 1 : 0) + (showAmount ? 1 : 0) + (showWeight ? 1 : 0);
            var html = '<table class="drilldown-table"><thead><tr>' +
                '<th>Category</th><th>Item name</th>';
            if (showQuantity) {
                html += '<th class="num">Quantity (pcs)</th>';
            }
            if (showAmount) {
                html += '<th class="num">Amount (IQD)</th>';
            }
            if (showWeight) {
                html += '<th class="num">Weight (kg)</th>';
            }
            html += '</tr></thead><tbody>';
            if (!rows.length) {
                html += '<tr><td colspan="' + (2 + metricCols) + '" class="muted">No item lines for this client in the selected period.</td></tr>';
            } else {
                rows.forEach(function (r) {
                    html += '<tr><td>' + esc(r.item_category || '') + '</td>' +
                        '<td>' + esc(r.item_name || '') + '</td>';
                    if (showQuantity) {
                        html += '<td class="num">' + fmt(r.units_sold) + '</td>';
                    }
                    if (showAmount) {
                        html += '<td class="num">' + fmt(r.amount) + '</td>';
                    }
                    if (showWeight) {
                        html += '<td class="num">' + fmt(r.weight_total) + '</td>';
                    }
                    html += '</tr>';
                });
            }
            html += '</tbody></table>';
            return html;
        }

        table.querySelectorAll('tr.client-row').forEach(function (row) {
            var trigger = row.querySelector('.drilldown-trigger');
            if (!trigger) return;
            trigger.addEventListener('click', function () {
                var accountId = row.getAttribute('data-account-id') || '';
                if (!accountId) return;

                if (openDrilldownRow && openDrilldownRow.previousElementSibling === row) {
                    openDrilldownRow.remove();
                    openDrilldownRow = null;
                    return;
                }

                if (openDrilldownRow) {
                    openDrilldownRow.remove();
                    openDrilldownRow = null;
                }

                var holder = document.createElement('tr');
                holder.className = 'drilldown-row';
                var td = document.createElement('td');
                td.colSpan = colSpan;
                td.innerHTML = '<div class="drilldown-loading">Loading item breakdown…</div>';
                holder.appendChild(td);
                row.insertAdjacentElement('afterend', holder);
                openDrilldownRow = holder;

                var params = new URLSearchParams({
                    date_from: dateFrom,
                    date_to: dateTo,
                    client_account_id: accountId
                });

                fetch(endpoint + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            td.innerHTML = '<div class="drilldown-loading">Could not load item breakdown.</div>';
                            return;
                        }
                        td.innerHTML = buildDrilldownTable(data.rows || []);
                    })
                    .catch(function () {
                        td.innerHTML = '<div class="drilldown-loading">Could not load item breakdown.</div>';
                    });
            });
        });
    })();
    </script>
    @endif
@endsection

