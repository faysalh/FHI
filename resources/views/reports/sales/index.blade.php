@extends('reports.layouts.app')
@section('title', 'Sales report by item')

@section('content')
<header class="page-header"><h1>Sales report by item</h1></header>

<form id="sales-filter-form" method="GET" action="{{ route('reports.sales.index') }}">
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
                    <label for="salesman_id">Salesman</label>
                    <select id="salesman_id" name="salesman_id">
                        <option value="">All</option>
                        @foreach (($salesmanOptions ?? []) as $salesman)
                            <option value="{{ $salesman['id'] }}" @selected(($filters['salesman_id'] ?? '') === (string) $salesman['id'])>{{ $salesman['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="city">City</label>
                    <select id="city" name="city">
                        <option value="">All</option>
                        @foreach (($cityOptions ?? []) as $cityOption)
                            <option value="{{ $cityOption }}" @selected(($filters['city'] ?? '') === (string) $cityOption)>{{ $cityOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">All</option>
                        @foreach (($categories ?? []) as $categoryOption)
                            <option value="{{ $categoryOption }}" @selected(($filters['category'] ?? '') === (string) $categoryOption)>{{ $categoryOption }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="q">Item</label>
                    <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or code">
                </div>
            </div>
            <div class="filters-actions">
                <button type="submit" class="btn">Apply</button>
                <button type="submit" class="btn" formaction="{{ route('reports.sales.export.pdf') }}">Export PDF</button>
                <button type="submit" class="btn" formaction="{{ route('reports.sales.export.csv') }}">Export CSV</button>
            </div>
        </div>
    </details>
</form>
@include('reports.partials.quick-date-buttons-script', ['formId' => 'sales-filter-form'])

@if (! empty($error))
    <p class="flash flash-error">{{ $error }}</p>
@endif

<p class="report-hint">One row per item. Sales qty and amount are posted sales (S and POS). Returns (SR and POSR) are subtracted in the net columns. Amount is gross minus line discount and extra unit discount.</p>

<div class="report-container">
    @include('reports.sales.partials.item-table', ['rows' => $paginator->items(), 'showTotals' => $paginator->total() > 0])
</div>

{{ $paginator->links() }}
@endsection
