<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales by item average</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a { padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333; background: #eee; font-size: 14px; }
        .tabs a.active { background: #2563eb; color: #fff; }
        .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; align-items: end; margin-bottom: 12px; }
        label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        .error { background: #ffeaea; color: #921d1d; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .hint { font-size: 13px; color: #666; margin-bottom: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: #64748b; font-size: 12px; }
        .customer-picker { position: relative; max-width: 560px; }
        .customer-chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 32px; margin-bottom: 8px; align-items: center; }
        .customer-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; background: #e0f2fe; border: 1px solid #7dd3fc;
            border-radius: 999px; font-size: 13px; color: #0c4a6e;
        }
        .customer-chip button {
            border: none; background: transparent; color: #0369a1; cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px;
        }
        .customer-chip button:hover { color: #0c4a6e; }
        .customer-search-wrap { position: relative; }
        .customer-suggestions {
            display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; margin: 4px 0 0 0; padding: 0;
            list-style: none; max-height: 220px; overflow-y: auto; background: #fff; border: 1px solid #ccc; border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
        }
        .customer-suggestions.is-open { display: block; }
        .customer-suggestions li { padding: 8px 10px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f1f5f9; }
        .customer-suggestions li:hover, .customer-suggestions li.is-active { background: #eff6ff; }
        .customer-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 13px; }
        .export-row { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 4px; }
        .export-row a { display: inline-block; padding: 8px 12px; border-radius: 6px; background: #0f766e; color: #fff; text-decoration: none; font-size: 14px; }
        .export-row a:hover { background: #115e59; }
        .drilldown-trigger { cursor: pointer; color: #1d4ed8; text-decoration: underline; }
        .drilldown-row td { background: #f8fafc; }
        .drilldown-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .drilldown-table th, .drilldown-table td { border: 1px solid #e2e8f0; padding: 6px; }
        .drilldown-loading { color: #64748b; font-size: 12px; padding: 6px 0; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index', request()->query()) }}" class="active">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Sales by item average</h1>
    <p class="hint">
        First level shows <strong>categories</strong> (from <code>fld_description</code>). Click a category to drill down to
        <strong>item names</strong> (from <code>fld_item_name</code>) with the same columns.
        If <strong>working days</strong> is set (&gt; 0), average columns are shown:
        each total divided by working days (example: 10 units over 2 days = 5/day), and
        balance coverage is calculated as current balance / avg quantity per day.
    </p>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    <form id="sales-item-average-filter-form" method="GET" action="{{ route('reports.sales-item-average.index') }}" class="filters">
        <div>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div>
            <label for="q">Item search (optional)</label>
            <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="e.g. chicken">
        </div>
        <div>
            <label for="exclude_category">Exclude category (optional)</label>
            <select id="exclude_category" name="exclude_category">
                <option value="">-- none --</option>
                @foreach (($categoryOptions ?? []) as $categoryOption)
                    <option value="{{ $categoryOption }}" @selected(($filters['exclude_category'] ?? '') === $categoryOption)>
                        {{ $categoryOption }}
                    </option>
                @endforeach
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
                    <input type="text" id="city-search" autocomplete="off" placeholder="Start typing a city..." style="width:100%;max-width:520px;" @disabled(!($hasCityColumn ?? false))>
                    <ul class="customer-suggestions" id="city-suggestions" role="listbox" aria-label="Matching cities"></ul>
                </div>
                @if (!($hasCityColumn ?? false))
                    <p class="muted" style="margin-top: 8px;">City filtering is unavailable because no city column could be resolved on accounts.</p>
                @else
                    <p class="muted" style="margin-top: 8px;">Add multiple cities by searching again. Leave empty for all cities.</p>
                @endif
            </div>
        </div>
        <div>
            <label for="working_days">Working days (optional)</label>
            <input type="number" id="working_days" name="working_days" min="0" max="400" value="{{ (int) ($filters['working_days'] ?? 0) }}">
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
            <a href="#" class="sales-item-average-export-link" data-export-base="{{ route('reports.sales-item-average.export.csv') }}">Export CSV</a>
            <a href="#" class="sales-item-average-export-link" data-export-base="{{ route('reports.sales-item-average.export.pdf') }}">Export PDF</a>
        </div>
    </form>

    <script>
    (function () {
        var form = document.getElementById('sales-item-average-filter-form');
        if (!form) return;
        var root = document.getElementById('city-picker');
        var jsonEl = document.getElementById('city-options-json');
        var allCities = [];
        try { allCities = JSON.parse(jsonEl ? (jsonEl.textContent || '[]') : '[]'); } catch (e) { allCities = []; }
        var selectedIds = new Set();
        var chipsEl = document.getElementById('city-chips');
        var hiddenEl = document.getElementById('city-hidden-inputs');
        var searchInput = document.getElementById('city-search');
        var listEl = document.getElementById('city-suggestions');
        var initialIds = @json($filters['cities'] ?? []);
        var byId = {};
        allCities.forEach(function (c) { if (c && c.id) byId[c.id] = c.name || c.id; });

        function renderChips() {
            if (!chipsEl || !hiddenEl) return;
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
                rm.textContent = 'x';
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
                hi.name = 'cities[]';
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

        initialIds.forEach(function (id) { if (id) selectedIds.add(id); });
        renderChips();

        if (searchInput && listEl) {
            var activeIndex = -1;
            searchInput.addEventListener('input', function () {
                var q = searchInput.value;
                if (q.trim() === '') {
                    closeSuggestions();
                    return;
                }
                var matches = filterCities(q);
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
                if (root && !root.contains(e.target)) closeSuggestions();
            });
        }

        document.querySelectorAll('a.sales-item-average-export-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var base = a.getAttribute('data-export-base');
                if (!base) return;
                var params = new URLSearchParams(new FormData(form));
                window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + params.toString();
            });
        });
    })();
    </script>

    @if ($rows)
        <table>
            <thead>
            <tr>
                <th>Category</th>
                <th class="num">Quantity (pcs)</th>
                @if ($workingDaysDivisor)
                    <th class="num">Avg quantity / day (pcs)</th>
                    <th class="num">Balance coverage (days)</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php
                    $units = (float) ($row->units_sold ?? 0);
                    $storage = (float) ($row->storage_balance ?? 0);
                    $avgUnits = $workingDaysDivisor ? ($units / $workingDaysDivisor) : null;
                @endphp
                <tr class="category-row" data-category="{{ $row->category_name ?? '' }}">
                    <td>
                        <span class="drilldown-trigger">{{ $row->category_name ?? '' }}</span>
                    </td>
                    <td class="num">{{ display_number($units) }}</td>
                    @if ($workingDaysDivisor)
                        <td class="num">{{ display_number($avgUnits) }}</td>
                        <td class="num">
                            @if (($avgUnits ?? 0.0) > 0)
                                {{ display_number($storage / $avgUnits) }}
                            @else
                                —
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif

    <script>
    (function () {
        var table = document.querySelector('.container table');
        if (!table) return;

        var endpoint = @json(route('reports.sales-item-average.items'));
        var dateFrom = @json($filters['date_from'] ?? '');
        var dateTo = @json($filters['date_to'] ?? '');
        var excludeCategory = @json($filters['exclude_category'] ?? '');
        var workingDays = parseInt(@json((int) ($filters['working_days'] ?? 0)), 10) || 0;
        var openDrilldownRow = null;

        function fmt(n) {
            if (n === null || n === undefined || isNaN(n)) return '0';
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(Number(n));
        }
        function esc(s) {
            return String(s || '').replace(/[&<>"']/g, function (c) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
            });
        }

        function buildDrilldownTable(rows) {
            var html = '<table class="drilldown-table"><thead><tr>' +
                '<th>Item name</th><th class="num">Quantity (pcs)</th>';
            if (workingDays > 0) {
                html += '<th class="num">Avg quantity / day (pcs)</th><th class="num">Balance coverage (days)</th>';
            }
            html += '</tr></thead><tbody>';
            if (!rows.length) {
                var emptyCols = 2 + (workingDays > 0 ? 2 : 0);
                html += '<tr><td colspan="' + emptyCols + '" class="muted">No item rows in this category for selected dates.</td></tr>';
            } else {
                rows.forEach(function (r) {
                    var units = Number(r.units_sold || 0);
                    var storage = Number(r.storage_balance || 0);
                    var avgUnits = workingDays > 0 ? (units / workingDays) : 0;
                    html += '<tr><td>' + esc(r.item_name || '') + '</td>' +
                        '<td class="num">' + fmt(units) + '</td>';
                    if (workingDays > 0) {
                        html += '<td class="num">' + fmt(avgUnits) + '</td>' +
                            '<td class="num">' + (avgUnits > 0 ? fmt(storage / avgUnits) : '—') + '</td>';
                    }
                    html += '</tr>';
                });
            }
            html += '</tbody></table>';
            return html;
        }

        table.querySelectorAll('tr.category-row').forEach(function (row) {
            var trigger = row.querySelector('.drilldown-trigger');
            if (!trigger) return;
            trigger.addEventListener('click', function () {
                var category = row.getAttribute('data-category') || '';
                if (!category) return;

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
                td.colSpan = 2 + (workingDays > 0 ? 2 : 0);
                td.innerHTML = '<div class="drilldown-loading">Loading item breakdown...</div>';
                holder.appendChild(td);
                row.insertAdjacentElement('afterend', holder);
                openDrilldownRow = holder;

                var params = new URLSearchParams({
                    date_from: dateFrom,
                    date_to: dateTo,
                    category: category
                });
                if (excludeCategory) {
                    params.append('exclude_category', excludeCategory);
                }
                document.querySelectorAll('#city-hidden-inputs input[name="cities[]"]').forEach(function (input) {
                    if (input.value) {
                        params.append('cities[]', input.value);
                    }
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
</div>
</body>
</html>
