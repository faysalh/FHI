@extends('reports.layouts.app')
@section('title', 'Account search')

@section('content')
<header class="page-header">
    <h1>Account search</h1>
</header>
<p class="hint">Search customer accounts by name, code, or city. For category sales use <a href="{{ route('reports.sales.index') }}">Sales</a>; for database tables use <a href="{{ route('reports.schema.index') }}">Schema</a>.</p>

    <form id="customers-filter-form" method="GET" action="{{ route('reports.customers.index') }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
            <div class="filters-body">
                <div class="filters-grid">
                    <div>
                        <label for="q">Search</label>
                        <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name or code">
                    </div>
                    <div>
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" value="{{ $filters['city'] ?? '' }}" placeholder="Optional">
                    </div>
                    <div>
                        <label for="per_page">Rows / page</label>
                        <select id="per_page" name="per_page">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.customers.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="customers-export-link export-link" data-export-base="{{ route('reports.customers.export.csv') }}">CSV</a>
                </div>
            </div>
        </details>
    </form>

    @if ($rows)
        @php
            $items = $rows->items();
            $first = $items[0] ?? null;
        @endphp
        @if (count($items) === 0)
            <p class="report-empty">No accounts match your search. Try a shorter name fragment or clear the city filter.</p>
        @else
            <table>
                <thead>
                <tr>
                    @foreach (array_keys((array) $first) as $column)
                        <th>{{ strtoupper(str_replace('_', ' ', (string) $column)) }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($items as $row)
                    <tr>
                        @foreach ((array) $row as $column => $value)
                            <td data-label="{{ strtoupper(str_replace('_', ' ', (string) $column)) }}">{{ $value !== null && ! is_bool($value) && is_numeric($value) ? display_number($value) : (string) $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div style="margin-top: 12px;">
                @include('reports.partials.pagination', ['paginator' => $rows])
            </div>
            <div class="report-meta">
                Source table: {{ $table ?? '-' }} · Total: {{ $rows->total() }}
            </div>
        @endif
    @endif
    @include('reports.partials.export-from-form-script', ['formId' => 'customers-filter-form', 'linkClass' => 'customers-export-link'])
@endsection

@push('styles')
<style>
        @media (max-width: 720px) {
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead {
                display: none;
            }
            tr {
                border: 1px solid #ececec;
                border-radius: 6px;
                margin-bottom: 10px;
                padding: 8px;
            }
            td {
                border: 0;
                padding: 6px 0;
            }
            td::before {
                content: attr(data-label) ": ";
                font-weight: bold;
            }
        }
</style>
@endpush

