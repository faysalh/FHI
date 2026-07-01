@php
    use App\Support\PromotionsWeekdays;

    $fieldName = $fieldName ?? 'visit_days';
    $selected = $selected ?? [];
    $inputIdPrefix = $inputIdPrefix ?? $fieldName;
    $dailyVisits = (bool) ($dailyVisits ?? false);
    if (! $dailyVisits && $selected !== []) {
        $dailyVisits = PromotionsWeekdays::isDailyVisitSchedule((array) $selected);
    }
    $dailyVisits = (bool) old('daily_visits', $dailyVisits);
@endphp
<div class="promo-visit-days-fields promo-visit-days-form">
    <span class="field-label">Visit days <span class="muted">(required)</span></span>
    <p class="muted" style="margin:4px 0 8px;font-size:12px;">
        Select {{ PromotionsWeekdays::MIN_VISITS_PER_WEEK }}–{{ PromotionsWeekdays::MAX_VISITS_PER_WEEK }} days per week, or enable daily visits for all working days (Saturday–Thursday).
    </p>
    @include('reports.promotions.partials.weekday-checkboxes', [
        'fieldName' => $fieldName,
        'selected' => $selected,
        'inputIdPrefix' => $inputIdPrefix,
        'hint' => false,
    ])
    <label class="promo-checkbox-inline" style="margin-top:8px;">
        <input type="checkbox"
               name="daily_visits"
               value="1"
               class="promo-daily-visits-toggle"
               @checked($dailyVisits)>
        <span>Daily visits (all working days)</span>
    </label>
</div>
