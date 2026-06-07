<div>
    @include('reports.partials.quick-date-buttons')
</div>
<div>
    <label for="sales_date_from">Sales period from</label>
    <input type="date" id="sales_date_from" name="sales_date_from" value="{{ $filters['sales_date_from'] }}">
</div>
<div>
    <label for="sales_date_to">Sales period to</label>
    <input type="date" id="sales_date_to" name="sales_date_to" value="{{ $filters['sales_date_to'] }}">
</div>
<div>
    <label for="working_days_display">Working days (Fri excluded)</label>
    <output id="working_days_display" class="working-days-display" for="sales_date_from sales_date_to">{{ $wdUi }}</output>
    <p class="muted" style="margin:4px 0 0;font-size:12px;">Calculated automatically from the sales period.</p>
</div>
