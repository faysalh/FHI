@if (! empty($errorMessage))
    <p class="error">{{ $errorMessage }}</p>
@endif

@php
    $months = is_array($months ?? null) ? $months : [];
    $showChange = count($months) >= 2;
    $changeLabel = (string) ($changeLabel ?? 'Change');
    $pdf = (bool) ($pdf ?? false);
    $text = static function (string $value) use ($pdf): string {
        return $pdf ? \App\Support\ArabicPdfText::glyphs($value) : $value;
    };
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
    $formatGrowth = static function (?float $growth): string {
        if ($growth === null) {
            return '—';
        }
        $formatted = display_number($growth);

        return ($growth > 0.0 ? '+' : '').$formatted.'%';
    };
    $groupedRows = is_array($groupedRows ?? null) ? $groupedRows : [];
    $totals = is_array($totals ?? null) ? $totals : [];
    $columnCount = 2 + (count($metrics) * count($months)) + ($showChange ? count($metrics) : 0);
@endphp

@if (!empty($rows))
    <table>
        <thead>
        <tr>
            <th rowspan="2">{{ $text('Category') }}</th>
            <th rowspan="2">{{ $text('Item') }}</th>
            @foreach ($months as $month)
                <th colspan="{{ count($metrics) }}" class="group-head">
                    {{ $text((string) $month['label']) }}
                    <br><span class="muted">{{ $text((string) $month['span']) }}</span>
                </th>
            @endforeach
            @if ($showChange)
                <th colspan="{{ count($metrics) }}" class="group-head">{{ $text($changeLabel) }}</th>
            @endif
        </tr>
        <tr>
            @foreach ($months as $month)
                @foreach ($metrics as $metric)
                    <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $text($metricLabel((string) $metric)) }}</th>
                @endforeach
            @endforeach
            @if ($showChange)
                @foreach ($metrics as $metric)
                    <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $text($metricLabel((string) $metric)) }}</th>
                @endforeach
            @endif
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
                    <td>{{ $text((string) ($row->category_name ?? '')) }}</td>
                    <td>{{ $text((string) ($row->item_name ?? '')) }}</td>
                    @foreach ($months as $month)
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($row->months[$month['key']][$metric] ?? 0)) }}</td>
                        @endforeach
                    @endforeach
                    @if ($showChange)
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($row->{'diff_'.$metric} ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($row->{'diff_'.$metric} ?? 0)) }}</td>
                        @endforeach
                    @endif
                </tr>
            @endforeach
            <tr style="background:#f8fafc;font-weight:700;">
                <td>{{ $text('Subtotal') }}</td>
                <td>{{ $text($groupCategory) }}</td>
                @foreach ($months as $month)
                    @foreach ($metrics as $metric)
                        <td class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($groupTotals['months'][$month['key']][$metric] ?? 0)) }}</td>
                    @endforeach
                @endforeach
                @if ($showChange)
                    @foreach ($metrics as $metric)
                        <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($groupTotals['diff_'.$metric] ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($groupTotals['diff_'.$metric] ?? 0)) }}</td>
                    @endforeach
                @endif
            </tr>
            @if ($showChange)
                <tr class="growth-row">
                    <td>{{ $text('Growth %') }}</td>
                    <td></td>
                    @foreach ($months as $month)
                        @foreach ($metrics as $metric)
                            <td class="num {{ $loop->first ? 'sep-left' : '' }}"></td>
                        @endforeach
                    @endforeach
                    @foreach ($metrics as $metric)
                        @php $growth = $groupTotals['growth_'.$metric] ?? null; @endphp
                        <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass(is_float($growth) ? $growth : 0.0) }}">{{ $formatGrowth(is_float($growth) ? $growth : null) }}</td>
                    @endforeach
                </tr>
            @endif
        @endforeach
        </tbody>
        @if ($totals !== [])
            <tfoot>
            <tr>
                <th>{{ $text('Total') }}</th>
                <th></th>
                @foreach ($months as $month)
                    @foreach ($metrics as $metric)
                        <th class="num {{ $loop->first ? 'sep-left' : '' }}">{{ $metricValue((string) $metric, (float) ($totals['months'][$month['key']][$metric] ?? 0)) }}</th>
                    @endforeach
                @endforeach
                @if ($showChange)
                    @foreach ($metrics as $metric)
                        <th class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass((float) ($totals['diff_'.$metric] ?? 0)) }}">{{ $metricValue((string) $metric, (float) ($totals['diff_'.$metric] ?? 0)) }}</th>
                    @endforeach
                @endif
            </tr>
            @if ($showChange)
                <tr class="growth-row">
                    <th>{{ $text('Growth %') }}</th>
                    <th></th>
                    @foreach ($months as $month)
                        @foreach ($metrics as $metric)
                            <th class="num {{ $loop->first ? 'sep-left' : '' }}"></th>
                        @endforeach
                    @endforeach
                    @foreach ($metrics as $metric)
                        @php $growth = $totals['growth_'.$metric] ?? null; @endphp
                        <th class="num {{ $loop->first ? 'sep-left' : '' }} {{ $diffClass(is_float($growth) ? $growth : 0.0) }}">{{ $formatGrowth(is_float($growth) ? $growth : null) }}</th>
                    @endforeach
                </tr>
            @endif
            </tfoot>
        @endif
    </table>
@elseif (empty($errorMessage) && ! $pdf)
    <p class="report-empty">No item rows match your filters. Try widening the date range or clearing category exclusions.</p>
@elseif ($pdf)
    <table>
        <tr>
            <td colspan="{{ max(1, $columnCount) }}">{{ $text('No rows match the selected filters.') }}</td>
        </tr>
    </table>
@endif
