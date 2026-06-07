@extends('reports.layouts.app')
@section('title', 'Visits report')

@section('content')
@php
    use App\Support\VisitsReportRowValues;
@endphp
<header class="page-header"><h1>Visits report</h1></header>
<p class="hint">
        <strong>Clients</strong> and <strong>salesmen</strong> follow the <a href="{{ route('reports.identifier.index') }}">Identifier</a> rules.
        A client is <strong>visited</strong> in a period if there is at least one non-cancelled store document line with a title date in that period for that account.
        If your date range spans <strong>more than one calendar month</strong>, you get one column per month (visit in that month only). Otherwise a single <strong>Visit status</strong> column is used.
        Leave <strong>salesman</strong> empty to list all clients (with city filters applied). Leave <strong>cities</strong> unselected to include all cities.
    </p>

    <form id="visits-filter-form" method="get" action="{{ route('reports.visits.index') }}">
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
            <label for="salesman_id">Salesman (optional)</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach ($salesmen as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? null) === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="span-full">
            <label>Cities (optional)</label>
            @include('reports.partials.city-picker', [
                'pickerId' => 'visits',
                'cityOptions' => $cityOptionsForPicker ?? [],
                'selectedCities' => $filters['cities'] ?? [],
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
        <div class="filter-checkbox">
            <label>
                <input type="checkbox" name="show_month_sales" value="1" @checked($filters['show_month_sales'] ?? false)>
                Show monthly sales per client
            </label>
            <p class="field-help">Adds a sales total column next to each visit month (posted sales invoices <code>S</code> only, discount-aware amount, same basis as the Sales report).</p>
        </div>
        <div class="filter-checkbox">
            <label>
                <input type="checkbox" name="sort_by_city" value="1" @checked($filters['sort_by_city'] ?? false)>
                Group by city (A–Z), not visited first per city
            </label>
            <p class="field-help">When enabled, the table and exports list cities alphabetically, show not-visited clients before visited within each city, and PDF/CSV include visited / not-visited totals per city.</p>
        </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.visits.index'])
                    <span class="muted">Export:</span>
                    <a href="#" class="visits-export-link export-link" data-export-base="{{ route('reports.visits.export.pdf') }}" title="PDF includes up to {{ \App\Repositories\VisitsReportRepository::MAX_PDF_EXPORT_ROWS }} clients; use CSV for larger exports">PDF</a>
                    <a href="#" class="visits-export-link export-link" data-export-base="{{ route('reports.visits.export.csv') }}">CSV</a>
                </div>
            </div>
        </details>
    </form>

    @include('reports.partials.city-picker-script', ['pickerId' => 'visits', 'selectedCities' => $filters['cities'] ?? []])
    @include('reports.partials.quick-date-buttons-script', ['formId' => 'visits-filter-form'])
    @include('reports.partials.export-from-form-script', ['formId' => 'visits-filter-form', 'linkClass' => 'visits-export-link'])

    @if (! $errorMessage)
        @php
            $showMonthSales = (bool) ($filters['show_month_sales'] ?? false);
            $monthColCount = ($multiMonth ?? false)
                ? count($monthSegments ?? []) * ($showMonthSales ? 2 : 1)
                : ($showMonthSales ? 2 : 1);
            $emptyColspan = 5 + $monthColCount;
        @endphp
        <table>
            <thead>
            @if (($multiMonth ?? false) && $showMonthSales)
            <tr>
                <th rowspan="2">#</th>
                <th rowspan="2">Client code</th>
                <th rowspan="2">Client name</th>
                <th rowspan="2">City</th>
                <th rowspan="2">Salesman</th>
                @foreach ($monthSegments ?? [] as $seg)
                    <th colspan="2">{{ $seg['label'] }}</th>
                @endforeach
            </tr>
            <tr>
                @foreach ($monthSegments ?? [] as $seg)
                    <th>Visit</th>
                    <th>Sales</th>
                @endforeach
            </tr>
            @else
            <tr>
                <th>#</th>
                <th>Client code</th>
                <th>Client name</th>
                <th>City</th>
                <th>Salesman</th>
                @if ($multiMonth ?? false)
                    @foreach ($monthSegments ?? [] as $seg)
                        <th>{{ $seg['label'] }}</th>
                        @if ($showMonthSales)
                            <th>{{ $seg['label'] }} — sales</th>
                        @endif
                    @endforeach
                @else
                    <th>Visit status</th>
                    @if ($showMonthSales)
                        <th>Sales</th>
                    @endif
                @endif
            </tr>
            @endif
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}</td>
                    <td>{{ $row->client_code }}</td>
                    <td>{{ $row->client_name }}</td>
                    <td>{{ $row->city }}</td>
                    <td>{{ $row->salesman_name }}</td>
                    @if ($multiMonth ?? false)
                        @foreach ($monthSegments ?? [] as $seg)
                            @php
                                $alias = $seg['sql_alias'];
                                $hit = VisitsReportRowValues::readMonthFlag($row, $alias);
                            @endphp
                            <td>
                                @if ($hit)
                                    <span class="badge badge-yes">Visited</span>
                                @else
                                    <span class="badge badge-no">Not visited</span>
                                @endif
                            </td>
                            @if ($showMonthSales)
                                <td class="num">{{ display_number(VisitsReportRowValues::readSalesAmount($row, (string) $seg['sales_sql_alias'])) }}</td>
                            @endif
                        @endforeach
                    @else
                        <td>
                            @if ((int) ($row->visited ?? 0) === 1)
                                <span class="badge badge-yes">Visited</span>
                            @else
                                <span class="badge badge-no">Not visited</span>
                            @endif
                        </td>
                        @if ($showMonthSales)
                            <td class="num">{{ display_number(VisitsReportRowValues::readSalesAmount($row, 'month_sales')) }}</td>
                        @endif
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $emptyColspan }}" style="color:#666;">No rows match the filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        @include('reports.partials.pagination', ['paginator' => $rows])
    @endif
@endsection

@push('styles')
<style>
table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        td.num { text-align: right; white-space: nowrap; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-yes { background: #dcfce7; color: #166534; }
        .badge-no { background: #fee2e2; color: #991b1b; }
        .filter-checkbox label { display: flex; align-items: flex-start; gap: 8px; font-weight: 600; }
        .filter-checkbox input { margin-top: 3px; }
</style>
@endpush

