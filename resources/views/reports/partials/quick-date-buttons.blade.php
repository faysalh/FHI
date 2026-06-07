@php
    $presets = $presets ?? ['this-month', 'last-30', 'last-month'];
    $labels = [
        'this-month' => 'This month',
        'last-30' => 'Last 30 days',
        'last-month' => 'Last month',
        'this-month-vs-last-month' => 'This month vs last month',
        'last-30-vs-prior-30' => 'Last 30 vs prior 30',
    ];
@endphp
<div class="pie-quick-dates" aria-label="Quick date ranges">
    <span class="pie-quick-dates__label">Quick range</span>
    @foreach ($presets as $preset)
        @if (isset($labels[$preset]))
            <button type="button" class="pie-quick-date-btn" data-range="{{ $preset }}">{{ $labels[$preset] }}</button>
        @endif
    @endforeach
</div>
