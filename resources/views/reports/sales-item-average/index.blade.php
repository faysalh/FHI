@extends('reports.layouts.app')
@section('title', 'Sales by item average')

@section('content')
<header class="page-header"><h1>Sales by item average</h1></header>
<p class="hint">
        First level shows <strong>categories</strong> (from <code>fld_description</code>). Click a category to drill down to
        <strong>item names</strong> (from <code>fld_item_name</code>) with the same columns.
        Quantity, amount, and weight use posted sales invoices (<code>S</code>) and the same discount-aware amount as the Sales report.
        If <strong>From</strong> and <strong>To</strong> are set, average columns use business days in that range
        (Fridays and holidays from <a href="{{ route('reports.holidays.index') }}">Settings → Holidays</a> excluded, same as Dashboard lab).
        Balance coverage is current balance ÷ avg quantity per business day.
    </p>

    <form id="sales-item-average-filter-form" method="GET" action="{{ route('reports.sales-item-average.index') }}">
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
        <div class="span-full">
            <label>Cities (optional)</label>
            @include('reports.partials.city-picker', [
                'pickerId' => 'sia',
                'cityOptions' => $cityOptions ?? [],
                'selectedCities' => $filters['cities'] ?? [],
                'note' => ($hasCityColumn ?? false)
                    ? 'Add multiple cities by searching again. Leave empty for all cities.'
                    : 'City filtering is unavailable because no city column could be resolved on accounts.',
                'disabled' => ! ($hasCityColumn ?? false),
            ])
        </div>
        <div>
            <label>Business days in range</label>
            <p class="working-days-display">{{ display_number($filters['working_days'] ?? 0) }}</p>
            <p class="muted" style="margin:4px 0 0;font-size:12px;">Auto-calculated from dates (excludes Fri + holidays).</p>
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
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.sales-item-average.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="sales-item-average-export-link export-link" data-export-base="{{ route('reports.sales-item-average.export.csv') }}">CSV</a>
                    <a href="#" class="sales-item-average-export-link export-link" data-export-base="{{ route('reports.sales-item-average.export.pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @include('reports.partials.city-picker-script', [
        'pickerId' => 'sia',
        'selectedCities' => $filters['cities'] ?? [],
    ])
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'sales-item-average-filter-form'])
    @include('reports.partials.export-from-form-script', ['formId' => 'sales-item-average-filter-form', 'linkClass' => 'sales-item-average-export-link'])

    @if ($rows)
        @if (!empty($grandTotals))
            @include('reports.partials.metric-grand-totals-bar', [
                'grandTotals' => $grandTotals,
                'showAmount' => false,
                'showWeight' => false,
            ])
        @endif
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
            @if (!empty($grandTotals))
                @include('reports.partials.metric-grand-totals-tfoot', [
                    'grandTotals' => $grandTotals,
                    'labelColspan' => 1,
                    'trailingColspan' => $workingDaysDivisor ? 2 : 0,
                    'showAmount' => false,
                    'showWeight' => false,
                ])
            @endif
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
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
            return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(n));
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
@endsection

