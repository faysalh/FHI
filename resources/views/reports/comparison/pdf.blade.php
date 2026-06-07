<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Comparison report</title>
    <style>@include('reports.partials.pdf-styles')</style>
</head>
<body>
@php use App\Support\ArabicPdfText as Ar; @endphp
@include('reports.partials.pdf-branding-header')
@include('reports.partials.pdf-title-block', [
    'title' => 'Comparison report',
    'meta' => [
        'Period 1: '.$filters['date_from_1'].' to '.$filters['date_to_1'],
        'Period 2: '.$filters['date_from_2'].' to '.$filters['date_to_2'],
        'City: '.(($filters['city'] ?? '') !== '' ? (string) $filters['city'] : 'All'),
        'Salesman: '.((string) ($salesmanLabel ?? 'All')),
        'Governorate: '.((string) ($governorateLabel ?? 'None')),
        'Exclude category: '.(($filters['exclude_category'] ?? '') !== '' ? (string) $filters['exclude_category'] : 'None'),
    ],
])

@php
    $diffClass = static function (float $value): string {
        if ($value > 0) return 'pos';
        if ($value < 0) return 'neg';
        return 'neu';
    };
    $metrics = $filters['metrics'] ?? ['quantity', 'amount', 'weight'];
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
        <th>{{ Ar::glyphs('Category') }}</th>
        <th>{{ Ar::glyphs('Item') }}</th>
        @foreach ($metrics as $metric)
            <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ Ar::glyphs('P1 '.$metricLabel((string) $metric)) }}</th>
        @endforeach
        @foreach ($metrics as $metric)
            <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ Ar::glyphs('P2 '.$metricLabel((string) $metric)) }}</th>
        @endforeach
        @foreach ($metrics as $metric)
            <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ Ar::glyphs('Diff '.$metricLabel((string) $metric)) }}</th>
        @endforeach
    </tr>
    </thead>
    <tbody>
    @forelse ($groupedRows as $group)
        @php
            $groupRows = is_array($group['rows'] ?? null) ? $group['rows'] : [];
            $groupTotals = is_array($group['totals'] ?? null) ? $group['totals'] : [];
            $groupCategory = (string) ($group['category'] ?? '');
        @endphp
        @foreach ($groupRows as $row)
            <tr>
                <td>{{ Ar::glyphs((string) ($row->category_name ?? '')) }}</td>
                <td>{{ Ar::glyphs((string) ($row->item_name ?? '')) }}</td>
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
        <tr>
            <th>{{ Ar::glyphs('Subtotal') }}</th>
            <th>{{ Ar::glyphs($groupCategory) }}</th>
            @foreach ($metrics as $metric)
                <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($groupTotals['period1_'.$metric] ?? 0)) }}</th>
            @endforeach
            @foreach ($metrics as $metric)
                <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($groupTotals['period2_'.$metric] ?? 0)) }}</th>
            @endforeach
            @foreach ($metrics as $metric)
                <th class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($groupTotals['diff_'.$metric] ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($groupTotals['diff_'.$metric] ?? 0)) }}</th>
            @endforeach
        </tr>
        @include('reports.comparison.partials.growth-percent-row', [
            'metrics' => $metrics,
            'totals' => $groupTotals,
            'useGlyphs' => true,
        ])
    @empty
        <tr>
            <td colspan="{{ 2 + (count($metrics) * 3) }}">{{ Ar::glyphs('No rows match the selected filters.') }}</td>
        </tr>
    @endforelse
    </tbody>
    @if ($totals !== [])
        <tfoot>
        <tr>
            <th>{{ Ar::glyphs('Total') }}</th>
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
            'useGlyphs' => true,
        ])
        </tfoot>
    @endif
</table>
@include('reports.partials.pdf-footer')
</body>
</html>
