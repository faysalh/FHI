@extends('reports.layouts.app')
@section('title', 'Dashboard')

@section('content')
<header class="page-header">
    <h1>Dashboard</h1>
</header>
<p class="lab-desc">Live KPI snapshot for a saved governorate and optional salesman filter. Metrics refresh when you change the toolbar; charts and category tables follow the same scope.</p>

@if ($governorateError || $dataError)
    <div class="alert alert--error" role="alert">
        @if ($governorateError)<div>{{ $governorateError }}</div>@endif
        @if ($dataError && $dataError !== $governorateError)<div>{{ $dataError }}</div>@endif
    </div>
@endif

<div class="lab-toolbar">
    <div>
        <label for="lab_governorate_id">Governorate</label>
        <select id="lab_governorate_id" @disabled($governorateOptions === [] || $initialMetrics === null)>
            @forelse ($governorateOptions as $opt)
                <option value="{{ $opt['id'] }}" @selected((int) $selectedGovernorateId === (int) $opt['id'])>{{ $opt['label'] }}</option>
            @empty
                <option value="">No saved governorates</option>
            @endforelse
        </select>
    </div>
    <div>
        <label for="lab_salesman_id">Salesman filter</label>
        <select id="lab_salesman_id" @disabled($initialMetrics === null)>
            <option value="">All salesmen</option>
            @foreach ($salesmanOptions as $opt)
                <option value="{{ $opt['id'] }}">{{ $opt['name'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="muted" style="font-size:12px;padding-bottom:6px;">
        <span id="lab_governorate_label">{{ $governorateLabel }}</span> · {{ $asOfLabel }}
    </div>
</div>

<div id="lab_root" class="lab-grid @if($initialMetrics === null) lab-loading @endif">

    <section class="lab-card lab-span-6">
        <h2 class="lab-card__title">Invoices</h2>
        <div class="lab-kpi-rows">
            <div class="lab-kpi-row">
                <span class="dash-card__eyebrow">Today</span>
                <div class="lab-kpis">
                    <div>
                        <p class="lab-kpi__label">Count</p>
                        <p class="lab-kpi__value" data-m="inv_count">—</p>
                        <p class="lab-kpi__delta lab-kpi__delta--flat" data-m="inv_count_delta"></p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Quantity</p>
                        <p class="lab-kpi__value" data-m="inv_qty">—</p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Amount</p>
                        <p class="lab-kpi__value" data-m="inv_amt">—</p>
                        <p class="lab-kpi__delta lab-kpi__delta--flat" data-m="inv_amt_delta"></p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Avg / invoice</p>
                        <p class="lab-kpi__value" data-m="inv_avg">—</p>
                    </div>
                </div>
            </div>
            <div class="lab-kpi-row">
                <span class="dash-card__eyebrow">This month</span>
                <div class="lab-kpis">
                    <div>
                        <p class="lab-kpi__label">Count</p>
                        <p class="lab-kpi__value" data-m="month_inv_count">—</p>
                        <p class="lab-kpi__delta lab-kpi__delta--flat" data-m="month_inv_count_delta"></p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Quantity</p>
                        <p class="lab-kpi__value" data-m="month_inv_qty">—</p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Amount</p>
                        <p class="lab-kpi__value" data-m="month_inv_amt">—</p>
                        <p class="lab-kpi__delta lab-kpi__delta--flat" data-m="month_inv_amt_delta"></p>
                    </div>
                    <div>
                        <p class="lab-kpi__label">Avg / invoice</p>
                        <p class="lab-kpi__value" data-m="month_inv_avg">—</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="lab-card lab-span-6">
        <h2 class="lab-card__title"><span class="lab-tag">Today</span> Sales by salesman</h2>
        <p class="lab-desc">
            Pie chart of today's sales amount (document lines, pre-discount unit × qty like the Sales report) split by salesman.
            Helps see who is carrying today's volume at a glance.
        </p>
        <div class="lab-chart">
            <canvas id="lab_pie_today"></canvas>
            <div id="lab_pie_today_empty" class="lab-chart-empty" hidden>No sales today.</div>
        </div>
    </section>

    <section class="lab-card lab-span-8 lab-card--pace">
        <h2 class="lab-card__title"><span class="lab-tag">{{ $monthLabel }}</span> Month pace</h2>
        <div class="lab-kpis">
            <div>
                <p class="lab-kpi__label">MTD amount</p>
                <p class="lab-kpi__value" data-m="mtd_amt">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">MTD quantity</p>
                <p class="lab-kpi__value" data-m="mtd_qty">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">MTD weight</p>
                <p class="lab-kpi__value" data-m="mtd_wgt">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">Projected month</p>
                <p class="lab-kpi__value" data-m="proj_amt">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">Run rate / day</p>
                <p class="lab-kpi__value" data-m="run_rate">—</p>
            </div>
        </div>
        <div class="lab-progress">
            <div class="lab-progress__bar"><div class="lab-progress__fill" id="lab_month_progress" style="width:0%"></div></div>
            <div class="lab-progress__labels">
                <span id="lab_month_progress_label">Month progress</span>
                <span id="lab_working_days_label"></span>
            </div>
        </div>
        <div class="lab-pace-compare">
            <div class="lab-pace-compare__head">
                <span class="lab-kpi__label">Last month (<span data-m="pace_prev_label">—</span>)</span>
                <span class="lab-kpi__label">MTD change</span>
            </div>
            <div class="lab-pace-compare__row">
                <span>Amount</span>
                <span class="num" data-m="pace_prev_amt">—</span>
                <span class="lab-kpi__delta lab-kpi__delta--flat" data-m="pace_amt_delta"></span>
            </div>
            <div class="lab-pace-compare__row">
                <span>Quantity</span>
                <span class="num" data-m="pace_prev_qty">—</span>
                <span class="lab-kpi__delta lab-kpi__delta--flat" data-m="pace_qty_delta"></span>
            </div>
            <div class="lab-pace-compare__row">
                <span>Weight</span>
                <span class="num" data-m="pace_prev_wgt">—</span>
                <span class="lab-kpi__delta lab-kpi__delta--flat" data-m="pace_wgt_delta"></span>
            </div>
        </div>
        <div class="lab-pace-foot">
            <span>Business days left: <strong data-m="pace_days_left">—</strong></span>
            <span>Projection vs last month: <strong data-m="pace_proj_vs_prev">—</strong></span>
        </div>
    </section>

    <section class="lab-card lab-span-4">
        <h2 class="lab-card__title"><span class="lab-tag">Month</span> By salesman</h2>
        <p class="lab-desc">
            Horizontal view of who sold the most this month (amount). Complements the today pie when you need the full-month picture.
        </p>
        <div class="lab-chart">
            <canvas id="lab_bar_month"></canvas>
            <div id="lab_bar_month_empty" class="lab-chart-empty" hidden>No MTD sales.</div>
        </div>
    </section>

    <section class="lab-card lab-span-12">
        <h2 class="lab-card__title"><span class="lab-tag">Average</span> Per working day</h2>
        <p class="lab-desc">
            Month totals divided by business days elapsed (Fridays and Eid holidays excluded, same as Month pace).
            Below: weight and amount broken down by item category — MTD and average per business day per category.
        </p>
        <div class="lab-kpis" style="margin-bottom:16px;">
            <div>
                <p class="lab-kpi__label">Avg qty / day</p>
                <p class="lab-kpi__value" data-m="avg_qty">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">Avg amount / day</p>
                <p class="lab-kpi__value" data-m="avg_amt">—</p>
            </div>
            <div>
                <p class="lab-kpi__label">Avg weight / day</p>
                <p class="lab-kpi__value" data-m="avg_wgt">—</p>
            </div>
        </div>
        <div class="lab-split">
            <div>
                <p class="lab-kpi__label" style="margin-bottom:6px;">Weight by category</p>
                <table class="lab-table">
                    <thead><tr><th>Category</th><th class="num">MTD</th><th class="num">Avg/day</th></tr></thead>
                    <tbody id="lab_weight_cats"><tr><td colspan="3" class="muted">—</td></tr></tbody>
                </table>
            </div>
            <div>
                <p class="lab-kpi__label" style="margin-bottom:6px;">Amount by category</p>
                <table class="lab-table">
                    <thead><tr><th>Category</th><th class="num">MTD</th><th class="num">Avg/day</th></tr></thead>
                    <tbody id="lab_amount_cats"><tr><td colspan="3" class="muted">—</td></tr></tbody>
                </table>
            </div>
        </div>
    </section>

</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    var metricsUrl = @json($metricsUrl);
    var initial = @json($initialMetrics);
    var root = document.getElementById('lab_root');
    var governorateSelect = document.getElementById('lab_governorate_id');
    var governorateLabel = document.getElementById('lab_governorate_label');
    var salesmanSelect = document.getElementById('lab_salesman_id');
    var pieToday = null, barMonth = null;
    var colors = ['#6366f1','#14b8a6','#f59e0b','#ec4899','#8b5cf6','#06b6d4','#84cc16','#f97316'];

    function fmt(n) {
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(Number(n) || 0);
    }
    function esc(s) { return String(s || '').replace(/</g, '&lt;'); }
    function setText(sel, t) {
        var el = document.querySelector('[data-m="' + sel + '"]');
        if (el) el.textContent = t;
    }
    var compareLabel = 'prior sales day';
    var monthCompareLabel = 'prior month';
    function setDelta(sel, delta, pct, vsLabel) {
        var el = document.querySelector('[data-m="' + sel + '"]');
        if (!el) return;
        var label = vsLabel !== undefined ? vsLabel : compareLabel;
        var vs = label ? (' vs ' + label) : '';
        if (delta === 0 && (pct === null || pct === undefined)) {
            el.textContent = label ? ('Same as ' + label) : 'No comparison available';
            el.className = 'lab-kpi__delta lab-kpi__delta--flat';
            return;
        }
        var sign = delta > 0 ? '+' : '';
        var pctStr = pct !== null && pct !== undefined ? ' (' + sign + pct + '%)' : '';
        el.textContent = sign + fmt(delta) + vs + pctStr;
        el.className = 'lab-kpi__delta ' + (delta > 0 ? 'lab-kpi__delta--up' : (delta < 0 ? 'lab-kpi__delta--down' : 'lab-kpi__delta--flat'));
    }
    function setPaceDelta(sel, delta, pct) {
        var el = document.querySelector('[data-m="' + sel + '"]');
        if (!el) return;
        if (delta === 0 && (pct === null || pct === undefined)) {
            el.textContent = 'Same as last month full total';
            el.className = 'lab-kpi__delta lab-kpi__delta--flat';
            return;
        }
        var sign = delta > 0 ? '+' : '';
        var pctStr = pct !== null && pct !== undefined ? ' (' + sign + pct + '%)' : '';
        el.textContent = sign + fmt(delta) + ' vs last month' + pctStr;
        el.className = 'lab-kpi__delta ' + (delta > 0 ? 'lab-kpi__delta--up' : (delta < 0 ? 'lab-kpi__delta--down' : 'lab-kpi__delta--flat'));
    }
    function renderTable(id, rows, valKey, avgKey) {
        var body = document.getElementById(id);
        if (!body) return;
        if (!rows || !rows.length) {
            body.innerHTML = '<tr><td colspan="3" class="muted">No data</td></tr>';
            return;
        }
        var sumVal = 0;
        var sumAvg = 0;
        var html = rows.map(function (r) {
            var val = Number(r[valKey]) || 0;
            var avg = Number(r[avgKey]) || 0;
            sumVal += val;
            sumAvg += avg;
            return '<tr><td>' + esc(r.category_name) + '</td><td class="num">' + fmt(val) +
                '</td><td class="num">' + fmt(avg) + '</td></tr>';
        }).join('');
        html += '<tr class="lab-table__sum"><td>Total</td><td class="num">' + fmt(sumVal) +
            '</td><td class="num">' + fmt(sumAvg) + '</td></tr>';
        body.innerHTML = html;
    }
    function upsertChart(ref, canvasId, emptyId, type, labels, data, horizontal) {
        var canvas = document.getElementById(canvasId);
        var empty = document.getElementById(emptyId);
        if (!canvas || typeof Chart === 'undefined') return ref;
        var has = data.some(function (v) { return v > 0; });
        if (empty) empty.hidden = has;
        canvas.hidden = !has;
        if (!has) {
            if (ref) { ref.destroy(); return null; }
            return null;
        }
        var bg = labels.map(function (_, i) { return colors[i % colors.length]; });
        var opts = {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: horizontal ? 'y' : 'x',
            plugins: {
                legend: { display: type === 'pie', position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            var v = Number(ctx.parsed[horizontal ? 'x' : 'y'] ?? ctx.parsed) || 0;
                            return (ctx.label || '') + ': ' + fmt(v);
                        }
                    }
                }
            },
            scales: type === 'bar' ? {
                x: { ticks: { callback: function (v) { return fmt(v); } } }
            } : {}
        };
        if (ref) {
            ref.data.labels = labels;
            ref.data.datasets[0].data = data;
            ref.data.datasets[0].backgroundColor = bg;
            ref.update();
            return ref;
        }
        return new Chart(canvas, {
            type: type,
            data: { labels: labels, datasets: [{ data: data, backgroundColor: bg, borderWidth: 1, borderColor: '#fff' }] },
            options: opts
        });
    }
    function metricsQueryParams() {
        var params = [];
        var gov = governorateSelect ? governorateSelect.value : '';
        var sales = salesmanSelect ? salesmanSelect.value : '';
        if (gov) params.push('saved_governorate_id=' + encodeURIComponent(gov));
        if (sales) params.push('salesman_id=' + encodeURIComponent(sales));
        return params;
    }
    function syncUrl() {
        var params = metricsQueryParams();
        var qs = params.length ? ('?' + params.join('&')) : '';
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', window.location.pathname + qs);
        }
    }
    function apply(data) {
        if (!data) return;
        if (data.governorate_label && governorateLabel) {
            governorateLabel.textContent = data.governorate_label;
        }
        var inv = data.todayInvoices || {};
        var cmp = data.comparisons || {};
        var monthInv = data.monthInvoices || {};
        var monthCmp = data.monthComparisons || {};
        compareLabel = cmp.compare_label || (data.comparisonDay && data.comparisonDay.label) || null;
        monthCompareLabel = monthCmp.compare_label || 'prior month';
        var month = data.monthSales || {};
        var avg = data.dailyAvg || {};
        var meta = data.meta || {};
        var pace = data.pacing || {};

        setText('inv_count', fmt(inv.invoice_count));
        setText('inv_qty', fmt(inv.quantity_total));
        setText('inv_amt', fmt(inv.invoice_amount));
        setText('inv_avg', fmt(cmp.avg_per_invoice_today));
        setDelta('inv_count_delta', cmp.invoice_count_delta || 0, null);
        setDelta('inv_amt_delta', cmp.invoice_amount_delta || 0, cmp.invoice_amount_pct);

        setText('month_inv_count', fmt(monthInv.invoice_count));
        setText('month_inv_qty', fmt(monthInv.quantity_total));
        setText('month_inv_amt', fmt(monthInv.invoice_amount));
        setText('month_inv_avg', fmt(monthCmp.avg_per_invoice_month));
        setDelta('month_inv_count_delta', monthCmp.invoice_count_delta || 0, null, monthCompareLabel);
        setDelta('month_inv_amt_delta', monthCmp.invoice_amount_delta || 0, monthCmp.invoice_amount_pct, monthCompareLabel);

        setText('mtd_amt', fmt(month.amount));
        setText('mtd_qty', fmt(month.units_sold));
        setText('mtd_wgt', fmt(month.weight_total));
        setText('proj_amt', fmt(pace.projected_month_amount));
        setText('run_rate', fmt(pace.daily_run_rate_amount));

        var salesCmp = data.monthSalesComparisons || {};
        var prevSales = salesCmp.previous || {};
        setText('pace_prev_label', salesCmp.compare_label || '—');
        setText('pace_prev_amt', fmt(prevSales.amount));
        setText('pace_prev_qty', fmt(prevSales.units_sold));
        setText('pace_prev_wgt', fmt(prevSales.weight_total));
        setPaceDelta('pace_amt_delta', salesCmp.amount_delta || 0, salesCmp.amount_pct);
        setPaceDelta('pace_qty_delta', salesCmp.units_delta || 0, salesCmp.units_pct);
        setPaceDelta('pace_wgt_delta', salesCmp.weight_delta || 0, salesCmp.weight_pct);
        setText('pace_days_left', fmt(pace.remaining_working_days));
        var projVsPrev = salesCmp.projected_vs_previous_pct;
        setText('pace_proj_vs_prev', projVsPrev !== null && projVsPrev !== undefined
            ? ((projVsPrev > 0 ? '+' : '') + projVsPrev + '%')
            : '—');

        setText('avg_qty', fmt(avg.units));
        setText('avg_amt', fmt(avg.amount));
        setText('avg_wgt', fmt(avg.weight));

        var pct = meta.month_progress_pct || 0;
        var prog = document.getElementById('lab_month_progress');
        if (prog) prog.style.width = Math.min(100, pct) + '%';
        var pl = document.getElementById('lab_month_progress_label');
        if (pl) pl.textContent = 'Calendar month ' + pct + '% elapsed';
        var wl = document.getElementById('lab_working_days_label');
        if (wl) {
            var eidNote = (meta.eid_holidays_elapsed && meta.eid_holidays_elapsed.length)
                ? ' آ· ' + meta.eid_holidays_elapsed.length + ' Eid day(s) excluded'
                : '';
            wl.textContent = (meta.working_days_elapsed || '—') + ' / ' + (meta.working_days_in_month || '—') + ' business days' + eidNote;
        }

        renderTable('lab_weight_cats', data.weightByCategory, 'weight_total', 'weight_avg_daily');
        renderTable('lab_amount_cats', data.amountByCategory, 'amount_total', 'amount_avg_daily');

        var todayRows = data.salesBySalesman || [];
        pieToday = upsertChart(pieToday, 'lab_pie_today', 'lab_pie_today_empty', 'pie',
            todayRows.map(function (r) { return r.salesman_name; }),
            todayRows.map(function (r) { return r.amount; }), false);

        var monthRows = data.monthSalesBySalesman || [];
        barMonth = upsertChart(barMonth, 'lab_bar_month', 'lab_bar_month_empty', 'bar',
            monthRows.map(function (r) { return r.salesman_name; }),
            monthRows.map(function (r) { return r.amount; }), true);
    }
    function load() {
        var params = metricsQueryParams();
        var url = metricsUrl + (params.length ? ('?' + params.join('&')) : '');
        syncUrl();
        if (root) root.classList.add('lab-loading');
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) { if (res.ok && res.body.ok) apply(res.body); })
            .finally(function () { if (root) root.classList.remove('lab-loading'); });
    }
    if (initial) apply(initial);
    if (governorateSelect) governorateSelect.addEventListener('change', load);
    if (salesmanSelect) salesmanSelect.addEventListener('change', load);
})();
</script>
@endpush
