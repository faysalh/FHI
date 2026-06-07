@extends('reports.layouts.app')
@section('title', 'Comparison report')

@section('content')
<header class="page-header"><h1>Comparison report</h1></header>
<p class="hint">Compare two periods side by side. Sales metrics use posted invoices (<code>S</code>) with discount-aware amounts (same basis as the Sales report). Difference shows as period 2 minus period 1. Growth % is <code>(P2 − P1) / P1 × 100</code> when period 1 is not zero. Green means positive change, red means negative change.</p>

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

    @if (!empty($rows))
        @php
            $diffClass = static function (float $value): string {
                if ($value > 0) return 'pos';
                if ($value < 0) return 'neg';
                return 'neu';
            };
            $metrics = $filters['metrics'] ?? ['quantity','amount','weight'];
            $metricLabel = static function (string $metric): string {
                return match ($metric) {
                    'quantity' => 'Quantity (carton)',
                    'amount' => 'Amount (IQD)',
                    'weight' => 'Weight (kg)',
                    default => ucfirst($metric),
                };
            };
            $metricValue = static function (string $metric, float $value): string {
                if ($metric === 'amount') {
                    return 'IQD '.display_number($value);
                }

                return display_number($value);
            };
            $groupedRows = is_array($groupedRows ?? null) ? $groupedRows : [];
            $totals = is_array($totals ?? null) ? $totals : [];
        @endphp
        <table>
            <thead>
            <tr>
                <th rowspan="2">Category</th>
                <th rowspan="2">Item</th>
                <th colspan="{{ count($metrics) }}" class="group-head">Period 1</th>
                <th colspan="{{ count($metrics) }}" class="group-head">Period 2</th>
                <th colspan="{{ count($metrics) }}" class="group-head">Difference (P2 - P1)</th>
            </tr>
            <tr>
                @foreach ($metrics as $metric)
                    <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricLabel((string) $metric) }}</th>
                @endforeach
                @foreach ($metrics as $metric)
                    <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricLabel((string) $metric) }}</th>
                @endforeach
                @foreach ($metrics as $metric)
                    <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricLabel((string) $metric) }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($groupedRows as $group)
                @php
                    $groupRows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
                    $groupTotals = is_array($group['totals'] ?? null) ? $group['totals'] : [];
                    $groupCategory = (string) ($group['category'] ?? '');
                @endphp
                @foreach ($groupRows as $row)
                    <tr>
                        <td>{{ $row->category_name ?? '' }}</td>
                        <td>{{ $row->item_name ?? '' }}</td>
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($row->{'period1_'.$metric} ?? 0)) }}</td>
                        @endforeach
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($row->{'period2_'.$metric} ?? 0)) }}</td>
                        @endforeach
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($row->{'diff_'.$metric} ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($row->{'diff_'.$metric} ?? 0)) }}</td>
                        @endforeach
                    </tr>
                @endforeach
                <tr style="background:#f8fafc;font-weight:700;">
                    <td>Subtotal</td>
                    <td>{{ $groupCategory }}</td>
                    @foreach ($metrics as $metric)
                        <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($groupTotals['period1_'.$metric] ?? 0)) }}</td>
                    @endforeach
                    @foreach ($metrics as $metric)
                        <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($groupTotals['period2_'.$metric] ?? 0)) }}</td>
                    @endforeach
                    @foreach ($metrics as $metric)
                        <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($groupTotals['diff_'.$metric] ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($groupTotals['diff_'.$metric] ?? 0)) }}</td>
                    @endforeach
                </tr>
                @include('reports.comparison.partials.growth-percent-row', [
                    'metrics' => $metrics,
                    'totals' => $groupTotals,
                ])
            @endforeach
            </tbody>
            @if ($totals !== [])
                <tfoot>
                <tr>
                    <th>Total</th>
                    <th></th>
                    @foreach ($metrics as $metric)
                        <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($totals['period1_'.$metric] ?? 0)) }}</th>
                    @endforeach
                    @foreach ($metrics as $metric)
                        <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($totals['period2_'.$metric] ?? 0)) }}</th>
                    @endforeach
                    @foreach ($metrics as $metric)
                        <th class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($totals['diff_'.$metric] ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($totals['diff_'.$metric] ?? 0)) }}</th>
                    @endforeach
                </tr>
                @include('reports.comparison.partials.growth-percent-row', [
                    'metrics' => $metrics,
                    'totals' => $totals,
                ])
                </tfoot>
            @endif
        </table>
    @elseif (!$errorMessage)
        <p class="report-empty">No item rows match your filters. Try widening the date range or clearing category exclusions.</p>
    @endif
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

