<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cities sales report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a {
            padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333;
            background: #eee; font-size: 14px;
        }
        .tabs a.active { background: #2563eb; color: #fff; }
        .sub-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
        .sub-tabs a {
            padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 14px;
            background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;
        }
        .sub-tabs a.active { background: #0ea5e9; color: #fff; border-color: #0ea5e9; }
        .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; align-items: end; margin-bottom: 12px; }
        .filters-breakdown { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 10px; }
        .filters-breakdown .chk-row { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
        label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        .chk { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #333; }
        .chk input { width: auto; }
        .error { background: #ffeaea; color: #921d1d; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .hint { font-size: 13px; color: #666; margin-bottom: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .totals-box { background: #f0f9ff; padding: 12px; border-radius: 8px; margin-top: 12px; }
        .muted { color: #64748b; font-size: 12px; }
        .customer-picker { position: relative; max-width: 560px; }
        .customer-chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 32px; margin-bottom: 8px; align-items: center; }
        .customer-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; background: #ecfdf5; border: 1px solid #6ee7b7;
            border-radius: 999px; font-size: 13px; color: #065f46;
        }
        .customer-chip button {
            border: none; background: transparent; color: #047857; cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px;
        }
        .customer-search-wrap { position: relative; }
        .customer-suggestions {
            display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin: 4px 0 0 0; padding: 0;
            list-style: none; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #ccc; border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }
        .customer-suggestions.is-open { display: block; }
        .customer-suggestions li {
            padding: 8px 10px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f1f5f9;
        }
        .customer-suggestions li:hover, .customer-suggestions li.is-active { background: #ecfdf5; }
        .customer-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 13px; }
        .export-row { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 4px; }
        .export-row a {
            display: inline-block; padding: 8px 12px; border-radius: 6px; background: #0f766e; color: #fff;
            text-decoration: none; font-size: 14px;
        }
        .export-row a:hover { background: #115e59; }
        .chart-wrap { margin-top: 16px; max-width: 1100px; min-height: 320px; }
        .gov-editor-card { border:1px solid #e2e8f0; border-radius:10px; padding:14px; margin-bottom:14px; background:#fcfdff; }
        .gov-editor-form { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; align-items:start; }
        .gov-editor-field label { font-weight: 600; color:#334155; margin-bottom:6px; }
        .gov-editor-field input, .gov-editor-field select { width:100%; }
        .gov-editor-members { min-height: 168px; }
        .gov-editor-actions { display:flex; align-items:flex-end; }
        .gov-editor-actions button { min-width: 170px; }
    </style>
    @if (($mode ?? '') === 'charts' || (($filters['city_page'] ?? 'overview') === 'pie-charts'))
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
    @endif
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.cities.index', request()->query()) }}" class="active">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Cities sales report</h1>
    @php $cityPage = $filters['city_page'] ?? 'overview'; @endphp
    <nav class="sub-tabs" aria-label="Cities pages">
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'overview'])) }}"
           class="{{ $cityPage === 'overview' ? 'active' : '' }}">Overview</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'governorate-breakdown'])) }}"
           class="{{ $cityPage === 'governorate-breakdown' ? 'active' : '' }}">Governorate breakdown</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'pie-charts'])) }}"
           class="{{ $cityPage === 'pie-charts' ? 'active' : '' }}">Pie charts</a>
    </nav>

    <datalist id="report-city-names-datalist">
        @foreach (($cityNames ?? []) as $cityName)
            <option value="{{ $cityName }}"></option>
        @endforeach
    </datalist>

    <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:14px;background:#f8fafc;">
        <strong style="font-size:14px;">Saved governorates</strong>
        <span class="muted" style="font-size:13px;margin-left:8px;">(local SQLite — same file as Deliveries teams)</span>
        @if (!empty($governorateStorageError))
            <div class="error" style="margin-top:8px;">{{ $governorateStorageError }}</div>
        @elseif (empty($savedGovernorates))
            <p class="muted" style="margin:8px 0 0;font-size:13px;">None saved yet. Open <strong>Governorate breakdown</strong> to add one. Governorate city can be typed (e.g. Duhok) even if it does not appear in the visits city list.</p>
        @else
            <ul style="margin:8px 0 0;padding-left:18px;font-size:14px;">
                @foreach ($savedGovernorates as $savedGov)
                    <li>
                        <strong>{{ $savedGov->name ?? '' }}</strong>
                        <span class="muted">— {{ $savedGov->governorate_city ?? '' }}</span>
                        ·
                        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'governorate-breakdown', 'saved_governorate_id' => (int) ($savedGov->id ?? 0)])) }}">Use in breakdown</a>
                        ·
                        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'pie-charts', 'saved_governorate_id' => (int) ($savedGov->id ?? 0)])) }}">Use in pie charts</a>
                        ·
                        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['city_page' => 'governorate-breakdown', 'edit_governorate_id' => (int) ($savedGov->id ?? 0)])) }}">Edit</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="error">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif
    @if (session('status'))
        <div style="background:#ecfdf5;color:#065f46;padding:10px;border-radius:6px;margin-bottom:12px;">{{ session('status') }}</div>
    @endif

    @if ($cityPage === 'overview')
    <nav class="sub-tabs" aria-label="Report view">
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['panel' => 'table'])) }}"
           class="{{ ($filters['panel'] ?? 'table') === 'table' ? 'active' : '' }}">Data table</a>
        <a href="{{ route('reports.cities.index', array_merge(request()->except('page'), ['panel' => 'charts'])) }}"
           class="{{ ($filters['panel'] ?? 'table') === 'charts' ? 'active' : '' }}">City sales charts</a>
    </nav>

    <p class="hint">
        Store document lines for the selected dates, filtered by <strong>client city</strong> on <code>dbo.tbl_accounting_accounts</code>
        (same column as Visits). Amount (IQD) = quantity × unit price. Category breakdowns match the Sales report; only the geography filter differs.
        Read-only.
    </p>

    @if (! $hasCityColumn)
        <div class="error">City column is not configured or not found. Set <code>REPORTING_ACCOUNT_CITY_COLUMN</code> in <code>.env</code> or add a city field on accounts. Charts need this column.</div>
    @endif

    <form id="cities-filter-form" method="GET" action="{{ route('reports.cities.index') }}" class="filters">
        <input type="hidden" name="panel" value="{{ $filters['panel'] ?? 'table' }}">
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
                <label class="chk">
                    <input type="checkbox" name="breakdown" value="1" @checked(!empty($filters['breakdown']))>
                    Category breakdown
                </label>
                <label class="chk">
                    <input type="checkbox" name="breakdown_by_client" value="1" @checked(!empty($filters['breakdown_by_client']))>
                    Category breakdown based on clients
                </label>
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label for="q">Filter category text (optional)</label>
                <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. صدر or wing">
            </div>
        </div>
        <div class="view-mode-row" style="grid-column: 1 / -1;">
            <label for="group_by_client" class="muted">View (ignored when a category mode is on)</label>
            <select id="group_by_client" name="group_by_client">
                <option value="1" @selected($filters['group_by_client'] ?? true)>By client (account)</option>
                <option value="0" @selected(!($filters['group_by_client'] ?? true))>Period totals only</option>
            </select>
        </div>
        <div style="grid-column: 1 / -1;">
            <span class="muted" style="display:block;margin-bottom:4px;">Cities (optional)</span>
            <script type="application/json" id="city-options-json">@json($cityOptions ?? [])</script>
            <div class="customer-picker" id="city-picker">
                <div class="customer-chips" id="city-chips" aria-live="polite"></div>
                <div id="city-hidden-inputs"></div>
                <div class="customer-search-wrap">
                    <label for="city-search" class="muted" style="display:block;margin-bottom:4px;">Type a city name, then pick from the list</label>
                    <input type="text" id="city-search" autocomplete="off" placeholder="Start typing a city…" style="width:100%;max-width:520px;">
                    <ul class="customer-suggestions" id="city-suggestions" role="listbox" aria-label="Matching cities"></ul>
                </div>
                <p class="muted" style="margin-top: 8px;">Add multiple cities by searching again. Leave empty for all cities (subject to chart limit).</p>
            </div>
        </div>
        <div>
            <label for="per_page">Rows per page</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <button type="submit">Apply</button>
        </div>
        <div class="export-row">
            <span class="muted">Export current filters:</span>
            <a href="#" class="cities-export-link" data-export-base="{{ route('reports.cities.export.csv') }}">Export CSV</a>
            <a href="#" class="cities-export-link" data-export-base="{{ route('reports.cities.export.pdf') }}">Export PDF</a>
            @if (($filters['panel'] ?? 'table') === 'charts')
                <a href="#" class="cities-export-link" data-append-chart-series="1" data-export-base="{{ route('reports.cities.export.chart-pdf') }}">Export chart PDF</a>
            @endif
        </div>
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

    @if ($mode === 'totals' && $totals)
        <div class="totals-box">
            <strong>Period totals</strong>
            <p class="num">Quantity (pcs): {{ display_number($totals->units_sold ?? 0) }}</p>
            <p class="num">Amount (IQD): {{ display_number($totals->amount ?? 0) }}</p>
            <p class="num">Weight (kg): {{ display_number($totals->weight_total ?? 0) }}</p>
        </div>
    @endif

    @if ($mode === 'by_client' && $rows)
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
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif

    @if ($mode === 'by_category' && $rows)
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
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif

    @if ($mode === 'by_category_by_client' && $rows)
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
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif
    @elseif ($cityPage === 'governorate-breakdown')
        <p class="hint">
            Select one city as the governorate and other cities that belong to it. The report shows sales by item category,
            with city-by-category breakdown for the selected governorate mapping.
        </p>
        <div class="gov-editor-card">
            <h2 style="font-size:16px;margin:0 0 12px;">Save / edit governorates</h2>
            <form method="POST" action="{{ route('reports.cities.governorates.save', request()->query()) }}" class="gov-editor-form">
                @csrf
                <input type="hidden" name="governorate_id" value="{{ !empty($editingGovernorate['id']) ? (int) $editingGovernorate['id'] : '' }}">
                <div class="gov-editor-field">
                    <label for="governorate_name_form">Governorate label</label>
                    <input type="text" id="governorate_name_form" name="governorate_name" value="{{ $editingGovernorate['name'] ?? '' }}" placeholder="e.g. Erbil Governorate" required>
                </div>
                <div class="gov-editor-field">
                    <label for="governorate_city_form">Governorate city</label>
                    <input type="text" id="governorate_city_form" name="governorate_city" list="report-city-names-datalist" value="{{ $editingGovernorate['governorate_city'] ?? '' }}" placeholder="Type or pick from suggestions (e.g. Duhok)" required maxlength="200">
                    <div class="muted" style="margin-top:4px;">Use the exact spelling stored on accounts for reporting.</div>
                </div>
                <div class="gov-editor-field">
                    <label for="governorate_members_form">Member cities</label>
                    <select id="governorate_members_form" name="governorate_members[]" multiple size="6" class="gov-editor-members">
                        @foreach (($cityNames ?? []) as $cityName)
                            <option value="{{ $cityName }}" @selected(in_array($cityName, $editingGovernorate['members'] ?? [], true))>{{ $cityName }}</option>
                        @endforeach
                    </select>
                    <div class="muted" style="margin-top:4px;">Optional. Governorate city is always included automatically.</div>
                </div>
                <div class="gov-editor-actions">
                    <button type="submit">{{ !empty($editingGovernorate) ? 'Update governorate' : 'Save governorate' }}</button>
                </div>
            </form>
            @if (!empty($savedGovernorates))
                <table style="margin-top:14px;">
                    <thead><tr><th>Name</th><th>Governorate city</th><th class="num">Cities</th><th>Action</th></tr></thead>
                    <tbody>
                    @foreach (($savedGovernorates ?? []) as $savedGov)
                        <tr>
                            <td>{{ $savedGov->name ?? '' }}</td>
                            <td>{{ $savedGov->governorate_city ?? '' }}</td>
                            <td class="num">{{ (int) ($savedGov->member_count ?? 0) }}</td>
                            <td>
                                <a href="{{ route('reports.cities.index', array_merge(request()->query(), ['city_page' => 'governorate-breakdown', 'edit_governorate_id' => (int) ($savedGov->id ?? 0), 'saved_governorate_id' => (int) ($savedGov->id ?? 0)])) }}">Edit</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <form method="GET" action="{{ route('reports.cities.index') }}" class="filters">
            <input type="hidden" name="city_page" value="governorate-breakdown">
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
                <label for="per_page_gov">Rows per page</label>
                <select id="per_page_gov" name="per_page">
                    @foreach ([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit">Apply</button>
            </div>
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
            <div style="margin-top: 12px;">{{ $governorateRows->links() }}</div>
        @endif
    @elseif ($cityPage === 'pie-charts')
        <p class="hint">
            Pie charts are based on sales amount percentage. Use city filters for scope, and choose a category
            for the item-level chart (example: chicken).
        </p>
        <form method="GET" action="{{ route('reports.cities.index') }}" class="filters">
            <input type="hidden" name="city_page" value="pie-charts">
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
                <label for="cities_pie">Cities (optional, multi-select)</label>
                <select id="cities_pie" name="cities[]" multiple size="6">
                    @foreach (($cityNames ?? []) as $cityName)
                        <option value="{{ $cityName }}" @selected(in_array($cityName, $filters['cities'] ?? [], true))>{{ $cityName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="pie_category">Category for item pie (optional)</label>
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
                <button type="submit">Apply</button>
            </div>
        </form>

        <div class="chart-wrap">
            <h2 style="font-size:16px;margin:16px 0 8px;">Pie chart by cities</h2>
            <canvas id="pie-city-chart" height="120"></canvas>
            <h2 style="font-size:16px;margin:20px 0 8px;">Pie chart by category</h2>
            <canvas id="pie-category-chart" height="120"></canvas>
            <h2 style="font-size:16px;margin:20px 0 8px;">Pie chart by item{{ ($filters['pie_category'] ?? '') !== '' ? ' ('.$filters['pie_category'].')' : '' }}</h2>
            <canvas id="pie-item-chart" height="120"></canvas>
        </div>
        <script>
        (function () {
            if (typeof Chart === 'undefined') return;
            var cityRows = @json($pieSeriesByCity ?? []);
            var categoryRows = @json($pieSeriesByCategory ?? []);
            var itemRows = @json($pieSeriesByItem ?? []);
            function buildData(rows, labelKey) {
                var labels = [];
                var values = [];
                (rows || []).forEach(function (r) {
                    var amount = parseFloat(r.amount || 0);
                    if (!isFinite(amount) || amount <= 0) return;
                    labels.push(String(r[labelKey] || ''));
                    values.push(amount);
                });
                return { labels: labels, values: values };
            }
            function makePie(canvasId, rows, labelKey, title) {
                var el = document.getElementById(canvasId);
                if (!el) return;
                var data = buildData(rows, labelKey);
                if (!data.labels.length) return;
                new Chart(el, {
                    type: 'pie',
                    data: {
                        labels: data.labels,
                        datasets: [{
                            data: data.values
                        }]
                    },
                    options: {
                        plugins: {
                            title: { display: false, text: title },
                            tooltip: {
                                callbacks: {
                                    label: function (ctx) {
                                        var arr = ctx.dataset.data || [];
                                        var total = arr.reduce(function (a, b) { return a + b; }, 0);
                                        var value = Number(ctx.parsed || 0);
                                        var pct = total > 0 ? ((value / total) * 100) : 0;
                                        return ctx.label + ': ' + value.toLocaleString() + ' (' + pct.toFixed(2) + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }
            makePie('pie-city-chart', cityRows, 'city_name', 'City share');
            makePie('pie-category-chart', categoryRows, 'item_category', 'Category share');
            makePie('pie-item-chart', itemRows, 'item_name', 'Item share');
        })();
        </script>
    @endif
</div>
</body>
</html>
