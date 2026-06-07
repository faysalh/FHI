@php
    $formatGrowthPercent = static function (?float $growth): string {
        if ($growth === null) {
            return '—';
        }
        $formatted = display_number($growth);

        return ($growth > 0.0 ? '+' : '').$formatted.'%';
    };
    $growthClass = static function (?float $growth): string {
        if ($growth === null) {
            return 'neu';
        }
        if ($growth > 0.0) {
            return 'pos';
        }
        if ($growth < 0.0) {
            return 'neg';
        }

        return 'neu';
    };
    $labelCell = ($useGlyphs ?? false)
        ? \App\Support\ArabicPdfText::glyphs($label ?? 'Growth %')
        : ($label ?? 'Growth %');
@endphp
<tr class="growth-row">
    <td>{{ $labelCell }}</td>
    <td></td>
    @foreach ($metrics as $metric)
        <td class="num {{ $loop->first ? 'sep-left' : '' }}"></td>
    @endforeach
    @foreach ($metrics as $metric)
        <td class="num {{ $loop->first ? 'sep-left' : '' }}"></td>
    @endforeach
    @foreach ($metrics as $metric)
        @php $growth = $totals['growth_'.$metric] ?? null; @endphp
        <td class="num {{ $loop->first ? 'sep-left' : '' }} {{ $growthClass(is_float($growth) ? $growth : null) }}">{{ $formatGrowthPercent(is_float($growth) ? $growth : null) }}</td>
    @endforeach
</tr>
