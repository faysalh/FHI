@if (! empty($errorMessage))
    <p class="error">{{ $errorMessage }}</p>
@endif

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
@elseif (empty($errorMessage))
    <p class="report-empty">No item rows match your filters. Try widening the date range or clearing category exclusions.</p>
@endif
