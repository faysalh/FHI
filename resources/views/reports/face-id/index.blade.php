@extends('reports.layouts.app')
@section('title', __('Face ID'))

@section('content')
@php
    use App\Support\FaceIdLocation;
    use App\Support\ReportingTime;
    $tab = $filters['tab'] ?? 'employees';
    $dateFrom = $filters['date_from'] ?? ReportingTime::now()->startOfMonth()->toDateString();
    $dateTo = $filters['date_to'] ?? ReportingTime::now()->toDateString();
@endphp

<header class="page-header"><h1>{{ __('Face ID') }}</h1></header>
<p class="hint">Register employees, enroll faces from this dashboard, and share the kiosk link for automatic clock-in and clock-out. Face recognition runs in the browser; only enrolled faces are logged. Camera access requires HTTPS or localhost.</p>

<div class="subtabs">
    <a href="{{ route('reports.face-id.index', ['tab' => 'employees']) }}" class="{{ $tab === 'employees' ? 'active' : '' }}">{{ __('Employees') }}</a>
    <a href="{{ route('reports.face-id.index', ['tab' => 'logs', 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="{{ $tab === 'logs' ? 'active' : '' }}">{{ __('Attendance logs') }}</a>
    <a href="{{ route('reports.face-id.index', ['tab' => 'kiosk']) }}" class="{{ $tab === 'kiosk' ? 'active' : '' }}">{{ __('Kiosk link') }}</a>
</div>

@include('reports.partials.flash-messages')

@if ($tab === 'employees')
    @if (!empty($errorMessage))
        <p class="hint" style="color:#b91c1c;">{{ $errorMessage }}</p>
    @endif

    <div class="lab-card face-id-system-check" id="face-id-system-check" style="margin-bottom:16px;">
        <h3 class="section-title">{{ __('System check') }}</h3>
        <p class="hint">{{ __('Green rows mean enrollment should work. Fix any red items before enrolling faces.') }}</p>
        <table data-no-table-scroll data-no-mobile-cards>
            <tbody>
            <tr data-check="https">
                <td>{{ __('HTTPS') }}</td>
                <td class="face-id-check-result muted">{{ __('Checking…') }}</td>
            </tr>
            <tr data-check="faceapi">
                <td>face-api.js</td>
                <td class="face-id-check-result muted">{{ __('Checking…') }}</td>
            </tr>
            <tr data-check="models">
                <td>{{ __('Model weights') }}</td>
                <td class="face-id-check-result muted">{{ __('Checking…') }}</td>
            </tr>
            <tr data-check="camera">
                <td>{{ __('Camera permission') }}</td>
                <td class="face-id-check-result muted">{{ __('Checking…') }}</td>
            </tr>
            <tr data-check="timezone">
                <td>{{ __('Server time (attendance)') }}</td>
                <td class="face-id-check-result">
                    @php
                        $tzOk = ($reportingTimezone ?? '') === 'Asia/Baghdad';
                    @endphp
                    @if ($tzOk)
                        <span class="badge badge--success">{{ $reportingNow ?? '' }} ({{ $reportingTimezone }})</span>
                    @else
                        <span class="badge badge--warn">{{ $reportingNow ?? '' }} ({{ $reportingTimezone ?? 'unknown' }}) — run installer\repair-timezone.cmd</span>
                    @endif
                </td>
            </tr>
            <tr data-check="sqlite">
                <td>{{ __('Face ID database') }}</td>
                <td class="face-id-check-result">
                    @if ($faceIdReady ?? false)
                        <span class="badge badge--success">{{ __('Ready') }} ({{ $faceIdEmployeeCount ?? 0 }} {{ __('employees') }})</span>
                        <div class="muted" style="font-size:11px;margin-top:4px;word-break:break-all;">{{ $faceIdDatabasePath ?? '' }}</div>
                    @else
                        <span class="badge badge--warn">{{ __('Unavailable') }}</span>
                    @endif
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="lab-card" style="margin-bottom:16px;">
        <h3 class="section-title">{{ __('Add employee') }}</h3>
        <form method="POST" action="{{ route('reports.face-id.employees.store') }}" class="mini-grid">
            @csrf
            <input type="hidden" name="tab" value="employees">
            <div>
                <label for="employee_name">{{ __('Name') }}</label>
                <input type="text" id="employee_name" name="name" value="{{ old('name') }}" required maxlength="255">
            </div>
            <div>
                <label for="employee_code">{{ __('Employee code') }} <span class="muted">({{ __('optional') }})</span></label>
                <input type="text" id="employee_code" name="employee_code" value="{{ old('employee_code') }}" maxlength="100">
            </div>
            <div style="align-self:end;">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => __('Add employee'), 'type' => 'submit'])
            </div>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ __('Name') }}</th>
            <th>{{ __('Code') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Face') }}</th>
            <th>{{ __('Actions') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($employees as $employee)
            @php
                $enrolled = ! empty($employee->face_descriptor);
                $isActive = (int) ($employee->is_active ?? 1) === 1;
            @endphp
            <tr>
                <td>{{ $employee->name }}</td>
                <td>{{ $employee->employee_code ?: '—' }}</td>
                <td>
                    @if ($isActive)
                        <span class="badge badge--success">{{ __('Active') }}</span>
                    @else
                        <span class="badge badge--muted">{{ __('Inactive') }}</span>
                    @endif
                </td>
                <td>
                    @if ($enrolled)
                        <span class="badge badge--success">{{ __('Enrolled') }}</span>
                    @else
                        <span class="badge badge--warn">{{ __('Not enrolled') }}</span>
                    @endif
                </td>
                <td>
                    <div class="btn-row">
                        <button type="button" class="btn btn-sm" data-face-enroll="{{ $employee->id }}" data-employee-name="{{ $employee->name }}">{{ __('Enroll face') }}</button>
                        @if ($enrolled)
                            <form method="POST" action="{{ route('reports.face-id.employees.face.destroy', $employee->id) }}" class="inline-form" onsubmit="return confirm('{{ __('Clear face enrollment for this employee?') }}');">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="tab" value="employees">
                                <button type="submit" class="btn btn-sm btn-danger">{{ __('Clear face') }}</button>
                            </form>
                        @endif
                    </div>
                    <details class="employee-edit-details" style="margin-top:8px;">
                        <summary class="muted" style="cursor:pointer;">{{ __('Edit') }}</summary>
                        <form method="POST" action="{{ route('reports.face-id.employees.update', $employee->id) }}" class="mini-grid" style="margin-top:8px;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="tab" value="employees">
                            <div>
                                <label>{{ __('Name') }}</label>
                                <input type="text" name="name" value="{{ $employee->name }}" required maxlength="255">
                            </div>
                            <div>
                                <label>{{ __('Employee code') }}</label>
                                <input type="text" name="employee_code" value="{{ $employee->employee_code }}" maxlength="100">
                            </div>
                            <div>
                                <label class="chk-label">
                                    <input type="checkbox" name="is_active" value="1" @checked($isActive)>
                                    {{ __('Active') }}
                                </label>
                            </div>
                            <div style="align-self:end;">
                                <button type="submit" class="btn btn-sm">{{ __('Save') }}</button>
                            </div>
                        </form>
                        <form method="POST" action="{{ route('reports.face-id.employees.destroy', $employee->id) }}" style="margin-top:8px;" onsubmit="return confirm('{{ __('Delete this employee and all attendance logs?') }}');">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="tab" value="employees">
                            <button type="submit" class="btn btn-sm btn-danger">{{ __('Delete') }}</button>
                        </form>
                    </details>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">{{ __('No employees yet. Add one above.') }}</td></tr>
        @endforelse
        </tbody>
    </table>

    @include('reports.face-id.partials.enroll-modal')
@endif

@if ($tab === 'logs')
    <p class="hint">{{ __('Attendance times use server timezone') }}: <strong>{{ $reportingTimezone ?? '—' }}</strong> — {{ __('server now') }}: <strong>{{ $reportingNow ?? '—' }}</strong>. {{ __('If this is ~3 hours behind your clock, run') }} <code>installer\repair-timezone.cmd</code> {{ __('on the server.') }}</p>
    <form method="GET" action="{{ route('reports.face-id.index') }}" class="filter-bar" id="face-id-logs-form">
        <input type="hidden" name="tab" value="logs">
        <div class="filter-grid">
            <div>
                <label for="date_from">{{ __('From') }}</label>
                <input type="date" id="date_from" name="date_from" value="{{ $dateFrom }}">
            </div>
            <div>
                <label for="date_to">{{ __('To') }}</label>
                <input type="date" id="date_to" name="date_to" value="{{ $dateTo }}">
            </div>
            <div class="filter-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => __('Apply'), 'type' => 'submit'])
                <div class="btn-group">
                    <a href="#" class="face-id-export-link export-link" data-export-base="{{ route('reports.face-id.export.csv') }}">{{ __('CSV') }}</a>
                    <a href="#" class="face-id-export-link export-link" data-export-base="{{ route('reports.face-id.export.pdf') }}">{{ __('PDF') }}</a>
                </div>
            </div>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>{{ __('Employee') }}</th>
            <th>{{ __('Code') }}</th>
            <th>{{ __('Event') }}</th>
            <th>{{ __('Recorded at') }}</th>
            <th>{{ __('Location') }}</th>
            <th class="num">{{ __('Confidence') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($attendance as $row)
            <tr>
                <td>{{ $row->employee_name }}</td>
                <td>{{ $row->employee_code ?: '—' }}</td>
                <td>{{ $row->event_type === 'clock_in' ? __('Clock in') : __('Clock out') }}</td>
                <td>{{ ReportingTime::formatStored($row->recorded_at) }}</td>
                <td>
                    @php
                        $lat = isset($row->latitude) ? (float) $row->latitude : null;
                        $lng = isset($row->longitude) ? (float) $row->longitude : null;
                        $accuracy = isset($row->location_accuracy) ? (float) $row->location_accuracy : null;
                        $mapsUrl = FaceIdLocation::mapsUrl($lat, $lng);
                        $locationText = FaceIdLocation::format($lat, $lng, $accuracy);
                    @endphp
                    @if ($mapsUrl)
                        <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer">{{ $locationText }}</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td class="num">{{ $row->confidence !== null ? number_format((float) $row->confidence, 2) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('No attendance records for this period.') }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($tab === 'kiosk')
    <div class="lab-card">
        <h3 class="section-title">{{ __('Workplace kiosk link') }}</h3>
        <p class="hint">Open this link on a tablet or PC at the entrance. Employees look at the camera; recognized faces are logged automatically. Unrecognized faces are ignored. The kiosk asks for <strong>camera</strong> and <strong>location</strong> permission (HTTPS required); GPS coordinates are saved with each punch.</p>
        <div class="kiosk-url-row" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0;">
            <input type="text" id="kiosk-url" readonly value="{{ $kioskUrl }}" style="flex:1;min-width:200px;font-size:14px;padding:8px 10px;">
            <button type="button" class="btn" id="copy-kiosk-url">{{ __('Copy link') }}</button>
        </div>
        <form method="POST" action="{{ route('reports.face-id.kiosk-token.regenerate') }}" onsubmit="return confirm('{{ __('Regenerate the kiosk link? The old link will stop working immediately.') }}');">
            @csrf
            <input type="hidden" name="tab" value="kiosk">
            <button type="submit" class="btn btn-danger">{{ __('Regenerate link') }}</button>
        </form>
    </div>
@endif

<style>
    .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; }
    .badge--success { background:#ecfdf5; color:#065f46; }
    .badge--warn { background:#fffbeb; color:#92400e; }
    .badge--muted { background:#f1f5f9; color:#64748b; }
    .btn-row { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
    .inline-form { display:inline; }
</style>

@if ($tab === 'logs')
<script>
document.querySelectorAll('a.face-id-export-link').forEach(function (a) {
    a.addEventListener('click', function (e) {
        e.preventDefault();
        var form = document.getElementById('face-id-logs-form');
        var base = a.getAttribute('data-export-base');
        if (!form || !base) return;
        var params = new URLSearchParams(new FormData(form));
        window.location.href = base + '?' + params.toString();
    });
});
</script>
@endif

@if ($tab === 'kiosk')
<script>
document.getElementById('copy-kiosk-url')?.addEventListener('click', function () {
    var input = document.getElementById('kiosk-url');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, input.value.length);
    navigator.clipboard?.writeText(input.value).catch(function () {
        document.execCommand('copy');
    });
});
</script>
@endif

@if ($tab === 'employees')
{{-- Root-relative URLs avoid APP_URL http/https port mismatches (mixed content blocks scripts). --}}
<script src="/js/face-api.min.js?v=3"></script>
<script src="/js/face-id-detector.js?v=4"></script>
<script>
window.FaceIdEnrollConfig = {
    modelsUrl: '/face-api-models',
    csrfToken: @json(csrf_token()),
    saveUrlTemplate: @json(route('reports.face-id.employees.face.store', ['employee' => '__ID__'])),
    autoCapture: true
};
</script>
<script src="/js/face-id-enroll.js?v=8"></script>
<script>
(function () {
    'use strict';
    var faceApiCandidates = ['/js/face-api.min.js?v=3', '/js/vendor/face-api.min.js'];

    function setRow(key, ok, detail) {
        var row = document.querySelector('#face-id-system-check tr[data-check="' + key + '"] .face-id-check-result');
        if (!row) return;
        row.innerHTML = ok
            ? '<span class="badge badge--success">' + detail + '</span>'
            : '<span class="badge badge--warn">' + detail + '</span>';
    }

    function probeFaceApiLoaded() {
        if (typeof faceapi !== 'undefined') {
            setRow('faceapi', true, 'Loaded');
            return Promise.resolve(true);
        }
        return fetch(faceApiCandidates[0], { method: 'GET', cache: 'no-store' })
            .then(function (r) {
                if (r.ok) {
                    return r.text().then(function (body) {
                        if (body.length > 100000 && body.indexOf('faceapi') !== -1) {
                            setRow('faceapi', false, 'File reachable (' + body.length + ' bytes) but faceapi global missing — hard refresh (Ctrl+F5)');
                        } else {
                            setRow('faceapi', false, 'Bad response (' + r.status + ', ' + body.length + ' bytes)');
                        }
                    });
                }
                return fetch(faceApiCandidates[1], { method: 'GET', cache: 'no-store' }).then(function (r2) {
                    if (r2.ok) {
                        setRow('faceapi', false, 'Use /js/face-api.min.js — /js/vendor/ is blocked by IIS');
                    } else {
                        setRow('faceapi', false, 'Not found (HTTP ' + r.status + ') — run installer upgrade or copy public\\js\\face-api.min.js');
                    }
                });
            })
            .catch(function (e) {
                setRow('faceapi', false, 'Request failed: ' + (e.message || 'network'));
            });
    }

    function probeModelAssets() {
        var manifestUrl = '/face-api-models/tiny_face_detector_model-weights_manifest.json';
        var shardUrl = '/face-api-models/tiny_face_detector_model-shard1.bin';

        return fetch(manifestUrl, { method: 'GET', cache: 'no-store' })
            .then(function (r) {
                if (!r.ok) {
                    setRow('models', false, 'Manifest HTTP ' + r.status);
                    return;
                }
                return fetch(shardUrl, { method: 'GET', cache: 'no-store' }).then(function (r2) {
                    if (r2.ok && r2.headers.get('content-type') && r2.headers.get('content-type').indexOf('text/html') === -1) {
                        return r2.blob().then(function (b) {
                            setRow('models', b.size > 100000, b.size > 100000 ? 'Manifest + shard OK (' + b.size + ' bytes)' : 'Shard too small (' + b.size + ' bytes)');
                        });
                    }
                    setRow('models', false, 'Shard HTTP ' + r2.status + ' — IIS may route weight files to Laravel');
                });
            })
            .catch(function () {
                setRow('models', false, 'Not reachable');
            });
    }

    setRow('https', location.protocol === 'https:', location.protocol === 'https:' ? 'HTTPS' : 'HTTP (camera may be blocked)');
    probeFaceApiLoaded();
    probeModelAssets();

    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function (result) {
            var ok = result.state === 'granted' || result.state === 'prompt';
            var label = result.state === 'granted' ? 'Granted' : (result.state === 'prompt' ? 'Not asked yet' : 'Denied');
            setRow('camera', ok, label);
        }).catch(function () {
            setRow('camera', true, 'Unknown (will prompt on enroll)');
        });
    } else {
        setRow('camera', true, 'Unknown (will prompt on enroll)');
    }
})();
</script>
@endif
@endsection
