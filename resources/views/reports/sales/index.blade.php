<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a {
            padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333;
            background: #eee; font-size: 14px;
        }
        .tabs a.active { background: #2563eb; color: #fff; }
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
        .customer-suggestions li {
            padding: 8px 10px; cursor: pointer; font-size: 14px; border-bottom: 1px solid #f1f5f9;
        }
        .customer-suggestions li:hover, .customer-suggestions li.is-active { background: #eff6ff; }
        .customer-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 13px; }
        .export-row { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 4px; }
        .export-row a {
            display: inline-block; padding: 8px 12px; border-radius: 6px; background: #0f766e; color: #fff;
            text-decoration: none; font-size: 14px;
        }
        .export-row a:hover { background: #115e59; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index', request()->query()) }}" class="active">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Sales report</h1>
    <p class="hint">
        Store document lines for the selected dates. <strong>Amount (IQD)</strong> = quantity × unit price per line.
        <strong>Weight (kg)</strong> = quantity × item weight from item settings (when set).
        <strong>Category breakdown</strong> totals sales by <code>dbo.tbl_store_items.fld_description</code> across all clients.
        <strong>Category breakdown based on clients</strong> shows one row per client per category.
        If both are checked, <em>based on clients</em> wins. See <a href="{{ route('reports.identifier.index') }}#term-item_category">Identifier</a>.
        Read-only; does not change the database.
    </p>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    <form id="sales-filter-form" method="GET" action="{{ route('reports.sales.index') }}" class="filters">
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
            <span class="muted" style="display:block;margin-bottom:4px;">Customers (optional)</span>
            <script type="application/json" id="customer-options-json">@json($customerOptions ?? [])</script>
            <div class="customer-picker" id="customer-picker">
                <div class="customer-chips" id="customer-chips" aria-live="polite"></div>
                <div id="customer-hidden-inputs"></div>
                <div class="customer-search-wrap">
                    <label for="customer-search" class="muted" style="display:block;margin-bottom:4px;">Type a name or code, then pick from the list</label>
                    <input type="text" id="customer-search" autocomplete="off" placeholder="e.g. type part of client name…" style="width:100%;max-width:520px;">
                    <ul class="customer-suggestions" id="customer-suggestions" role="listbox" aria-label="Matching clients"></ul>
                </div>
                <p class="muted" style="margin-top: 8px;">Add several clients by searching again after each selection. Leave empty to include all customers.</p>
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
            <a href="#" class="sales-export-link" data-export-base="{{ route('reports.sales.export.csv') }}">Export CSV</a>
            <a href="#" class="sales-export-link" data-export-base="{{ route('reports.sales.export.pdf') }}">Export PDF</a>
        </div>
    </form>

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

        document.querySelectorAll('a.sales-export-link').forEach(function (a) {
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
        <p class="muted" style="margin-top:8px;">Category breakdown — grouped by <code>dbo.tbl_store_items.fld_description</code> (all clients). Uncategorized lines show as <em>(uncategorized)</em>.</p>
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
        <p class="muted" style="margin-top:8px;">Category breakdown based on clients — one row per client per category. Order: client name, then amount descending.</p>
    @endif
</div>
</body>
</html>
