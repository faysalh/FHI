@php
    use App\Support\PromotionsWeekdays;

    $fieldName = $fieldName ?? 'default_visit_days';
    $selected = $selected ?? [];
    $selectedMap = array_flip(array_map('intval', (array) $selected));
    $inputIdPrefix = $inputIdPrefix ?? $fieldName;
    $showHint = $hint ?? true;
@endphp
<div class="promo-weekdays" role="group" aria-label="Visit weekdays">
    @foreach (PromotionsWeekdays::allowedWeekdayNumbers() as $weekday)
        @php $checked = isset($selectedMap[$weekday]); @endphp
        <label class="promo-weekday">
            <input type="checkbox"
                   name="{{ $fieldName }}[]"
                   value="{{ $weekday }}"
                   id="{{ $inputIdPrefix }}_{{ $weekday }}"
                   @checked($checked)>
            <span>{{ PromotionsWeekdays::label($weekday) }}</span>
        </label>
    @endforeach
</div>
@if ($showHint)
<p class="muted" style="margin:6px 0 0;font-size:12px;">Fridays are excluded. Pick {{ \App\Support\PromotionsWeekdays::MIN_VISITS_PER_WEEK }}–{{ \App\Support\PromotionsWeekdays::MAX_VISITS_PER_WEEK }} typical days (used as a starting template when assigning clients).</p>
@endif
