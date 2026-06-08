@extends('reports.layouts.app')
@section('title', 'Cities sales report')
@section('container-class', 'report-container--wide')

@section('content')
@php $cityPage = $filters['city_page'] ?? 'overview'; @endphp
<header class="page-header">
    <h1>Cities sales report</h1>
</header>
    <nav class="sub-tabs" aria-label="Cities pages">
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'overview'])) }}"
           class="{{ $cityPage === 'overview' ? 'active' : '' }}">Overview</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'governorate-breakdown'])) }}"
           class="{{ $cityPage === 'governorate-breakdown' ? 'active' : '' }}">Governorate breakdown</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'pie-charts'])) }}"
           class="{{ $cityPage === 'pie-charts' ? 'active' : '' }}">Pie charts</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'salesman-pie'])) }}"
           class="{{ $cityPage === 'salesman-pie' ? 'active' : '' }}">Pie by salesman</a>
    </nav>

    <datalist id="report-city-names-datalist">
        @foreach (($cityNames ?? []) as $cityName)
            <option value="{{ $cityName }}"></option>
        @endforeach
    </datalist>


    @if ($cityPage === 'overview')
    <nav class="sub-tabs" aria-label="Report view">
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['panel' => 'table'])) }}"
           class="{{ ($filters['panel'] ?? 'table') === 'table' ? 'active' : '' }}">Data table</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['panel' => 'charts'])) }}"
           class="{{ ($filters['panel'] ?? 'table') === 'charts' ? 'active' : '' }}">City sales charts</a>
    </nav>

    <p class="hint">
        Store document lines for the selected dates, filtered by <strong>client city</strong> on <code>dbo.tbl_accounting_accounts</code>
        (same column as Visits). Only posted sales invoices (<code>fld_type_alias = S</code>); amount includes proportional invoice header and extra discounts (same basis as the Sales report). Category breakdowns match Sales; only the geography filter differs.
        Read-only.
    </p>

    @if (! $hasCityColumn)
        <div class="error">City column is not configured or not found. Set <code>REPORTING_ACCOUNT_CITY_COLUMN</code> in <code>.env</code> or add a city field on accounts. Charts need this column.</div>
    @endif

    <form id="cities-filter-form" method="GET" action="{{ route('reports.cities.index') }}">
        <input type="hidden" name="panel" value="{{ $filters['panel'] ?? 'table' }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                <div class="filters-grid">
        <div>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div class="filters-breakdown">
            <div class="chk-row">
                <label class="chk-label">
                    <input type="checkbox" name="breakdown" value="1" @checked(!empty($filters['breakdown']))>
                    Category breakdown
                </label>
                <label class="chk-label">
                    <input type="checkbox" name="breakdown_by_client" value="1" @checked(!empty($filters['breakdown_by_client']))>
                    Category breakdown based on clients
                </label>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label for="q">Filter category text (optional)</label>
                <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. صدر or wing">
            </div>
        </div>
        <div class="span-full">
            <label for="group_by_client">View (ignored when a category mode is on)</label>
            <select id="group_by_client" name="group_by_client">
                <option value="1" @selected($filters['group_by_client'] ?? true)>By client (account)</option>
                <option value="0" @selected(!($filters['group_by_client'] ?? true))>Period totals only</option>
            </select>
        </div>
        <div class="span-full">
            <label>Cities (optional)</label>
            <script type="application/json" id="city-options-json">@json($cityOptions ?? [])</script>
            <div class="customer-picker" id="city-picker">
                <div class="customer-chips" id="city-chips" aria-live="polite"></div>
                <div id="city-hidden-inputs"></div>
                <div class="customer-search-wrap">
                    <label for="city-search">Search city</label>
                    <input type="text" id="city-search" autocomplete="off" placeholder="City name…">
                    <ul class="customer-suggestions" id="city-suggestions" role="listbox" aria-label="Matching cities"></ul>
                </div>
                <p class="muted" style="margin-top: 8px;">Add multiple cities by searching again. Leave empty for all cities (subject to chart limit).</p>
            </div>
        </div>
        <div>
            <label for="salesman_ids">Salesmen (optional, multi-select)</label>
            <select id="salesman_ids" name="salesman_ids[]" multiple size="6">
                @foreach (($salesmanOptions ?? []) as $salesman)
                    <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
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
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.cities.index', 'params' => ['city_page' => 'overview', 'panel' => $filters['panel'] ?? 'table']])
                    <span class="muted">Export:</span>
                    <a href="#" class="cities-export-link export-link" data-export-base="{{ route('reports.cities.export.csv') }}">CSV</a>
                    <a href="#" class="cities-export-link export-link" data-export-base="{{ route('reports.cities.export.pdf') }}">PDF</a>
                    @if (($filters['panel'] ?? 'table') === 'charts')
                        <a href="#" class="cities-export-link export-link" data-append-chart-series="1" data-export-base="{{ route('reports.cities.export.chart-pdf') }}">Export chart PDF</a>
                    @endif
                </div>
            </div>
        </details>
    </form>

    <script>
    (function () {
        var form = document.getElementById('cities-filter-form');
        var root = document.getElementById('city-picker');
        if (!form || !root) return;

        var jsonEl = document.getElementById('city-options-json');
        var allCities = [];
        try {
            allCities = JSON.parse(jsonEl ? (jsonEl.textContent || '[]') : '[]');
        } catch (e) { allCities = []; }

        var selectedIds = new Set();
        var chipsEl = document.getElementById('city-chips');
        var hiddenEl = document.getElementById('city-hidden-inputs');
        var searchInput = document.getElementById('city-search');
        var listEl = document.getElementById('city-suggestions');

        var initialIds = @json($filters['cities'] ?? []);
        var byId = {};
        allCities.forEach(function (c) { if (c && c.id) byId[c.id] = c.name || c.id; });

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
                hi.name = 'cities[]';
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
                li.textContent = 'No matching cities. Try another spelling.';
                listEl.appendChild(li);
            } else {
                matches.forEach(function (c) {
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

        function filterCities(q) {
            var needle = (q || '').trim().toLowerCase();
            if (needle === '') return [];
            var out = [];
            for (var i = 0; i < allCities.length; i++) {
                var c = allCities[i];
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
            showSuggestions(filterCities(q));
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

        document.querySelectorAll('a.cities-export-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var base = a.getAttribute('data-export-base');
                if (!base) return;
                var params = new URLSearchParams(new FormData(form));
                if (a.getAttribute('data-append-chart-series') === '1') {
                    var seriesWrap = document.getElementById('city-chart-series-controls');
                    if (seriesWrap) {
                        seriesWrap.querySelectorAll('input[type="checkbox"][data-chart-series]').forEach(function (cb) {
                            if (cb.checked) {
                                params.append('chart_show[]', cb.getAttribute('data-chart-series') || '');
                            }
                        });
                    }
                }
                window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + params.toString();
            });
        });
    })();
    </script>

    @if ($mode === 'charts')
        <div class="chart-wrap">
            <h2 style="font-size:16px;margin:16px 0 8px;">Sales over time</h2>
            <p class="muted" style="margin-bottom:12px;">
                One point per calendar day in the selected range. <strong>Amount (IQD)</strong> = Σ(qty × unit price);
                <strong>Quantity (pcs)</strong> = units sold; <strong>Weight (kg)</strong> = Σ(qty × item weight);
                <strong>Customers</strong> = distinct accounts with sales that day; <strong>Invoices</strong> = distinct sales documents that day.
                City filters apply when configured. Each metric uses its own vertical scale. Use the toggles below to show or hide lines.
            </p>
            @if (count($chartTimeSeries ?? []) > 0)
                <div id="city-chart-series-controls" class="chart-series-controls" style="display:flex;flex-wrap:wrap;gap:10px 18px;margin-bottom:14px;align-items:center;">
                    <span class="muted" style="font-size:13px;">Show series:</span>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" data-chart-series="amount" checked> Amount (IQD)
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" data-chart-series="units_sold" checked> Quantity (pcs)
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" data-chart-series="weight_total" checked> Weight (kg)
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" data-chart-series="customer_count" checked> Customers
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                        <input type="checkbox" data-chart-series="invoice_count" checked> Invoices
                    </label>
                </div>
                <canvas id="city-sales-time-chart" height="200"></canvas>
                <script>
                (function () {
                    var raw = @json($chartTimeSeries);
                    function labelFor(d) {
                        if (!d) return '';
                        var s = typeof d === 'string' ? d : (d.date || d);
                        if (typeof s !== 'string') {
                            try { s = s.substring ? s : String(s); } catch (e) { return ''; }
                        }
                        if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
                        return s;
                    }
                    function num(r, k) {
                        var v = r[k];
                        if (v === undefined || v === null) return 0;
                        var n = parseFloat(v);
                        return isNaN(n) ? 0 : n;
                    }
                    var labels = raw.map(function (r) { return labelFor(r.sale_date); });
                    var seriesDefs = [
                        { key: 'amount', label: 'Amount (IQD)', border: 'rgb(14, 165, 233)', bg: 'rgba(14, 165, 233, 0.08)', fill: true, yAxisID: 'y', axisTitle: 'Amount (IQD)' },
                        { key: 'units_sold', label: 'Quantity (pcs)', border: 'rgb(22, 163, 74)', bg: 'transparent', fill: false, yAxisID: 'y1', axisTitle: 'Qty (pcs)' },
                        { key: 'weight_total', label: 'Weight (kg)', border: 'rgb(234, 88, 12)', bg: 'transparent', fill: false, yAxisID: 'y2', axisTitle: 'Weight (kg)' },
                        { key: 'customer_count', label: 'Customers', border: 'rgb(124, 58, 237)', bg: 'transparent', fill: false, yAxisID: 'y3', axisTitle: 'Customers' },
                        { key: 'invoice_count', label: 'Invoices', border: 'rgb(219, 39, 119)', bg: 'transparent', fill: false, yAxisID: 'y4', axisTitle: 'Invoices' }
                    ];
                    var datasets = seriesDefs.map(function (def) {
                        return {
                            label: def.label,
                            data: raw.map(function (r) { return num(r, def.key); }),
                            borderColor: def.border,
                            backgroundColor: def.bg,
                            fill: def.fill,
                            tension: 0.25,
                            yAxisID: def.yAxisID,
                            pointRadius: 2,
                            hidden: false
                        };
                    });
                    var el = document.getElementById('city-sales-time-chart');
                    var controls = document.getElementById('city-chart-series-controls');
                    if (!el || typeof Chart === 'undefined') return;
                    var chart = new Chart(el, {
                        type: 'line',
                        data: { labels: labels, datasets: datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            interaction: { mode: 'index', intersect: false },
                            scales: {
                                x: {
                                    title: { display: true, text: 'Date' },
                                    ticks: { maxRotation: 45, minRotation: 0, autoSkip: true, maxTicksLimit: 20 }
                                },
                                y: {
                                    type: 'linear',
                                    position: 'left',
                                    beginAtZero: true,
                                    title: { display: true, text: 'Amount (IQD)' },
                                    grid: { drawOnChartArea: true }
                                },
                                y1: {
                                    type: 'linear',
                                    position: 'right',
                                    beginAtZero: true,
                                    title: { display: true, text: seriesDefs[1].axisTitle },
                                    grid: { drawOnChartArea: false }
                                },
                                y2: {
                                    type: 'linear',
                                    position: 'right',
                                    offset: true,
                                    beginAtZero: true,
                                    title: { display: true, text: seriesDefs[2].axisTitle },
                                    grid: { drawOnChartArea: false }
                                },
                                y3: {
                                    type: 'linear',
                                    position: 'right',
                                    offset: true,
                                    beginAtZero: true,
                                    title: { display: true, text: seriesDefs[3].axisTitle },
                                    grid: { drawOnChartArea: false }
                                },
                                y4: {
                                    type: 'linear',
                                    position: 'right',
                                    offset: true,
                                    beginAtZero: true,
                                    title: { display: true, text: seriesDefs[4].axisTitle },
                                    grid: { drawOnChartArea: false }
                                }
                            }
                        }
                    });
                    function syncFromCheckboxes(changedBox) {
                        if (!controls) return;
                        var boxes = controls.querySelectorAll('input[type="checkbox"][data-chart-series]');
                        var checkedCount = 0;
                        boxes.forEach(function (b) { if (b.checked) checkedCount++; });
                        if (checkedCount === 0 && changedBox) {
                            changedBox.checked = true;
                            return;
                        }
                        seriesDefs.forEach(function (def, i) {
                            var cb = controls.querySelector('input[data-chart-series="' + def.key + '"]');
                            chart.data.datasets[i].hidden = !(cb && cb.checked);
                        });
                        chart.update();
                    }
                    if (controls) {
                        controls.querySelectorAll('input[type="checkbox"][data-chart-series]').forEach(function (cb) {
                            cb.addEventListener('change', function () { syncFromCheckboxes(cb); });
                        });
                        syncFromCheckboxes(null);
                    }
                })();
                </script>
            @else
                <p class="muted">No sales rows matched these filters for the chart (try widening the date range or clearing city filters).</p>
            @endif
        </div>
    @endif

    @if ($mode === 'totals' && !empty($grandTotals))
        @include('reports.partials.metric-grand-totals-bar', ['grandTotals' => $grandTotals, 'grandTotalsNote' => 'Period totals (all matching filters)'])
    @endif

    @if ($mode === 'by_client' && $rows)
        @include('reports.partials.metric-grand-totals-bar', ['grandTotals' => $grandTotals ?? null])
        <table>
            <thead>
            <tr>
                <th>Client code</th>
                <th>Client name</th>
                <th class="num">Quantity (pcs)</th>
                <th class="num">Amount (IQD)</th>
                <th class="num">Weight (kg)</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->client_code }}</td>
                    <td>{{ $row->client_name }}</td>
                    <td class="num">{{ display_number($row->units_sold) }}</td>
                    <td class="num">{{ display_number($row->amount) }}</td>
                    <td class="num">{{ display_number($row->weight_total) }}</td>
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', ['grandTotals' => $grandTotals ?? null, 'labelColspan' => 2, 'trailingColspan' => 0])
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif

    @if ($mode === 'by_category' && $rows)
        @include('reports.partials.metric-grand-totals-bar', ['grandTotals' => $grandTotals ?? null])
        <table>
            <thead>
            <tr>
                <th>Category (item description)</th>
                <th class="num">Quantity (pcs)</th>
                <th class="num">Amount (IQD)</th>
                <th class="num">Weight (kg)</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->chicken_category ?? '' }}</td>
                    <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', ['grandTotals' => $grandTotals ?? null, 'labelColspan' => 1, 'trailingColspan' => 0])
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif

    @if ($mode === 'by_category_by_client' && $rows)
        @include('reports.partials.metric-grand-totals-bar', ['grandTotals' => $grandTotals ?? null])
        <table>
            <thead>
            <tr>
                <th>Client code</th>
                <th>Client name</th>
                <th>Category (item description)</th>
                <th class="num">Quantity (pcs)</th>
                <th class="num">Amount (IQD)</th>
                <th class="num">Weight (kg)</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->client_code ?? '' }}</td>
                    <td>{{ $row->client_name ?? '' }}</td>
                    <td>{{ $row->chicken_category ?? '' }}</td>
                    <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                    <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                    <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            @include('reports.partials.metric-grand-totals-tfoot', ['grandTotals' => $grandTotals ?? null, 'labelColspan' => 3, 'trailingColspan' => 0])
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif
    @elseif ($cityPage === 'governorate-breakdown')
        <p class="hint">
            Select one city as the governorate and other cities that belong to it. The report shows sales by item category,
            with city-by-category breakdown for the selected governorate mapping.
            Save or edit presets under @if (\App\Support\ReportAuthSession::canAccessReport('governorates'))<a href="{{ route('reports.governorates.index') }}">Settings → Governorates</a>@else Settings → Governorates (ask an administrator for access) @endif.
        </p>
        @if (!empty($governorateStorageError))
            <div class="alert alert--error">{{ $governorateStorageError }}</div>
        @endif
        <form method="GET" action="{{ route('reports.cities.index') }}">
            <input type="hidden" name="city_page" value="governorate-breakdown">
            <details class="filters-panel" open>
                <summary>Filters</summary>
                <div class="filters-body">
                    <div class="filters-grid">
            <div>
                <label for="date_from_gov">From</label>
                <input type="date" id="date_from_gov" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div>
                <label for="date_to_gov">To</label>
                <input type="date" id="date_to_gov" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div>
                <label for="saved_governorate_id_gov">Saved governorate (optional)</label>
                <select id="saved_governorate_id_gov" name="saved_governorate_id">
                    <option value="">Manual selection</option>
                    @foreach (($savedGovernorates ?? []) as $savedGov)
                        <option value="{{ (int) ($savedGov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($savedGov->id ?? 0))>{{ $savedGov->name ?? '' }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="governorate_city">Governorate city</label>
                <input type="text" id="governorate_city" name="governorate_city" list="report-city-names-datalist" value="{{ $filters['governorate_city'] ?? '' }}" placeholder="From saved governorate or type manually" maxlength="200" style="width:100%;max-width:320px;">
            </div>
            <div>
                <label for="governorate_members">Cities in governorate (multi-select)</label>
                <select id="governorate_members" name="governorate_members[]" multiple size="6">
                    @foreach (($cityNames ?? []) as $cityName)
                        <option value="{{ $cityName }}" @selected(in_array($cityName, $filters['governorate_members'] ?? [], true))>{{ $cityName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="exclude_category_gov">Exclude category (optional)</label>
                <select id="exclude_category_gov" name="exclude_category">
                    <option value="">All categories</option>
                    @foreach (($pieCategoryOptions ?? []) as $category)
                        <option value="{{ $category }}" @selected(($filters['exclude_category'] ?? '') === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="salesman_ids_gov">Salesmen (optional, multi-select)</label>
                <select id="salesman_ids_gov" name="salesman_ids[]" multiple size="6">
                    @foreach (($salesmanOptions ?? []) as $salesman)
                        <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="per_page_gov">Rows per page</label>
                <select id="per_page_gov" name="per_page">
                    @foreach ([10, 25, 50, 100, 250] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 250) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
                    </div>
                    <div class="filters-actions">
                        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                        @include('reports.partials.filters-reset-link', ['route' => 'reports.cities.index', 'params' => ['city_page' => 'governorate-breakdown']])
                    </div>
                </div>
            </details>
        </form>

        @if ($governorateRows)
            <table>
                <thead>
                <tr>
                    <th>City</th>
                    <th>Item category</th>
                    <th class="num">Quantity (pcs)</th>
                    <th class="num">Amount (IQD)</th>
                    <th class="num">Weight (kg)</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($governorateRows as $row)
                    <tr>
                        <td>{{ $row->city_name ?? '' }}</td>
                        <td>{{ $row->item_category ?? '' }}</td>
                        <td class="num">{{ display_number($row->units_sold ?? 0) }}</td>
                        <td class="num">{{ display_number($row->amount ?? 0) }}</td>
                        <td class="num">{{ display_number($row->weight_total ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="muted">No rows match the selected governorate mapping.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            @include('reports.partials.pagination', ['paginator' => $governorateRows])
        @endif
    @elseif ($cityPage === 'salesman-pie')
        @php
            $salesmanPieItems = [];
            $salesmanPieTotal = 0.0;
            foreach ($pieSeriesBySalesman ?? [] as $row) {
                $amount = (float) ($row->amount ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $label = trim((string) ($row->salesman_name ?? ''));
                if ($label === '') {
                    $label = '(unknown)';
                }
                $salesmanPieItems[] = ['label' => $label, 'amount' => $amount];
                $salesmanPieTotal += $amount;
            }
            $salesmanPieStats = [
                'items' => $salesmanPieItems,
                'total' => $salesmanPieTotal,
                'count' => count($salesmanPieItems),
            ];
            $salesmanPieActiveFilters = [];
            if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
                $salesmanPieActiveFilters[] = ($filters['date_from'] ?? '…').' → '.($filters['date_to'] ?? '…');
            }
            if (($filters['saved_governorate_id'] ?? '') !== '') {
                foreach (($savedGovernorates ?? []) as $savedGov) {
                    if ((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($savedGov->id ?? 0)) {
                        $salesmanPieActiveFilters[] = 'Governorate: '.($savedGov->name ?? '');
                        break;
                    }
                }
            }
            if (! empty($filters['cities'] ?? [])) {
                $salesmanPieActiveFilters[] = count($filters['cities']).' '.(count($filters['cities']) === 1 ? 'city' : 'cities');
            }
            if (($filters['exclude_category'] ?? '') !== '') {
                $salesmanPieActiveFilters[] = 'Exclude: '.($filters['exclude_category'] ?? '');
            }
            if (! empty($filters['salesman_ids'] ?? [])) {
                $salesmanPieActiveFilters[] = count($filters['salesman_ids']).' salesman filter'.(count($filters['salesman_ids']) === 1 ? '' : 's');
            }
            $hasSalesmanPieData = $salesmanPieStats['count'] > 0;
        @endphp

        @if (! empty($governorateStorageError ?? null))
            <div class="alert alert--error" role="alert">{{ $governorateStorageError }}</div>
        @endif

        <p class="lab-desc">
            Doughnut chart shows <strong>sales amount share by salesman</strong> for posted invoices in the selected period.
            Up to <strong>50 salesmen</strong> are shown (largest amounts first). Use the breakdown table for exact values; click legend items to hide slices.
        </p>

        <form id="salesman-pie-filter-form" method="GET" action="{{ route('reports.cities.index') }}">
            <input type="hidden" name="city_page" value="salesman-pie">
            <details class="filters-panel" open>
                <summary>Filters</summary>
                <div class="filters-body">
                    @include('reports.partials.quick-date-buttons')
                    <div class="filters-grid">
                        <div>
                            <label for="date_from_salesman_pie">From</label>
                            <input type="date" id="date_from_salesman_pie" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                        </div>
                        <div>
                            <label for="date_to_salesman_pie">To</label>
                            <input type="date" id="date_to_salesman_pie" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                        </div>
                        <div>
                            <label for="saved_governorate_id_salesman_pie">Saved governorate (optional)</label>
                            <select id="saved_governorate_id_salesman_pie" name="saved_governorate_id">
                                <option value="">No governorate preset</option>
                                @foreach (($savedGovernorates ?? []) as $savedGov)
                                    <option value="{{ (int) ($savedGov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($savedGov->id ?? 0))>{{ $savedGov->name ?? '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="exclude_category_salesman_pie">Exclude category (optional)</label>
                            <select id="exclude_category_salesman_pie" name="exclude_category">
                                <option value="">All categories</option>
                                @foreach (($pieCategoryOptions ?? []) as $category)
                                    <option value="{{ $category }}" @selected(($filters['exclude_category'] ?? '') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="salesman_ids_salesman_pie">Salesmen filter (optional, multi-select)</label>
                            <select id="salesman_ids_salesman_pie" name="salesman_ids[]" multiple size="5">
                                @foreach (($salesmanOptions ?? []) as $salesman)
                                    <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="span-full">
                            <label>Cities (optional)</label>
                            @include('reports.partials.city-picker', [
                                'pickerId' => 'salesman-pie',
                                'cityOptions' => $cityOptions ?? [],
                                'selectedCities' => $filters['cities'] ?? [],
                                'note' => 'Add cities one at a time. Leave empty for all cities, or pick a saved governorate preset above.',
                            ])
                        </div>
                    </div>
                    <div class="filters-actions">
                        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                        <a class="export-link" href="{{ route('reports.cities.index', ['city_page' => 'salesman-pie']) }}">Reset filters</a>
                    </div>
                </div>
            </details>
        </form>

        @if ($salesmanPieActiveFilters !== [])
            <div class="pie-active-filters" aria-label="Active filters">
                @foreach ($salesmanPieActiveFilters as $pill)
                    <span class="pie-active-filters__pill">{{ $pill }}</span>
                @endforeach
            </div>
        @endif

        <section class="lab-card pie-summary-card" aria-label="Period summary">
            <div class="pie-summary-card__grid">
                <div>
                    <p class="lab-kpi__label">Period</p>
                    <p class="lab-kpi__value" style="font-size:1.05rem;">{{ $filters['date_from'] ?? '—' }} → {{ $filters['date_to'] ?? '—' }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">Chart total</p>
                    <p class="lab-kpi__value">{{ display_number($salesmanPieStats['total']) }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">Salesmen shown</p>
                    <p class="lab-kpi__value">{{ display_number($salesmanPieStats['count'], 0) }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">Top share</p>
                    <p class="lab-kpi__value">
                        @if ($hasSalesmanPieData)
                            {{ display_number(($salesmanPieStats['items'][0]['amount'] / max($salesmanPieStats['total'], 1)) * 100, 1) }}%
                        @else
                            —
                        @endif
                    </p>
                </div>
            </div>
        </section>

        <div class="pie-charts-grid">
            <section class="lab-card pie-chart-card pie-chart-card--salesman" aria-labelledby="pie-card-title-salesman">
                <header class="pie-chart-card__head">
                    <div class="pie-chart-card__titles">
                        <h2 class="lab-card__title" id="pie-card-title-salesman">
                            By salesman
                            <span class="lab-tag">Team mix</span>
                        </h2>
                        <p class="pie-chart-card__hint">How sales amount is distributed across salesmen for the selected cities and period (includes unassigned invoices when present).</p>
                    </div>
                    <div class="pie-chart-card__total">
                        <span class="pie-chart-card__total-label">Chart total</span>
                        <strong>{{ display_number($salesmanPieStats['total']) }}</strong>
                        <span class="pie-chart-card__total-meta">{{ display_number($salesmanPieStats['count'], 0) }} slice{{ $salesmanPieStats['count'] === 1 ? '' : 's' }}</span>
                    </div>
                </header>
                <div class="pie-chart-card__body">
                    <div class="pie-chart-card__viz">
                        <div class="pie-donut-wrap">
                            <canvas id="pie-salesman-chart" aria-hidden="{{ $hasSalesmanPieData ? 'false' : 'true' }}" @if(! $hasSalesmanPieData) hidden @endif></canvas>
                            <div id="pie-salesman-chart-empty" class="dash-chart-empty" @if($hasSalesmanPieData) hidden @endif>
                                No sales for these filters in this period.
                            </div>
                        </div>
                    </div>
                    <div class="pie-chart-card__table-wrap">
                        @if ($hasSalesmanPieData)
                            <table class="lab-table pie-breakdown-table">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Salesman</th>
                                        <th scope="col" class="num">Amount</th>
                                        <th scope="col" class="num">Share</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($salesmanPieStats['items'] as $index => $item)
                                        @php
                                            $share = $salesmanPieStats['total'] > 0 ? ($item['amount'] / $salesmanPieStats['total']) * 100 : 0;
                                        @endphp
                                        <tr>
                                            <td class="pie-breakdown-table__rank">{{ $index + 1 }}</td>
                                            <td>{{ $item['label'] }}</td>
                                            <td class="num">{{ display_number($item['amount']) }}</td>
                                            <td class="num">{{ display_number($share, 1) }}%</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="lab-table__sum">
                                        <td colspan="2">Total (shown slices)</td>
                                        <td class="num">{{ display_number($salesmanPieStats['total']) }}</td>
                                        <td class="num">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        @else
                            <p class="muted pie-breakdown-empty">Adjust dates or filters, then apply to load a breakdown table.</p>
                        @endif
                    </div>
                </div>
            </section>
        </div>

        <script>
        (function () {
            if (typeof Chart === 'undefined') return;

            var palette = ['#6366f1', '#14b8a6', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316', '#0ea5e9', '#a855f7'];
            var rows = @json($pieSeriesBySalesman ?? []);
            var chartTotal = @json($salesmanPieStats['total']);

            var doughnutCenterPlugin = {
                id: 'doughnutCenterText',
                beforeDraw: function (chart) {
                    var total = chart.config.options.plugins.doughnutCenterText.total;
                    if (!total || total <= 0) return;
                    var ctx = chart.ctx;
                    var meta = chart.getDatasetMeta(0);
                    if (!meta || !meta.data || !meta.data[0]) return;
                    var arc = meta.data[0];
                    var x = arc.x;
                    var y = arc.y;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#64748b';
                    ctx.font = '600 11px system-ui, sans-serif';
                    ctx.fillText('Total', x, y - 8);
                    ctx.fillStyle = '#0f172a';
                    ctx.font = '700 14px system-ui, sans-serif';
                    ctx.fillText(total, x, y + 10);
                    ctx.restore();
                }
            };
            Chart.register(doughnutCenterPlugin);

            function fmtAmount(n) {
                return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
            }

            var labels = [];
            var values = [];
            (rows || []).forEach(function (r) {
                var amount = parseFloat(r.amount || 0);
                if (!isFinite(amount) || amount <= 0) return;
                var label = String(r.salesman_name || '').trim();
                if (!label) label = '(unknown)';
                labels.push(label);
                values.push(amount);
            });

            var canvas = document.getElementById('pie-salesman-chart');
            var emptyEl = document.getElementById('pie-salesman-chart-empty');
            if (!canvas) return;
            var hasData = labels.length > 0;
            if (emptyEl) emptyEl.hidden = hasData;
            canvas.hidden = !hasData;
            if (!hasData) return;

            var colors = labels.map(function (_, i) { return palette[i % palette.length]; });
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors,
                        borderColor: '#fff',
                        borderWidth: 2,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    layout: { padding: 4 },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 10,
                                font: { size: 11 },
                                usePointStyle: true
                            }
                        },
                        doughnutCenterText: {
                            total: fmtAmount(chartTotal)
                        },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var arr = ctx.dataset.data || [];
                                    var total = arr.reduce(function (a, b) { return a + b; }, 0);
                                    var value = Number(ctx.parsed || 0);
                                    var pct = total > 0 ? ((value / total) * 100) : 0;
                                    return (ctx.label || '') + ': ' + fmtAmount(value) + ' (' + pct.toFixed(1) + '%)';
                                }
                            }
                        }
                    }
                }
            });
        })();
        </script>
        @include('reports.partials.quick-date-buttons-script', [
            'formId' => 'salesman-pie-filter-form',
            'fromId' => 'date_from_salesman_pie',
            'toId' => 'date_to_salesman_pie',
        ])
        @include('reports.partials.city-picker-script', [
            'pickerId' => 'salesman-pie',
            'selectedCities' => $filters['cities'] ?? [],
        ])
    @elseif ($cityPage === 'pie-charts')
        @php
            $pieStatsFromRows = static function (array $rows, string $labelKey): array {
                $items = [];
                $total = 0.0;
                foreach ($rows as $row) {
                    $amount = (float) ($row->amount ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }
                    $label = trim((string) ($row->{$labelKey} ?? ''));
                    if ($label === '') {
                        $label = '(unknown)';
                    }
                    $items[] = ['label' => $label, 'amount' => $amount];
                    $total += $amount;
                }

                return ['items' => $items, 'total' => $total, 'count' => count($items)];
            };
            $pieCityStats = $pieStatsFromRows($pieSeriesByCity ?? [], 'city_name');
            $pieCategoryStats = $pieStatsFromRows($pieSeriesByCategory ?? [], 'item_category');
            $pieItemStats = $pieStatsFromRows($pieSeriesByItem ?? [], 'item_name');
            $pieCharts = [
                [
                    'slug' => 'city',
                    'title' => 'By city',
                    'tag' => 'Geography',
                    'hint' => 'Share of sales amount across client cities (top 50 by amount).',
                    'labelKey' => 'city_name',
                    'rows' => $pieSeriesByCity ?? [],
                    'stats' => $pieCityStats,
                ],
                [
                    'slug' => 'category',
                    'title' => 'By category',
                    'tag' => 'Product mix',
                    'hint' => 'How sales amount splits across item categories in the selected scope.',
                    'labelKey' => 'item_category',
                    'rows' => $pieSeriesByCategory ?? [],
                    'stats' => $pieCategoryStats,
                ],
                [
                    'slug' => 'item',
                    'title' => 'By item',
                    'tag' => 'SKU mix',
                    'hint' => ($filters['pie_category'] ?? '') !== ''
                        ? 'Items within category “'.($filters['pie_category'] ?? '').'”.'
                        : 'Top items across all categories — pick a category filter to focus one product line.',
                    'labelKey' => 'item_name',
                    'rows' => $pieSeriesByItem ?? [],
                    'stats' => $pieItemStats,
                ],
            ];
            $pieChartsJs = array_map(
                static fn (array $chart): array => [
                    'slug' => $chart['slug'],
                    'labelKey' => $chart['labelKey'],
                    'rows' => $chart['rows'],
                    'total' => $chart['stats']['total'],
                ],
                $pieCharts
            );
            $pieActiveFilters = [];
            if (($filters['date_from'] ?? '') !== '' || ($filters['date_to'] ?? '') !== '') {
                $pieActiveFilters[] = ($filters['date_from'] ?? '…').' → '.($filters['date_to'] ?? '…');
            }
            if (($filters['saved_governorate_id'] ?? '') !== '') {
                foreach (($savedGovernorates ?? []) as $savedGov) {
                    if ((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($savedGov->id ?? 0)) {
                        $pieActiveFilters[] = 'Governorate: '.($savedGov->name ?? '');
                        break;
                    }
                }
            }
            if (! empty($filters['cities'] ?? [])) {
                $pieActiveFilters[] = count($filters['cities']).' '.(count($filters['cities']) === 1 ? 'city' : 'cities');
            }
            if (($filters['pie_category'] ?? '') !== '') {
                $pieActiveFilters[] = 'Item category: '.($filters['pie_category'] ?? '');
            }
            if (($filters['exclude_category'] ?? '') !== '') {
                $pieActiveFilters[] = 'Exclude: '.($filters['exclude_category'] ?? '');
            }
            if (! empty($filters['salesman_ids'] ?? [])) {
                $pieActiveFilters[] = count($filters['salesman_ids']).' salesman filter'.(count($filters['salesman_ids']) === 1 ? '' : 's');
            }
        @endphp

        @if (! empty($governorateStorageError ?? null))
            <div class="alert alert--error" role="alert">{{ $governorateStorageError }}</div>
        @endif

        <p class="lab-desc">
            Doughnut charts show <strong>sales amount share</strong> for posted invoices in the selected period.
            Each chart lists up to <strong>50 slices</strong> (largest amounts first). Use the breakdown table beside each chart for exact values; click legend items to hide slices.
        </p>

        <form id="pie-charts-filter-form" method="GET" action="{{ route('reports.cities.index') }}">
            <input type="hidden" name="city_page" value="pie-charts">
            <details class="filters-panel" open>
                <summary>Filters</summary>
                <div class="filters-body">
                    @include('reports.partials.quick-date-buttons')
                    <div class="filters-grid">
                        <div>
                            <label for="date_from_pie">From</label>
                            <input type="date" id="date_from_pie" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                        </div>
                        <div>
                            <label for="date_to_pie">To</label>
                            <input type="date" id="date_to_pie" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                        </div>
                        <div>
                            <label for="saved_governorate_id_pie">Saved governorate (optional)</label>
                            <select id="saved_governorate_id_pie" name="saved_governorate_id">
                                <option value="">No governorate preset</option>
                                @foreach (($savedGovernorates ?? []) as $savedGov)
                                    <option value="{{ (int) ($savedGov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($savedGov->id ?? 0))>{{ $savedGov->name ?? '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="pie_category">Category for item chart (optional)</label>
                            <select id="pie_category" name="pie_category">
                                <option value="">All categories</option>
                                @foreach (($pieCategoryOptions ?? []) as $category)
                                    <option value="{{ $category }}" @selected(($filters['pie_category'] ?? '') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="exclude_category_pie">Exclude category (optional)</label>
                            <select id="exclude_category_pie" name="exclude_category">
                                <option value="">All categories</option>
                                @foreach (($pieCategoryOptions ?? []) as $category)
                                    <option value="{{ $category }}" @selected(($filters['exclude_category'] ?? '') === $category)>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="salesman_ids_pie">Salesmen (optional, multi-select)</label>
                            <select id="salesman_ids_pie" name="salesman_ids[]" multiple size="5">
                                @foreach (($salesmanOptions ?? []) as $salesman)
                                    <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="span-full">
                            <label>Cities (optional)</label>
                            @include('reports.partials.city-picker', [
                                'pickerId' => 'pie',
                                'cityOptions' => $cityOptions ?? [],
                                'selectedCities' => $filters['cities'] ?? [],
                                'note' => 'Add cities one at a time. Leave empty for all cities, or pick a saved governorate preset above.',
                            ])
                        </div>
                    </div>
                    <div class="filters-actions">
                        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                        <a class="export-link" href="{{ route('reports.cities.index', ['city_page' => 'pie-charts']) }}">Reset filters</a>
                    </div>
                </div>
            </details>
        </form>

        @if ($pieActiveFilters !== [])
            <div class="pie-active-filters" aria-label="Active filters">
                @foreach ($pieActiveFilters as $pill)
                    <span class="pie-active-filters__pill">{{ $pill }}</span>
                @endforeach
            </div>
        @endif

        <section class="lab-card pie-summary-card" aria-label="Period summary">
            <div class="pie-summary-card__grid">
                <div>
                    <p class="lab-kpi__label">Period</p>
                    <p class="lab-kpi__value" style="font-size:1.05rem;">{{ $filters['date_from'] ?? '—' }} → {{ $filters['date_to'] ?? '—' }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">City chart total</p>
                    <p class="lab-kpi__value">{{ display_number($pieCityStats['total']) }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">Categories tracked</p>
                    <p class="lab-kpi__value">{{ display_number($pieCategoryStats['count'], 0) }}</p>
                </div>
                <div>
                    <p class="lab-kpi__label">Items tracked</p>
                    <p class="lab-kpi__value">{{ display_number($pieItemStats['count'], 0) }}</p>
                </div>
            </div>
        </section>

        <div class="pie-charts-grid">
            @foreach ($pieCharts as $chart)
                @php
                    $stats = $chart['stats'];
                    $hasPieData = $stats['count'] > 0;
                @endphp
                <section class="lab-card pie-chart-card" aria-labelledby="pie-card-title-{{ $chart['slug'] }}">
                    <header class="pie-chart-card__head">
                        <div class="pie-chart-card__titles">
                            <h2 class="lab-card__title" id="pie-card-title-{{ $chart['slug'] }}">
                                {{ $chart['title'] }}
                                <span class="lab-tag">{{ $chart['tag'] }}</span>
                            </h2>
                            <p class="pie-chart-card__hint">{{ $chart['hint'] }}</p>
                        </div>
                        <div class="pie-chart-card__total">
                            <span class="pie-chart-card__total-label">Chart total</span>
                            <strong>{{ display_number($stats['total']) }}</strong>
                            <span class="pie-chart-card__total-meta">{{ display_number($stats['count'], 0) }} slice{{ $stats['count'] === 1 ? '' : 's' }}</span>
                        </div>
                    </header>
                    <div class="pie-chart-card__body">
                        <div class="pie-chart-card__viz">
                            <div class="pie-donut-wrap">
                                <canvas id="pie-{{ $chart['slug'] }}-chart" aria-hidden="{{ $hasPieData ? 'false' : 'true' }}" @if(! $hasPieData) hidden @endif></canvas>
                                <div id="pie-{{ $chart['slug'] }}-chart-empty" class="dash-chart-empty" @if($hasPieData) hidden @endif>
                                    No sales for these filters in this period.
                                </div>
                            </div>
                        </div>
                        <div class="pie-chart-card__table-wrap">
                            @if ($hasPieData)
                                <table class="lab-table pie-breakdown-table">
                                    <thead>
                                        <tr>
                                            <th scope="col">#</th>
                                            <th scope="col">Name</th>
                                            <th scope="col" class="num">Amount</th>
                                            <th scope="col" class="num">Share</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($stats['items'] as $index => $item)
                                            @php
                                                $share = $stats['total'] > 0 ? ($item['amount'] / $stats['total']) * 100 : 0;
                                            @endphp
                                            <tr>
                                                <td class="pie-breakdown-table__rank">{{ $index + 1 }}</td>
                                                <td>{{ $item['label'] }}</td>
                                                <td class="num">{{ display_number($item['amount']) }}</td>
                                                <td class="num">{{ display_number($share, 1) }}%</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="lab-table__sum">
                                            <td colspan="2">Total (shown slices)</td>
                                            <td class="num">{{ display_number($stats['total']) }}</td>
                                            <td class="num">100%</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            @else
                                <p class="muted pie-breakdown-empty">Adjust dates or filters, then apply to load a breakdown table.</p>
                            @endif
                        </div>
                    </div>
                </section>
            @endforeach
        </div>

        <script>
        (function () {
            if (typeof Chart === 'undefined') return;

            var palette = ['#6366f1', '#14b8a6', '#f59e0b', '#ec4899', '#8b5cf6', '#06b6d4', '#84cc16', '#f97316', '#0ea5e9', '#a855f7'];
            var charts = @json($pieChartsJs);

            var doughnutCenterPlugin = {
                id: 'doughnutCenterText',
                beforeDraw: function (chart) {
                    var total = chart.config.options.plugins.doughnutCenterText.total;
                    if (!total || total <= 0) return;
                    var ctx = chart.ctx;
                    var meta = chart.getDatasetMeta(0);
                    if (!meta || !meta.data || !meta.data[0]) return;
                    var arc = meta.data[0];
                    var x = arc.x;
                    var y = arc.y;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#64748b';
                    ctx.font = '600 11px system-ui, sans-serif';
                    ctx.fillText('Total', x, y - 8);
                    ctx.fillStyle = '#0f172a';
                    ctx.font = '700 14px system-ui, sans-serif';
                    ctx.fillText(total, x, y + 10);
                    ctx.restore();
                }
            };
            Chart.register(doughnutCenterPlugin);

            function fmtAmount(n) {
                return Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });
            }

            function buildData(rows, labelKey) {
                var labels = [];
                var values = [];
                (rows || []).forEach(function (r) {
                    var amount = parseFloat(r.amount || 0);
                    if (!isFinite(amount) || amount <= 0) return;
                    var label = String(r[labelKey] || '').trim();
                    if (!label) label = '(unknown)';
                    labels.push(label);
                    values.push(amount);
                });
                return { labels: labels, values: values };
            }

            charts.forEach(function (cfg) {
                var canvas = document.getElementById('pie-' + cfg.slug + '-chart');
                var emptyEl = document.getElementById('pie-' + cfg.slug + '-chart-empty');
                if (!canvas) return;
                var data = buildData(cfg.rows, cfg.labelKey);
                var hasData = data.labels.length > 0;
                if (emptyEl) emptyEl.hidden = hasData;
                canvas.hidden = !hasData;
                if (!hasData) return;

                var colors = data.labels.map(function (_, i) { return palette[i % palette.length]; });
                new Chart(canvas, {
                    type: 'doughnut',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values,
                            backgroundColor: colors,
                            borderColor: '#fff',
                            borderWidth: 2,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '58%',
                        layout: { padding: 4 },
                        plugins: {
                            legend: {
                                display: true,
                                position: 'bottom',
                                labels: {
                                    boxWidth: 10,
                                    boxHeight: 10,
                                    padding: 10,
                                    font: { size: 11 },
                                    usePointStyle: true
                                }
                            },
                            doughnutCenterText: {
                                total: fmtAmount(cfg.total)
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var arr = ctx.dataset.data || [];
                                        var total = arr.reduce(function (a, b) { return a + b; }, 0);
                                        var value = Number(ctx.parsed || 0);
                                        var pct = total > 0 ? ((value / total) * 100) : 0;
                                        return (ctx.label || '') + ': ' + fmtAmount(value) + ' (' + pct.toFixed(1) + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            });
        })();
        </script>
        @include('reports.partials.quick-date-buttons-script', [
            'formId' => 'pie-charts-filter-form',
            'fromId' => 'date_from_pie',
            'toId' => 'date_to_pie',
        ])
        @include('reports.partials.city-picker-script', [
            'pickerId' => 'pie',
            'selectedCities' => $filters['cities'] ?? [],
        ])
    @endif
@endsection

@push('styles')
<style>
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals-box { background: #f0f9ff; padding: 12px; border-radius: 8px; margin-top: 12px; }
        .customer-suggestions li:hover, .customer-suggestions li.is-active { background: #ecfdf5; }
        .customer-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 12px; }
        .chart-wrap { margin-top: 16px; max-width: 1100px; min-height: 320px; }
        .pie-quick-dates {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px dashed var(--rp-border, #e2e8f0);
        }
        .pie-quick-dates__label {
            font-size: 12px;
            font-weight: 600;
            color: var(--rp-muted, #64748b);
            margin-right: 4px;
        }
        .pie-quick-date-btn {
            border: 1px solid var(--rp-border, #e2e8f0);
            background: #f8fafc;
            color: #334155;
            border-radius: 999px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .pie-quick-date-btn:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
            color: #4338ca;
        }
        .pie-city-picker-note { margin-top: 8px; font-size: 12px; }
        .pie-active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0 4px;
        }
        .pie-active-filters__pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            font-size: 12px;
            font-weight: 600;
        }
        .pie-summary-card {
            margin-top: 12px;
            border-top: 3px solid #6366f1;
        }
        .pie-summary-card__grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px 20px;
        }
        .pie-charts-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-top: 16px;
        }
        .pie-chart-card {
            border-top: 3px solid #14b8a6;
            overflow: hidden;
        }
        .pie-chart-card:nth-child(2) { border-top-color: #6366f1; }
        .pie-chart-card:nth-child(3) { border-top-color: #f59e0b; }
        .pie-chart-card--salesman { border-top-color: #ec4899; }
        .pie-chart-card__head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 12px 20px;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--rp-border, #e2e8f0);
        }
        .pie-chart-card__titles { flex: 1 1 240px; min-width: 0; }
        .pie-chart-card__hint {
            margin: 6px 0 0;
            font-size: 12px;
            color: var(--rp-muted, #64748b);
            line-height: 1.5;
        }
        .pie-chart-card__total {
            text-align: right;
            flex: 0 0 auto;
        }
        .pie-chart-card__total-label {
            display: block;
            font-size: 11px;
            color: var(--rp-muted, #64748b);
            margin-bottom: 2px;
        }
        .pie-chart-card__total strong {
            display: block;
            font-size: 1.35rem;
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.02em;
        }
        .pie-chart-card__total-meta {
            display: block;
            margin-top: 2px;
            font-size: 11px;
            color: var(--rp-muted, #64748b);
        }
        .pie-chart-card__body {
            display: grid;
            grid-template-columns: minmax(220px, 340px) minmax(0, 1fr);
            gap: 16px 20px;
            align-items: start;
        }
        @media (max-width: 900px) {
            .pie-chart-card__body { grid-template-columns: 1fr; }
        }
        .pie-donut-wrap {
            position: relative;
            min-height: 260px;
            max-height: 320px;
        }
        .pie-donut-wrap canvas {
            width: 100% !important;
            height: 100% !important;
            max-height: 300px;
        }
        .pie-chart-card__table-wrap {
            min-width: 0;
            max-height: 320px;
            overflow: auto;
            border: 1px solid var(--rp-border, #e2e8f0);
            border-radius: 8px;
        }
        .pie-breakdown-table { margin: 0; font-size: 12px; }
        .pie-breakdown-table th,
        .pie-breakdown-table td { padding: 7px 10px; }
        .pie-breakdown-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
        }
        .pie-breakdown-table__rank {
            width: 2rem;
            color: var(--rp-muted, #64748b);
            font-variant-numeric: tabular-nums;
        }
        .pie-breakdown-empty {
            padding: 16px;
            font-size: 13px;
            margin: 0;
        }
</style>
@endpush

@push('head')
    @if (($mode ?? '') === 'charts' || in_array(($filters['city_page'] ?? 'overview'), ['pie-charts', 'salesman-pie'], true))
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    @endif
@endpush
