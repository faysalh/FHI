@extends('reports.layouts.app')
@section('title', 'Leave')

@section('content')
    <header class="page-header">
        <h1>Leave</h1>
        <p class="hint">Hourly leave is one date and a time range. Sick, unpaid, annual, and other leave use a date range. Fridays and holidays from the Holidays page are not counted.</p>
    </header>

    @include('reports.hr.partials.subnav')

    @if (session('status'))
        <div class="alert alert--success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert--error" role="alert">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert--error" role="alert">{{ $errors->first() }}</div>
    @endif

    @if ($canFile)
        <h2>New request</h2>
        <form method="POST" action="{{ route('reports.hr.leave.store') }}">
            @csrf
            <div class="filters-grid">
                <div>
                    <label for="employee_id">Employee</label>
                    <select id="employee_id" name="employee_id" required>
                        <option value="">Choose</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}" @selected((string) old('employee_id') === (string) $employee->id)>{{ $employee->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="leave_type">Type</label>
                    <select id="leave_type" name="leave_type" required>
                        @foreach (['hourly' => 'Hourly', 'sick' => 'Sick', 'unpaid' => 'Unpaid', 'annual' => 'Annual', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('leave_type', 'annual') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="hr-leave-when span-full">
                    <div id="day-range">
                        <div class="filters-grid">
                            <div>
                                <label for="start_date">From</label>
                                <input id="start_date" type="date" name="start_date" value="{{ old('start_date') }}">
                            </div>
                            <div>
                                <label for="end_date">To</label>
                                <input id="end_date" type="date" name="end_date" value="{{ old('end_date') }}">
                            </div>
                        </div>
                    </div>
                    <div id="hour-range" hidden>
                        <div class="filters-grid">
                            <div>
                                <label for="hour_date">Date</label>
                                <input id="hour_date" type="date" value="{{ old('start_date') }}">
                            </div>
                            <div>
                                <label for="start_time">From</label>
                                <input id="start_time" type="time" name="start_time" value="{{ old('start_time') }}">
                            </div>
                            <div>
                                <label for="end_time">To</label>
                                <input id="end_time" type="time" name="end_time" value="{{ old('end_time') }}">
                            </div>
                        </div>
                    </div>
                    <aside class="hr-leave-count" aria-live="polite">
                        <span class="muted" id="leave-count-caption">Working days</span>
                        <strong id="leave-count-value">—</strong>
                        <span class="muted" id="leave-count-note">Fridays and holidays are not counted.</span>
                    </aside>
                </div>
                <div>
                    <label for="reason">Reason</label>
                    <input id="reason" name="reason" value="{{ old('reason') }}" required maxlength="2000">
                </div>
            </div>
            <div class="btn-group" style="margin:12px 0;">
                <button class="btn" type="submit">File request</button>
            </div>
        </form>
    @endif

    <form id="hr-leave-filter-form" method="GET" action="{{ route('reports.hr.leave.index') }}">
        <div class="filters-grid filters-grid--compact">
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    @foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="btn-group" style="margin:12px 0;">
            <button class="btn" type="submit">Show</button>
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.hr.leave.export.pdf') }}">Export PDF</a>
            <a href="#" class="btn btn--secondary report-export-link" data-export-base="{{ route('reports.hr.leave.export.csv') }}">Export CSV</a>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>When</th>
                <th>Length</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $requestRow)
                <tr>
                    <td>{{ $requestRow->employee_name }}</td>
                    <td>{{ $requestRow->type_label }}</td>
                    <td>{{ $requestRow->span_label }}</td>
                    <td>{{ $requestRow->duration_label }}</td>
                    <td>{{ $requestRow->status_label }}</td>
                    <td>
                        <a href="{{ route('reports.hr.leave.print', $requestRow->id) }}">Print</a>
                        <a href="{{ route('reports.hr.leave.pdf', $requestRow->id) }}">PDF</a>
                        @if ($canApprove && $requestRow->status === 'pending')
                            <form method="POST" action="{{ route('reports.hr.leave.decide', $requestRow->id) }}" style="margin-top:8px;">
                                @csrf
                                <input type="hidden" name="filter_status" value="{{ $status }}">
                                <label class="muted" for="decision-{{ $requestRow->id }}">Decision</label>
                                <select id="decision-{{ $requestRow->id }}" name="status" required>
                                    <option value="approved">Approve</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <input name="decision_note" maxlength="1000" placeholder="Note">
                                <button class="btn" type="submit">Save decision</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No leave requests.</td></tr>
            @endforelse
        </tbody>
    </table>

    <style>
        .hr-leave-when { display: grid; grid-template-columns: minmax(0, 1fr) 220px; gap: 16px; align-items: start; }
        .hr-leave-count {
            border: 1px solid #c7d2fe; border-radius: 12px;
            padding: 12px 14px; background: #eef2ff;
        }
        @media (max-width: 600px) {
            .hr-leave-when { grid-template-columns: 1fr; }
        }
        .hr-leave-count strong { display: block; font-size: 28px; line-height: 1.2; margin: 4px 0; }
    </style>
    <script>
    (function () {
        var holidays = @json($holidayDates ?? []);
        var holidaySet = {};
        holidays.forEach(function (day) { holidaySet[day] = true; });
        var typeInput = document.getElementById('leave_type');
        var dayRange = document.getElementById('day-range');
        var hourRange = document.getElementById('hour-range');
        var startDate = document.getElementById('start_date');
        var endDate = document.getElementById('end_date');
        var hourDate = document.getElementById('hour_date');
        var startTime = document.getElementById('start_time');
        var endTime = document.getElementById('end_time');
        var caption = document.getElementById('leave-count-caption');
        var value = document.getElementById('leave-count-value');
        var note = document.getElementById('leave-count-note');

        function weekday(iso) {
            var parts = String(iso || '').split('-');
            if (parts.length !== 3) return null;
            return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).getDay();
        }

        function eachDate(from, to, visit) {
            var start = new Date(from + 'T00:00:00');
            var end = new Date(to + 'T00:00:00');
            if (isNaN(start.getTime()) || isNaN(end.getTime()) || start > end) return;
            var cursor = start;
            while (cursor <= end) {
                var iso = cursor.getFullYear() + '-' + String(cursor.getMonth() + 1).padStart(2, '0') + '-' + String(cursor.getDate()).padStart(2, '0');
                visit(iso);
                cursor.setDate(cursor.getDate() + 1);
            }
        }

        function paint() {
            var hourly = typeInput && typeInput.value === 'hourly';
            if (dayRange) dayRange.hidden = hourly;
            if (hourRange) hourRange.hidden = !hourly;
            if (startDate) startDate.required = !hourly;
            if (endDate) endDate.required = !hourly;
            if (hourDate) hourDate.required = hourly;
            if (startTime) startTime.required = hourly;
            if (endTime) endTime.required = hourly;
            if (hourly && hourDate && startDate) startDate.value = hourDate.value;
            if (!value) return;
            if (hourly) {
                caption.textContent = 'Hours';
                var from = startTime && startTime.value ? startTime.value : '';
                var to = endTime && endTime.value ? endTime.value : '';
                if (!hourDate || !hourDate.value || !from || !to || to <= from) {
                    value.textContent = '—';
                    note.textContent = 'Choose one date and a later end time.';
                    return;
                }
                var startMin = Number(from.slice(0, 2)) * 60 + Number(from.slice(3, 5));
                var endMin = Number(to.slice(0, 2)) * 60 + Number(to.slice(3, 5));
                var minutes = endMin - startMin;
                var hours = Math.floor(minutes / 60);
                var rest = minutes % 60;
                value.textContent = rest === 0
                    ? (hours + (hours === 1 ? ' hour' : ' hours'))
                    : (hours === 0 ? (rest + ' minutes') : (hours + (hours === 1 ? ' hour ' : ' hours ') + rest + ' minutes'));
                note.textContent = 'This request is for that time on one date.';
                return;
            }
            caption.textContent = 'Working days';
            var rangeFrom = startDate ? startDate.value : '';
            var rangeTo = endDate ? endDate.value : '';
            if (!rangeFrom || !rangeTo || rangeTo < rangeFrom) {
                value.textContent = '—';
                note.textContent = 'Fridays and holidays are not counted.';
                return;
            }
            var counted = 0;
            var skippedFriday = 0;
            var skippedHoliday = 0;
            eachDate(rangeFrom, rangeTo, function (iso) {
                if (holidaySet[iso]) {
                    skippedHoliday += 1;
                    return;
                }
                if (weekday(iso) === 5) {
                    skippedFriday += 1;
                    return;
                }
                counted += 1;
            });
            value.textContent = counted + (counted === 1 ? ' day' : ' days');
            var skipped = [];
            if (skippedFriday) skipped.push(skippedFriday + (skippedFriday === 1 ? ' Friday' : ' Fridays'));
            if (skippedHoliday) skipped.push(skippedHoliday + (skippedHoliday === 1 ? ' holiday' : ' holidays'));
            note.textContent = skipped.length
                ? ('Not counted: ' + skipped.join(' and ') + '.')
                : 'Fridays and holidays are not counted.';
        }

        [typeInput, startDate, endDate, hourDate, startTime, endTime].forEach(function (input) {
            if (input) input.addEventListener('change', paint);
            if (input) input.addEventListener('input', paint);
        });
        if (startDate && startDate.form) {
            startDate.form.addEventListener('submit', function () {
                if (typeInput && typeInput.value === 'hourly' && hourDate) {
                    startDate.value = hourDate.value;
                }
            });
        }
        paint();

        document.querySelectorAll('.report-export-link').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                var form = document.getElementById('hr-leave-filter-form');
                if (!form) return;
                var params = new URLSearchParams(new FormData(form));
                window.location.href = link.getAttribute('data-export-base') + '?' + params.toString();
            });
        });
    })();
    </script>
@endsection
