@extends('reports.layouts.app')
@section('title', 'Sales by item')

@section('content')
<header class="page-header"><h1>Sales by item</h1></header>
<p class="hint">
    Posted sales invoices (<code>S</code>) for the selected <strong>salesman</strong> and date range, grouped by item
    <strong>category</strong> (<code>fld_description</code>). Sales are attributed by the <strong>salesman on the invoice</strong>
    (<code>fld_sales_man_id_ref</code> on the document title), same as <a href="{{ route('reports.sales-by-salesman.index') }}">Sales by salesman</a>.
    Each sale is counted under the <strong>client’s price group</strong>
    (وكيل, وكيل 2, ماركيت, جملة, كي),
    not by the invoice line unit price. <strong>ماركيت</strong> lines use qty × price after line discount when the invoice has no header discount; otherwise proportional invoice header discount only (rounded per line, extra discount excluded). Other price groups use qty × price after line discount only (ERP salesman report basis).
    @if (!empty($priceGroupColumn))
        Tier source: <code>{{ $priceGroupColumn }}</code>.
    @endif
</p>

<form id="sales-by-item-filter-form" method="GET" action="{{ route('reports.sales-by-item.index') }}">
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
                    <label for="salesman_id">Salesman</label>
                    <select id="salesman_id" name="salesman_id" required>
                        <option value="">— Select —</option>
                        @foreach ($salesmen as $sm)
                            <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === $sm['id'])>{{ $sm['name'] }}</option>
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
                <div class="span-full">
                    <label>Price groups (client tiers)</label>
                    <div class="price-tier-checkboxes" style="display:flex;flex-wrap:wrap;gap:10px 16px;margin-top:6px;">
                        @foreach ($priceTiers as $tier)
                            @php
                                $selectedTiers = $filters['price_tiers'] ?? [];
                                $tierChecked = $selectedTiers === [] || in_array($tier['tier'], $selectedTiers, true);
                            @endphp
                            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
                                <input type="checkbox" name="price_tiers[]" value="{{ $tier['tier'] }}" @checked($tierChecked)>
                                {{ 'Price '.$tier['tier'].' ('.$tier['label'].')' }}
                            </label>
                        @endforeach
                    </div>
                    <p class="muted" style="margin:4px 0 0;font-size:12px;">Uncheck groups to hide them from the table and exports. All checked = every price group.</p>
                </div>
                <div class="span-full">
                    <label>Columns</label>
                    <div class="metric-checkboxes" style="display:flex;flex-wrap:wrap;gap:10px 16px;margin-top:6px;">
                        @foreach (\App\Support\SalesItemReportMetrics::definitions() as $metric)
                            @php
                                $selectedMetrics = $filters['metrics'] ?? [];
                                $metricChecked = $selectedMetrics === [] || in_array($metric['key'], $selectedMetrics, true);
                            @endphp
                            <label style="display:inline-flex;align-items:center;gap:6px;font-weight:normal;">
                                <input type="checkbox" name="metrics[]" value="{{ $metric['key'] }}" @checked($metricChecked)>
                                {{ $metric['label'] }}
                            </label>
                        @endforeach
                    </div>
                    <p class="muted" style="margin:4px 0 0;font-size:12px;">Uncheck Qty, Amount, or Weight to hide those columns from the table and exports. All checked = every column.</p>
                </div>
                <div class="span-full">
                    <label>Categories (optional)</label>
                    <select id="categories" name="categories[]" multiple size="4" class="select-compact-multi">
                        @foreach (($categoryOptions ?? []) as $categoryOption)
                            <option value="{{ $categoryOption }}" @selected(in_array($categoryOption, $filters['categories'] ?? [], true))>{{ $categoryOption }}</option>
                        @endforeach
                    </select>
                    <p class="muted" style="margin:4px 0 0;font-size:12px;">Leave empty for all categories. Options refresh after you choose a salesman and apply filters.</p>
                </div>
                <div class="span-full">
                    <label>Cities (optional)</label>
                    @include('reports.partials.city-picker', [
                        'pickerId' => 'sbi',
                        'cityOptions' => $cityOptions ?? [],
                        'selectedCities' => $filters['cities'] ?? [],
                        'note' => ($hasCityColumn ?? false)
                            ? 'Add multiple cities by searching again. Leave empty for all cities.'
                            : 'City filtering is unavailable because no city column could be resolved on accounts.',
                        'disabled' => ! ($hasCityColumn ?? false),
                    ])
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
                @include('reports.partials.filters-reset-link', ['route' => 'reports.sales-by-item.index'])
                @if (!($needsSalesman ?? false))
                    <span class="muted">Export:</span>
                    <a href="#" class="sales-by-item-export-link export-link" data-export-base="{{ route('reports.sales-by-item.export.csv') }}">CSV</a>
                    <a href="#" class="sales-by-item-export-link export-link" data-export-base="{{ route('reports.sales-by-item.export.pdf') }}">PDF</a>
                @endif
            </div>
        </div>
    </details>
</form>

@include('reports.partials.city-picker-script', [
    'pickerId' => 'sbi',
    'selectedCities' => $filters['cities'] ?? [],
])
@include('reports.partials.quick-date-buttons-script', ['formId' => 'sales-by-item-filter-form'])
@include('reports.partials.export-from-form-script', ['formId' => 'sales-by-item-filter-form', 'linkClass' => 'sales-by-item-export-link'])

@if (!empty($errorMessage))
    <div class="alert alert--error" role="alert">{{ $errorMessage }}</div>
@endif

@if ($needsSalesman ?? false)
    <p class="muted">Select a salesman and click <strong>Apply filters</strong> to load the report.</p>
@elseif ($rows)
    @php
        $activePriceTiers = $activePriceTiers ?? $priceTiers;
        $activeMetrics = $activeMetrics ?? \App\Support\SalesItemReportMetrics::definitions();
        $showUnknownColumn = (bool) ($showUnknownColumn ?? true);
        $metricGroups = count($activePriceTiers) + ($showUnknownColumn ? 1 : 0) + 1;
        $colsPerGroup = count($activeMetrics);
        $showAmountSummary = collect($activeMetrics)->contains(fn (array $m): bool => ($m['key'] ?? '') === 'amt');
    @endphp
    @if (!empty($salesmanName))
        <p class="muted">Salesman: <strong>{{ $salesmanName }}</strong></p>
    @endif

    @if (!empty($grandTotals) && $showAmountSummary)
        <div class="lab-card" style="margin-bottom:12px;">
            <h3 class="section-title" style="margin-top:0;">Amount by client price group</h3>
            <table>
                <thead>
                <tr>
                    @foreach ($activePriceTiers as $tier)
                        <th class="num">{{ $tier['label'] }}</th>
                    @endforeach
                    @if ($showUnknownColumn)
                        <th class="num">Unknown</th>
                    @endif
                    <th class="num">Total</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    @foreach ($activePriceTiers as $tier)
                        <td class="num">{{ display_number((float) ($grandTotals->{'p'.$tier['tier'].'_amt'} ?? 0)) }}</td>
                    @endforeach
                    @if ($showUnknownColumn)
                        <td class="num">{{ display_number((float) ($grandTotals->unmatched_amt ?? 0)) }}</td>
                    @endif
                    <td class="num">{{ display_number((float) ($grandTotals->total_amt ?? 0)) }}</td>
                </tr>
                </tbody>
            </table>
        </div>
    @endif

    <div class="table-scroll-wide">
        <table class="sales-by-item-table">
            <thead>
            <tr>
                <th rowspan="2">Category</th>
                @foreach ($activePriceTiers as $tier)
                    <th colspan="{{ $colsPerGroup }}" class="num">{{ 'Price '.$tier['tier'].' ('.$tier['label'].')' }}</th>
                @endforeach
                @if ($showUnknownColumn)
                    <th colspan="{{ $colsPerGroup }}" class="num">Unknown group</th>
                @endif
                <th colspan="{{ $colsPerGroup }}" class="num">Total</th>
            </tr>
            <tr>
                @for ($i = 0; $i < $metricGroups; $i++)
                    @foreach ($activeMetrics as $metric)
                        <th class="num">{{ $metric['label'] }}</th>
                    @endforeach
                @endfor
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row->category_name ?? '' }}</td>
                    @foreach ($activePriceTiers as $tier)
                        @foreach ($activeMetrics as $metric)
                            @php $field = \App\Support\SalesItemReportMetrics::fieldKey('tier', $metric['suffix'], $tier['tier']); @endphp
                            <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                        @endforeach
                    @endforeach
                    @if ($showUnknownColumn)
                        @foreach ($activeMetrics as $metric)
                            @php $field = \App\Support\SalesItemReportMetrics::fieldKey('unmatched', $metric['suffix']); @endphp
                            <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                        @endforeach
                    @endif
                    @foreach ($activeMetrics as $metric)
                        @php $field = \App\Support\SalesItemReportMetrics::fieldKey('total', $metric['suffix']); @endphp
                        <td class="num">{{ display_number((float) ($row->{$field} ?? 0)) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
            @if (!empty($grandTotals))
                <tfoot>
                <tr class="grand-total-row">
                    <th>Total</th>
                    @foreach ($activePriceTiers as $tier)
                        @foreach ($activeMetrics as $metric)
                            @php $field = \App\Support\SalesItemReportMetrics::fieldKey('tier', $metric['suffix'], $tier['tier']); @endphp
                            <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                        @endforeach
                    @endforeach
                    @if ($showUnknownColumn)
                        @foreach ($activeMetrics as $metric)
                            @php $field = \App\Support\SalesItemReportMetrics::fieldKey('unmatched', $metric['suffix']); @endphp
                            <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                        @endforeach
                    @endif
                    @foreach ($activeMetrics as $metric)
                        @php $field = \App\Support\SalesItemReportMetrics::fieldKey('total', $metric['suffix']); @endphp
                        <td class="num">{{ display_number((float) ($grandTotals->{$field} ?? 0)) }}</td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @include('reports.partials.pagination', ['paginator' => $rows])
@endif

<style>
    .table-scroll-wide { overflow-x: auto; max-width: 100%; margin-top: 12px; }
    .sales-by-item-table { min-width: 1200px; font-size: 13px; }
    .sales-by-item-table th, .sales-by-item-table td { white-space: nowrap; }
    .sales-by-item-table thead th { vertical-align: bottom; }
</style>
@endsection
