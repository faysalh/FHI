@extends('reports.layouts.app')
@section('title', 'Rankings')

@section('content')
@php
    $activeTab = $filters['tab'] ?? 'clients';
    $isGrowthTab = in_array($activeTab, ['growing', 'declining'], true);
    $limit = (int) ($filters['limit'] ?? 10);
    $tabUrl = static fn (string $tab): string => route('reports.rankings.index', array_merge(request()->query(), ['tab' => $tab]));
    $nameColumn = match ($activeTab) {
        'items' => 'Item',
        'salesmen' => 'Salesman',
        'categories' => 'Category',
        'cities' => 'City',
        default => 'Client',
    };
@endphp

<header class="page-header"><h1>Rankings</h1></header>

<nav class="subtabs" aria-label="Rankings views">
    @foreach (($tabs ?? []) as $tabKey => $tabLabel)
        <a href="{{ $tabUrl($tabKey) }}" class="{{ $activeTab === $tabKey ? 'active' : '' }}">{{ $tabLabel }}</a>
    @endforeach
</nav>

<p class="hint">
    Top {{ $limit }} ranked by
    @if ($isGrowthTab)
        <strong>revenue growth %</strong> vs the prior period of equal length ({{ $priorPeriodLabel ?? '—' }}).
    @else
        <strong>{{ $filters['metric'] ?? 'amount' }}</strong> for posted sales invoices (<code>S</code>) with the same discount-aware amounts as the Sales report.
    @endif
    Share % is each row’s amount divided by total period sales for the current filters.
</p>

@if (!empty($errorMessage))
    <div class="alert alert--error" role="alert">{{ $errorMessage }}</div>
@endif

<form id="rankings-filter-form" method="GET" action="{{ route('reports.rankings.index') }}">
    <input type="hidden" name="tab" value="{{ $activeTab }}">
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
                    <label for="saved_governorate_id">Governorate</label>
                    <select id="saved_governorate_id" name="saved_governorate_id">
                        <option value="">All cities</option>
                        @foreach (($savedGovernorates ?? []) as $gov)
                            <option value="{{ (int) ($gov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($gov->id ?? 0))>{{ $gov->name ?? '' }}</option>
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
                    <label for="salesman_ids" title="Ctrl/Cmd+click for multiple">Salesmen</label>
                    <select id="salesman_ids" name="salesman_ids[]" multiple size="3" class="select-compact-multi">
                        @foreach (($salesmanOptions ?? []) as $salesman)
                            <option value="{{ $salesman['id'] }}" @selected(in_array($salesman['id'], $filters['salesman_ids'] ?? [], true))>{{ $salesman['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if (! $isGrowthTab)
                <div>
                    <label for="metric">Rank by</label>
                    <select id="metric" name="metric">
                        <option value="amount" @selected(($filters['metric'] ?? 'amount') === 'amount')>Amount</option>
                        <option value="quantity" @selected(($filters['metric'] ?? '') === 'quantity')>Quantity</option>
                        <option value="weight" @selected(($filters['metric'] ?? '') === 'weight')>Weight</option>
                    </select>
                </div>
                @else
                <input type="hidden" name="metric" value="amount">
                @endif
                <div>
                    <label for="limit">Show top</label>
                    <select id="limit" name="limit">
                        @foreach ([10, 25] as $n)
                            <option value="{{ $n }}" @selected($limit === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="filters-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                @include('reports.partials.filters-reset-link', ['route' => 'reports.rankings.index', 'params' => ['tab' => $activeTab]])
                @if (empty($errorMessage) && ($rows ?? []) !== [])
                    <span class="muted">Export:</span>
                    <a href="#" class="rankings-export-link export-link" data-export-base="{{ route('reports.rankings.export.csv') }}">CSV</a>
                    <a href="#" class="rankings-export-link export-link" data-export-base="{{ route('reports.rankings.export.pdf') }}">PDF</a>
                @endif
            </div>
        </div>
    </details>
</form>

@if (!empty($filters['governorate_label']))
    <p class="muted">Governorate: <strong>{{ $filters['governorate_label'] }}</strong></p>
@endif

@if ($isGrowthTab && !empty($priorPeriodLabel))
    <div class="alert alert--info" role="status">Prior period: {{ $priorPeriodLabel }}</div>
@endif

@if (!empty($periodTotals) && empty($errorMessage))
    @include('reports.partials.metric-grand-totals-bar', [
        'grandTotals' => $periodTotals,
        'quantityLabel' => 'Period quantity (pcs)',
        'amountLabel' => 'Period amount (IQD)',
        'weightLabel' => 'Period weight (kg)',
        'grandTotalsNote' => $topNSharePct !== null
            ? 'Top '.$limit.' combined = '.display_number($topNSharePct, 1).'% of period amount'
            : null,
    ])
@endif

@if (empty($errorMessage))
<div class="table-scroll">
    <table class="lab-table rankings-table">
        <thead>
        <tr>
            <th class="num">#</th>
            <th>{{ $nameColumn }}</th>
            @if ($activeTab === 'clients' || $isGrowthTab)
                <th>Code</th>
            @endif
            @if ($activeTab === 'items')
                <th>Category</th>
            @endif
            @if ($isGrowthTab)
                <th class="num">Prior amount</th>
                <th class="num">Growth %</th>
            @endif
            <th class="num">Amount</th>
            <th class="num">Quantity</th>
            <th class="num">Weight</th>
            <th class="num">Invoices</th>
            <th class="num">Share %</th>
        </tr>
        </thead>
        <tbody>
        @forelse (($rows ?? []) as $row)
            @php
                $growth = $row->growth_pct ?? null;
                $growthClass = '';
                if ($growth !== null) {
                    $growthClass = (float) $growth >= 0 ? 'rankings-growth--up' : 'rankings-growth--down';
                }
            @endphp
            <tr>
                <td class="num">{{ $loop->iteration }}</td>
                <td>{{ $row->label ?? '' }}</td>
                @if ($activeTab === 'clients' || $isGrowthTab)
                    <td>{{ $row->client_code ?? '' }}</td>
                @endif
                @if ($activeTab === 'items')
                    <td>{{ $row->secondary_label ?? '' }}</td>
                @endif
                @if ($isGrowthTab)
                    <td class="num">{{ display_number((float) ($row->prior_amount ?? 0)) }}</td>
                    <td class="num {{ $growthClass }}">
                        @if ($growth !== null)
                            {{ display_number((float) $growth, 1) }}%
                        @else
                            —
                        @endif
                    </td>
                @endif
                <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->weight_total ?? 0), 1) }}</td>
                <td class="num">{{ display_number((float) ($row->invoice_count ?? 0)) }}</td>
                <td class="num">{{ display_number((float) ($row->share_pct ?? 0), 1) }}%</td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ 6 + ($activeTab === 'clients' || $isGrowthTab ? 1 : 0) + ($activeTab === 'items' ? 1 : 0) + ($isGrowthTab ? 2 : 0) }}" class="muted">
                    No ranked rows for this period and filters.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
@endif

@include('reports.partials.quick-date-buttons-script', ['formId' => 'rankings-filter-form'])
@include('reports.partials.export-from-form-script', ['formId' => 'rankings-filter-form', 'linkClass' => 'rankings-export-link'])
@endsection

@push('styles')
<style>
    .rankings-growth--up { color: #047857; font-weight: 600; }
    .rankings-growth--down { color: #b91c1c; font-weight: 600; }
    .rankings-table .num { font-variant-numeric: tabular-nums; }
</style>
@endpush
