@extends('reports.layouts.app')
@section('title', 'Comparison report')

@section('content')
<header class="page-header"><h1>Comparison report</h1></header>
@include('reports.comparison.partials.subtabs')
<p class="hint">Posted sales tab: compare two periods side by side. Sales metrics use posted invoices (<code>S</code>) with discount-aware amounts (same basis as the Sales report). Difference shows as period 2 minus period 1. Growth % is <code>(P2 − P1) / P1 × 100</code> when period 1 is not zero. Green means positive change, red means negative change.</p>

    <form id="comparison-filter-form" method="GET" action="{{ route('reports.comparison.index') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
                <div class="filters-body">
                    @include('reports.partials.quick-date-buttons', ['presets' => ['this-month-vs-last-month', 'last-30-vs-prior-30', 'this-month', 'last-30', 'last-month']])
                    <div class="filters-grid">
        <div>
            <label for="date_from_1">Period 1 from</label>
            <input type="date" id="date_from_1" name="date_from_1" value="{{ $filters['date_from_1'] }}">
        </div>
        <div>
            <label for="date_to_1">Period 1 to</label>
            <input type="date" id="date_to_1" name="date_to_1" value="{{ $filters['date_to_1'] }}">
        </div>
        <div>
            <label for="date_from_2">Period 2 from</label>
            <input type="date" id="date_from_2" name="date_from_2" value="{{ $filters['date_from_2'] }}">
        </div>
        <div>
            <label for="date_to_2">Period 2 to</label>
            <input type="date" id="date_to_2" name="date_to_2" value="{{ $filters['date_to_2'] }}">
        </div>
        <div>
            <label for="city">City (optional)</label>
            <select id="city" name="city">
                <option value="">All cities</option>
                @foreach (($cityOptions ?? []) as $city)
                    <option value="{{ $city }}" @selected(($filters['city'] ?? '') === $city)>{{ $city }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="salesman_id">Salesman (optional)</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach (($salesmanOptions ?? []) as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="saved_governorate_id">Governorate (from Cities)</label>
            <select id="saved_governorate_id" name="saved_governorate_id">
                <option value="">None</option>
                @foreach (($savedGovernorates ?? []) as $gov)
                    <option value="{{ (int) ($gov->id ?? 0) }}" @selected((string) ($filters['saved_governorate_id'] ?? '') === (string) (int) ($gov->id ?? 0))>{{ $gov->name ?? '' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="exclude_category">Exclude category (optional)</label>
            <select id="exclude_category" name="exclude_category">
                <option value="">None</option>
                @foreach (($categoryOptions ?? []) as $category)
                    <option value="{{ $category }}" @selected(($filters['exclude_category'] ?? '') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div class="span-full filters-breakdown">
            <div class="chk-row">
            @php $activeMetrics = $filters['metrics'] ?? ['quantity','amount','weight']; @endphp
            <label class="chk-label">
                <input type="checkbox" name="metrics[]" value="quantity" @checked(in_array('quantity', $activeMetrics, true))> Quantity (carton)
            </label>
            <label class="chk-label">
                <input type="checkbox" name="metrics[]" value="amount" @checked(in_array('amount', $activeMetrics, true))> Amount (IQD)
            </label>
            <label class="chk-label">
                <input type="checkbox" name="metrics[]" value="weight" @checked(in_array('weight', $activeMetrics, true))> Weight (kg)
            </label>
            </div>
        </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.comparison.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="comparison-export-link export-link" data-export-base="{{ route('reports.comparison.export.csv') }}">CSV</a>
                    <a href="#" class="comparison-export-link export-link" data-export-base="{{ route('reports.comparison.export.pdf') }}">PDF</a>
                </div>
            </div>
        </details>
    </form>

    @include('reports.partials.quick-date-buttons-script', [
        'formId' => 'comparison-filter-form',
        'fromId' => 'date_from_1',
        'toId' => 'date_to_1',
        'from2Id' => 'date_from_2',
        'to2Id' => 'date_to_2',
    ])
    @include('reports.partials.export-from-form-script', ['formId' => 'comparison-filter-form', 'linkClass' => 'comparison-export-link'])

    @include('reports.comparison.partials.results-table')
@endsection

@push('styles')
<style>
table { width:100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align:left; }
        th { background:#f9fafb; }
        .num { text-align:right; font-variant-numeric: tabular-nums; }
        .pos { color:#166534; font-weight:700; }
        .neg { color:#b91c1c; font-weight:700; }
        .neu { color:#475569; font-weight:700; }
        .group-head { text-align: center; font-weight: 700; }
        .sep-left { border-left: 3px solid #94a3b8 !important; }
        .growth-row td { font-size: 13px; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        .growth-row td.num.pos, .growth-row td.num.neg, .growth-row td.num.neu { font-weight: 700; }
</style>
@endpush
