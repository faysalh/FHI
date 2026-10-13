@extends('reports.layouts.app')
@section('title', 'Market study')

@section('content')
@php
    $todayPrice = $insights['today_price'] ?? null;
    $rangeAvg = $insights['range_average'] ?? null;
    $rangeCount = (int) ($insights['range_count'] ?? 0);
    $deltaPct = $insights['delta_pct'] ?? null;
    $deltaClass = 'lab-kpi__delta--flat';
    if ($deltaPct !== null) {
        $deltaClass = $deltaPct > 0.05 ? 'lab-kpi__delta--up' : ($deltaPct < -0.05 ? 'lab-kpi__delta--down' : 'lab-kpi__delta--flat');
    }
@endphp
<header class="page-header">
    <h1>Market study</h1>
</header>
<p class="hint">
    Log market prices per KG by market, item, and brand. Shared across everyone with access — use filters and the chart to compare today against a selected range.
</p>

@if (session('status'))
    <div class="alert alert--success">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="alert alert--error">{{ session('error') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert--error">
        <ul class="error-list-plain">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if ($canAdd)
<section class="tasks-card market-study-card">
    <h2 class="tasks-card__title">Log market price</h2>
    <form method="POST" action="{{ route('reports.market-study.store') }}" class="tasks-form-add" id="market-study-add-form" autocomplete="off">
        @csrf
        <div class="filters-grid market-study-form-grid">
            <div>
                <label for="add_market_name">Market</label>
                <input type="text" id="add_market_name" name="market_name" list="market-study-market-options" value="{{ old('market_name') }}" maxlength="255" required placeholder="Market name (not a client)">
            </div>
            <div class="market-study-item-wrap">
                <label for="add_item_name">Item / product</label>
                <input type="text" id="add_item_name" name="item_name" value="{{ old('item_name') }}" maxlength="255" required placeholder="Type to search past items" autocomplete="off">
                <ul id="market-study-item-suggestions" class="market-study-suggestions" hidden role="listbox"></ul>
            </div>
            <div>
                <label for="add_brand">Brand</label>
                <input type="text" id="add_brand" name="brand" list="market-study-brand-options" value="{{ old('brand') }}" maxlength="255" required placeholder="Brand">
            </div>
            <div>
                <label for="add_price_per_kg">Price / KG</label>
                <input type="number" id="add_price_per_kg" name="price_per_kg" value="{{ old('price_per_kg') }}" step="0.0001" min="0.0001" required placeholder="0.00">
            </div>
            <div>
                <label for="add_logged_on">Logged on</label>
                <input type="date" id="add_logged_on" name="logged_on" value="{{ old('logged_on', $todayDate) }}" required>
            </div>
            <div>
                <label for="add_notes">Notes (optional)</label>
                <input type="text" id="add_notes" name="notes" value="{{ old('notes') }}" maxlength="5000" placeholder="Optional note">
            </div>
        </div>
        <div class="filters-actions" style="margin-top: 0.75rem;">
            @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Log price'])
        </div>
    </form>
</section>
@endif

@if (! $canView)
    <div class="alert alert--error" role="status">
        View permission is required to see the log, charts, and exports. You can still add entries if you have Add permission.
    </div>
@else
<form method="GET" action="{{ route('reports.market-study.index') }}" id="market-study-filter-form" class="tasks-filter-form">
    <details class="filters-panel" open>
        <summary>Filter &amp; export</summary>
        <div class="filters-body">
            <div class="filters-grid">
                <div>
                    <label for="filter_market">Market</label>
                    <select id="filter_market" name="market">
                        <option value="">All markets</option>
                        @foreach ($marketOptions as $opt)
                            <option value="{{ $opt }}" @selected(($filters['market'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filter_item">Item</label>
                    <select id="filter_item" name="item">
                        <option value="">All items</option>
                        @foreach ($itemOptions as $opt)
                            <option value="{{ $opt }}" @selected(($filters['item'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filter_brand">Brand</label>
                    <select id="filter_brand" name="brand">
                        <option value="">All brands</option>
                        @foreach ($brandOptions as $opt)
                            <option value="{{ $opt }}" @selected(($filters['brand'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="date_from">From</label>
                    <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div>
                    <label for="date_to">To</label>
                    <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div>
                    <label for="filter_q">Search</label>
                    <input type="text" id="filter_q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Market, item, brand, notes">
                </div>
            </div>
            @include('reports.partials.quick-date-buttons')
            <div class="filters-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply'])
                @include('reports.partials.filters-reset-link', ['route' => 'reports.market-study.index'])
                <span class="muted">Export:</span>
                <a href="#" class="market-study-export-link export-link" data-export-base="{{ route('reports.market-study.export.csv') }}">CSV</a>
                <a href="#" class="market-study-export-link export-link" data-export-base="{{ route('reports.market-study.export.pdf') }}">PDF</a>
            </div>
        </div>
    </details>
</form>

<section class="tasks-card market-study-card">
    <h2 class="tasks-card__title">Price insights</h2>
    <div class="lab-kpis market-study-kpis">
        <div>
            <p class="lab-kpi__label">Today’s price</p>
            <p class="lab-kpi__value">
                @if ($todayPrice !== null)
                    {{ display_number((float) $todayPrice, 4) }}
                @else
                    —
                @endif
            </p>
            <p class="lab-kpi__delta lab-kpi__delta--flat">
                @if (($insights['today_count'] ?? 0) > 0)
                    {{ (int) $insights['today_count'] }} log{{ (int) $insights['today_count'] === 1 ? '' : 's' }} today
                @else
                    No logs today for this filter
                @endif
            </p>
        </div>
        <div>
            <p class="lab-kpi__label">Range average</p>
            <p class="lab-kpi__value">
                @if ($rangeAvg !== null)
                    {{ display_number((float) $rangeAvg, 4) }}
                @else
                    —
                @endif
            </p>
            <p class="lab-kpi__delta lab-kpi__delta--flat">Avg price / KG in selected range</p>
        </div>
        <div>
            <p class="lab-kpi__label">Entries in range</p>
            <p class="lab-kpi__value">{{ display_number($rangeCount, 0) }}</p>
            <p class="lab-kpi__delta lab-kpi__delta--flat">Matching filters</p>
        </div>
        <div>
            <p class="lab-kpi__label">Today vs range</p>
            <p class="lab-kpi__value">
                @if ($deltaPct !== null)
                    {{ ($deltaPct > 0 ? '+' : '').display_number((float) $deltaPct, 1) }}%
                @else
                    —
                @endif
            </p>
            <p class="lab-kpi__delta {{ $deltaClass }}">
                @if ($deltaPct !== null)
                    {{ $deltaPct > 0.05 ? 'Above range average' : ($deltaPct < -0.05 ? 'Below range average' : 'Near range average') }}
                @else
                    Needs today + range data
                @endif
            </p>
        </div>
    </div>
</section>

<section class="tasks-card market-study-card">
    <h2 class="tasks-card__title">Price over time</h2>
    <p class="muted market-study-chart-hint">
        Daily average price / KG for the current filters.
        @if (($filters['item'] ?? '') !== '' && ($filters['brand'] ?? '') === '')
            Multiple brands shown as separate series.
        @endif
    </p>
    <div class="market-study-chart-wrap">
        <canvas id="market-study-chart" height="120" aria-label="Market price chart"></canvas>
        <p id="market-study-chart-empty" class="muted" @if (($chart['labels'] ?? []) !== []) hidden @endif>No price points in this range.</p>
    </div>
</section>

<section class="tasks-card market-study-card">
    <h2 class="tasks-card__title">Price log ({{ count($entries) }})</h2>
    @if ($entries === [])
        <p class="muted">No entries match these filters.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Logged on</th>
                    <th>Market</th>
                    <th>Item</th>
                    <th>Brand</th>
                    <th>Price / KG</th>
                    <th>Notes</th>
                    @if ($canEdit || $canDelete)
                        <th>Actions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @foreach ($entries as $entry)
                @php $entryId = (int) ($entry->id ?? 0); @endphp
                @if ($canEdit)
                    <tr>
                        <td>
                            <form method="POST" action="{{ route('reports.market-study.update', $entryId) }}" id="market-study-edit-{{ $entryId }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="market" value="{{ $filters['market'] ?? '' }}">
                                <input type="hidden" name="item" value="{{ $filters['item'] ?? '' }}">
                                <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
                                <input type="hidden" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                                <input type="hidden" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                                <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">
                                <input type="date" name="logged_on" value="{{ $entry->logged_on }}" required aria-label="Logged on">
                        </td>
                        <td>
                                <input type="text" name="market_name" value="{{ $entry->market_name }}" maxlength="255" required aria-label="Market">
                        </td>
                        <td>
                                <input type="text" name="item_name" value="{{ $entry->item_name }}" maxlength="255" required aria-label="Item">
                        </td>
                        <td>
                                <input type="text" name="brand" value="{{ $entry->brand }}" maxlength="255" required aria-label="Brand">
                        </td>
                        <td>
                                <input type="number" name="price_per_kg" value="{{ $entry->price_per_kg }}" step="0.0001" min="0.0001" required aria-label="Price per KG">
                        </td>
                        <td>
                                <input type="text" name="notes" value="{{ $entry->notes }}" maxlength="5000" aria-label="Notes" placeholder="Notes">
                        </td>
                        <td class="tasks-actions">
                                @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save changes'])
                            </form>
                            @if ($canDelete)
                                <form method="POST" action="{{ route('reports.market-study.destroy', $entryId) }}" onsubmit="return confirm('Delete this market price entry?');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="market" value="{{ $filters['market'] ?? '' }}">
                                    <input type="hidden" name="item" value="{{ $filters['item'] ?? '' }}">
                                    <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
                                    <input type="hidden" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                                    <input type="hidden" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                                    <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">
                                    @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete'])
                                </form>
                            @endif
                        </td>
                    </tr>
                @else
                    <tr>
                        <td>{{ $entry->logged_on }}</td>
                        <td>{{ $entry->market_name }}</td>
                        <td>{{ $entry->item_name }}</td>
                        <td>{{ $entry->brand }}</td>
                        <td>{{ display_number((float) ($entry->price_per_kg ?? 0), 4) }}</td>
                        <td>{{ $entry->notes }}</td>
                        @if ($canDelete)
                            <td class="tasks-actions">
                                <form method="POST" action="{{ route('reports.market-study.destroy', $entryId) }}" onsubmit="return confirm('Delete this market price entry?');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="market" value="{{ $filters['market'] ?? '' }}">
                                    <input type="hidden" name="item" value="{{ $filters['item'] ?? '' }}">
                                    <input type="hidden" name="brand" value="{{ $filters['brand'] ?? '' }}">
                                    <input type="hidden" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                                    <input type="hidden" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                                    <input type="hidden" name="q" value="{{ $filters['q'] ?? '' }}">
                                    @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete'])
                                </form>
                            </td>
                        @endif
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    @endif
</section>
@endif

<datalist id="market-study-market-options">
    @foreach ($marketOptions as $opt)
        <option value="{{ $opt }}"></option>
    @endforeach
</datalist>
<datalist id="market-study-brand-options">
    @foreach ($brandOptions as $opt)
        <option value="{{ $opt }}"></option>
    @endforeach
</datalist>

<style>
.market-study-card { margin-top: 1rem; }
.market-study-form-grid { align-items: end; }
.market-study-item-wrap { position: relative; }
.market-study-suggestions {
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    top: calc(100% + 2px);
    margin: 0;
    padding: 0.25rem 0;
    list-style: none;
    background: var(--rp-surface, #fff);
    border: 1px solid var(--rp-border, #d1d5db);
    border-radius: 6px;
    max-height: 220px;
    overflow: auto;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
}
.market-study-suggestions li {
    padding: 0.45rem 0.7rem;
    cursor: pointer;
}
.market-study-suggestions li:hover,
.market-study-suggestions li.is-active {
    background: var(--rp-muted-bg, #f3f4f6);
}
.market-study-suggestions__meta {
    display: block;
    font-size: 12px;
    color: var(--rp-muted, #6b7280);
}
.market-study-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}
.market-study-chart-wrap {
    position: relative;
    min-height: 260px;
}
.market-study-chart-hint { margin: 0 0 0.75rem; }
@media (max-width: 900px) {
    .market-study-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 560px) {
    .market-study-kpis { grid-template-columns: 1fr; }
}
</style>

@if ($canView)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    var chartPayload = @json($chart);
    var canvas = document.getElementById('market-study-chart');
    var emptyEl = document.getElementById('market-study-chart-empty');
    if (!canvas || typeof Chart === 'undefined') return;

    var labels = (chartPayload && chartPayload.labels) ? chartPayload.labels : [];
    var datasetsIn = (chartPayload && chartPayload.datasets) ? chartPayload.datasets : [];
    if (!labels.length || !datasetsIn.length) {
        if (emptyEl) emptyEl.hidden = false;
        return;
    }
    if (emptyEl) emptyEl.hidden = true;

    var palette = ['#0f766e', '#b45309', '#1d4ed8', '#be123c', '#7c3aed', '#047857', '#c2410c', '#0369a1'];
    var datasets = datasetsIn.map(function (ds, i) {
        var color = palette[i % palette.length];
        return {
            label: ds.label || 'Price / KG',
            data: ds.data || [],
            borderColor: color,
            backgroundColor: color,
            tension: 0.25,
            spanGaps: true,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2
        };
    });

    new Chart(canvas, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: datasets.length > 1, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var v = ctx.parsed.y;
                            if (v === null || typeof v === 'undefined') return ctx.dataset.label + ': —';
                            return ctx.dataset.label + ': ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 4 });
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                y: {
                    beginAtZero: false,
                    title: { display: true, text: 'Price / KG' }
                }
            }
        }
    });
})();
</script>
@include('reports.partials.quick-date-buttons-script', ['formId' => 'market-study-filter-form'])
@include('reports.partials.export-from-form-script', ['formId' => 'market-study-filter-form', 'linkClass' => 'market-study-export-link'])
@endif

@if ($canAdd)
<script>
(function () {
    var itemInput = document.getElementById('add_item_name');
    var brandInput = document.getElementById('add_brand');
    var marketInput = document.getElementById('add_market_name');
    var list = document.getElementById('market-study-item-suggestions');
    if (!itemInput || !list) return;

    var suggestUrl = @json(route('reports.market-study.suggest'));
    var timer = null;
    var activeIndex = -1;
    var current = [];

    function hide() {
        list.hidden = true;
        list.innerHTML = '';
        activeIndex = -1;
        current = [];
    }

    function applySuggestion(item) {
        if (!item) return;
        itemInput.value = item.item_name || '';
        if (brandInput && item.brand) brandInput.value = item.brand;
        if (marketInput && item.market_name && !marketInput.value) marketInput.value = item.market_name;
        hide();
        itemInput.focus();
    }

    function render(items) {
        current = items || [];
        activeIndex = -1;
        list.innerHTML = '';
        if (!current.length) {
            hide();
            return;
        }
        current.forEach(function (item, idx) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.dataset.index = String(idx);
            var title = document.createElement('span');
            title.textContent = item.label || item.item_name || '';
            li.appendChild(title);
            if (item.brand) {
                var meta = document.createElement('span');
                meta.className = 'market-study-suggestions__meta';
                meta.textContent = 'Brand: ' + item.brand + (item.market_name ? ' · Market: ' + item.market_name : '');
                li.appendChild(meta);
            }
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                applySuggestion(item);
            });
            list.appendChild(li);
        });
        list.hidden = false;
    }

    function fetchSuggestions(q) {
        var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q || '');
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { suggestions: [] }; })
            .then(function (data) { render(data.suggestions || []); })
            .catch(function () { hide(); });
    }

    itemInput.addEventListener('input', function () {
        clearTimeout(timer);
        var q = itemInput.value.trim();
        timer = setTimeout(function () { fetchSuggestions(q); }, 180);
    });
    itemInput.addEventListener('focus', function () {
        fetchSuggestions(itemInput.value.trim());
    });
    itemInput.addEventListener('blur', function () {
        setTimeout(hide, 120);
    });
    itemInput.addEventListener('keydown', function (e) {
        if (list.hidden || !current.length) return;
        var items = list.querySelectorAll('li');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(current.length - 1, activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(0, activeIndex - 1);
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            applySuggestion(current[activeIndex]);
            return;
        } else if (e.key === 'Escape') {
            hide();
            return;
        } else {
            return;
        }
        items.forEach(function (el, i) {
            el.classList.toggle('is-active', i === activeIndex);
        });
    });
})();
</script>
@endif
@endsection
