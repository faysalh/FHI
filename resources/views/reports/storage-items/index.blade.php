@extends('reports.layouts.app')
@section('title', 'Storage items report')
@section('container-class', 'report-container--wide')

@section('content')
@php $q = request()->query(); @endphp
    <div class="subtabs">
        <a href="{{ route('reports.storage-items.index', $q) }}" class="active">Inventory & sales</a>
        <a href="{{ route('reports.storage-items.evaluation', $q) }}">Evaluation (price / KG)</a>
    </div>

    <header class="page-header">
        <h1>Storage items report</h1>
    </header>
        @php $wdUi = max(1, min(366, (int) ($filters['working_days'] ?? 1))); @endphp
        <p class="hint">
        <strong>Inventory</strong> uses current stock (today). <strong>Sales</strong> sums document quantities in the selected date range (inclusive).
        <strong>Working days</strong> (Fridays excluded) divides period sales to get <strong>Sales average</strong>; <strong>Forecast</strong> is — when period sales are zero.
        Items with <strong>zero cartons</strong> are hidden unless they had <strong>sales</strong> in the selected date range.
        Rows with <strong>Forecast</strong> under 5 are highlighted <span class="forecast-legend forecast-legend--critical">red</span>; under 10 (and ≥ 5) <span class="forecast-legend forecast-legend--warning">orange</span>.
        For <strong>weight, price/KG, and value</strong> calculations, open <a href="{{ route('reports.storage-items.evaluation', $q) }}">Evaluation (price / KG)</a>.
        </p>

    @if ($errors->has('exclude_categories'))
        <div class="error">{{ $errors->first('exclude_categories') }}</div>
    @endif

    <form id="storage-items-filter-form" method="GET" action="{{ route('reports.storage-items.index') }}">
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
        <div>
            <label for="exclude_categories" title="Ctrl/Cmd+click for multiple; empty = none excluded">Exclude categories (optional)</label>
            <select id="exclude_categories" name="exclude_categories[]" multiple size="4" class="select-compact-multi">
                @foreach (($categoryOptions ?? []) as $cat)
                    <option value="{{ $cat }}" @selected(is_array($excludedSet) && in_array($cat, $excludedSet, true))>{{ $cat }}</option>
                @endforeach
            </select>
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
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.storage-items.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage-items.export.csv') }}">CSV</a>
                    <a href="#" class="storage-export-link export-link" data-export-base="{{ route('reports.storage-items.export.pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @if ($rows)
        @if (!empty($evaluationTotals))
            <div class="totals-bar" role="region" aria-label="Report totals">
                <div class="total-item">
                    <span>Carton (total)</span>
                    <strong class="num">{{ display_number($evaluationTotals['quantity_total'] ?? 0) }}</strong>
                </div>
                <div class="total-item">
                    <span>Sales (period)</span>
                    <strong class="num">{{ display_number($evaluationTotals['sold_quantity_period'] ?? 0) }}</strong>
                </div>
                <div class="muted" style="align-self:center;">All matching items (not just this page)</div>
            </div>
        @endif
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Item code</th>
                <th>Category</th>
                <th>Item name</th>
                <th class="num">Carton</th>
                <th class="num">Sales</th>
                <th class="num">Sales average</th>
                <th class="num">Forecast</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                @php
                    $quantity = (float) ($row->quantity_total ?? 0);
                    $sold = (float) ($row->sold_quantity_period ?? 0);
                    $avgWd = $sold / $wdUi;
                    $cover = ($sold > 0) ? ($quantity / $avgWd) : null;
                @endphp
                <tr @class([
                    'forecast-below-5' => $cover !== null && $cover < 5,
                    'forecast-below-10' => $cover !== null && $cover >= 5 && $cover < 10,
                ])>
                    <td>{{ (($rows->currentPage() - 1) * $rows->perPage()) + $loop->iteration }}</td>
                    <td>{{ $row->item_code ?? '' }}</td>
                    <td>{{ $row->category_name ?? '' }}</td>
                    <td>{{ $row->item_name ?? '' }}</td>
                    <td class="num">{{ display_number($quantity) }}</td>
                    <td class="num">{{ display_number($sold) }}</td>
                    <td class="num">{{ display_number($avgWd) }}</td>
                    <td class="num">@if ($cover !== null){{ display_number($cover) }}@else<span class="dash">—</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
            @if (!empty($evaluationTotals))
                <tfoot>
                <tr class="grand-total">
                    <td colspan="4"><strong>Total (all matching filters)</strong></td>
                    <td class="num"><strong>{{ display_number($evaluationTotals['quantity_total'] ?? 0) }}</strong></td>
                    <td class="num"><strong>{{ display_number($evaluationTotals['sold_quantity_period'] ?? 0) }}</strong></td>
                    <td colspan="2"></td>
                </tr>
                </tfoot>
            @endif
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif
</div>

@include('reports.partials.quick-date-buttons-script', [
    'formId' => 'storage-items-filter-form',
    'fromId' => 'sales_date_from',
    'toId' => 'sales_date_to',
])
@include('reports.partials.export-from-form-script', ['formId' => 'storage-items-filter-form', 'linkClass' => 'storage-export-link'])
@include('reports.storage-items.partials.sales-period-filters-script')
@endsection

@push('styles')
<style>
.subtabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .subtabs a { padding: 8px 14px; border-radius: 6px; text-decoration: none; background: #e2e8f0; color: #1e293b; font-size: 13px; font-weight: 600; }
        .subtabs a.active { background: #2563eb; color: #fff; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .dash { color: #94a3b8; }
        tbody tr.forecast-below-5 td { background-color: #fecaca; }
        tbody tr.forecast-below-10 td { background-color: #ffedd5; }
</style>
@endpush

