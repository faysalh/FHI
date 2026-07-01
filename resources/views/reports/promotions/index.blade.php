@extends('reports.layouts.app')
@section('title', 'Promotions')

@section('content')
@php
    use App\Support\PromotionsWeekdays;

    $setupUrl = fn (array $extra = []) => route('reports.promotions.index', array_merge(request()->query(), ['tab' => 'setup'], $extra));
    $assignmentsUrl = fn (array $extra = []) => route('reports.promotions.index', array_merge(request()->query(), ['tab' => 'assignments'], $extra));
    $scheduleUrl = fn (array $extra = []) => route('reports.promotions.index', array_merge(request()->query(), ['tab' => 'schedule'], $extra));
@endphp

<header class="page-header"><h1>Promotions</h1></header>

<div class="subtabs">
    <a href="{{ $setupUrl() }}" class="{{ $tab === 'setup' ? 'active' : '' }}">Promoters</a>
    <a href="{{ $assignmentsUrl(['promoter_id' => $promoterId]) }}" class="{{ $tab === 'assignments' ? 'active' : '' }}">Client assignments</a>
    <a href="{{ $scheduleUrl(['promoter_id' => $promoterId, 'week_start' => $weekStart]) }}" class="{{ $tab === 'schedule' ? 'active' : '' }}">Schedule</a>
</div>

@include('reports.partials.flash-messages')

@if ($tab === 'setup')
    <p class="hint">Add promoters with employee name and vehicle. Visit days are chosen per client when you assign them on the Client assignments tab.</p>

    <div class="lab-card">
        <h3 class="section-title">{{ $editingPromoter ? 'Edit promoter' : 'Add promoter' }}</h3>
        <form method="POST"
              action="{{ $editingPromoter ? route('reports.promotions.promoters.update', ['promoter' => (int) $editingPromoter->id]) : route('reports.promotions.promoters.store') }}"
              class="promo-form">
            @csrf
            @if ($editingPromoter)
                @method('PUT')
            @endif
            <input type="hidden" name="tab" value="setup">
            <div class="promo-form-grid">
                <div>
                    <label for="employee_name">Employee name</label>
                    <input type="text" id="employee_name" name="employee_name" maxlength="200" required
                           value="{{ old('employee_name', $editingPromoter->employee_name ?? '') }}">
                </div>
                <div>
                    <label for="vehicle">Vehicle</label>
                    <input type="text" id="vehicle" name="vehicle" maxlength="500"
                           value="{{ old('vehicle', $editingPromoter->vehicle ?? '') }}" placeholder="Plate or description">
                </div>
            </div>
            <div class="inline-action-row">
                @include('reports.partials.icon-button', ['action' => 'save', 'label' => $editingPromoter ? 'Update promoter' : 'Add promoter'])
                @if ($editingPromoter)
                    <a href="{{ $setupUrl() }}" class="btn btn--secondary">Cancel edit</a>
                @endif
            </div>
        </form>
    </div>

    <div class="lab-card">
        <h3 class="section-title">Promoters ({{ count($promoters) }})</h3>
        <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Vehicle</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($promoters as $promoter)
                    <tr>
                        <td>{{ $promoter->employee_name }}</td>
                        <td>{{ $promoter->vehicle ?? '—' }}</td>
                        <td class="inline-action-row">
                            <a href="{{ $setupUrl(['edit_promoter' => (int) $promoter->id]) }}" class="btn btn--secondary btn--sm">Edit</a>
                            <form method="POST" action="{{ route('reports.promotions.promoters.destroy', ['promoter' => (int) $promoter->id, 'tab' => 'setup']) }}" onsubmit="return confirm('Delete this promoter and all client assignments?');">
                                @csrf
                                @method('DELETE')
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete', 'type' => 'submit'])
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3">No promoters yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($tab === 'assignments')
    <p class="hint">Assign clients to a promoter and choose 1–3 visit days per week for each client (or daily visits). Fridays are never visit days. Search clients live from the reporting database (type 2+ characters).</p>

    @if ($promoters === [])
        <div class="alert alert--warn">Add at least one promoter on the Promoters tab first.</div>
    @else
        <form method="GET" action="{{ route('reports.promotions.index') }}" class="promo-filter-form">
            <input type="hidden" name="tab" value="assignments">
            <label for="promoter_id_assign">Promoter</label>
            <select id="promoter_id_assign" name="promoter_id" onchange="this.form.submit()">
                @foreach ($promoters as $promoter)
                    <option value="{{ (int) $promoter->id }}" @selected($promoterId === (int) $promoter->id)>{{ $promoter->employee_name }}</option>
                @endforeach
            </select>
        </form>

        @if ($selectedPromoter)
            <div class="lab-card">
                <h3 class="section-title">Assign client to {{ $selectedPromoter->employee_name }}</h3>
                <form method="POST" action="{{ route('reports.promotions.assignments.store') }}" class="promo-form" id="promo_assign_form">
                    @csrf
                    <input type="hidden" name="tab" value="assignments">
                    <input type="hidden" name="promoter_id" value="{{ $promoterId }}">
                    <div class="promo-form-grid">
                        <div class="promo-client-search-wrap">
                            <label for="client_search">Client (market)</label>
                            <input type="text"
                                   id="client_search"
                                   placeholder="Type at least 2 characters to search…"
                                   autocomplete="off"
                                   value="{{ old('client_name') }}"
                                   required>
                            <div id="client_suggest" class="promo-suggest" role="listbox" aria-label="Client suggestions"></div>
                            <p id="client_selected_label" class="muted" style="margin:6px 0 0;font-size:12px;"></p>
                            <input type="hidden" name="client_account_id" id="client_account_id" value="{{ old('client_account_id') }}">
                            <input type="hidden" name="client_name" id="client_name" value="{{ old('client_name') }}">
                        </div>
                        <div class="promo-form-span">
                            @include('reports.promotions.partials.visit-days-fields', [
                                'fieldName' => 'visit_days',
                                'selected' => old('visit_days', []),
                                'inputIdPrefix' => 'assign_visit_days',
                            ])
                        </div>
                    </div>
                    @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Assign client'])
                </form>
            </div>

            <div class="lab-card">
                <h3 class="section-title">Assigned clients ({{ count($assignments) }})</h3>
                <div class="table-scroll">
                    <table>
                        <thead>
                        <tr>
                            <th>Client</th>
                            <th>Visit days</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($assignments as $assignment)
                            @php
                                $effectiveDays = app(\App\Services\PromotionsSqliteService::class)->effectiveVisitDays($selectedPromoter, $assignment);
                                $isDaily = PromotionsWeekdays::isDailyVisitSchedule($effectiveDays);
                            @endphp
                            <tr>
                                <td>{{ $assignment->client_name }}</td>
                                <td>
                                    @if ($isDaily)
                                        <span>Daily</span>
                                        <span class="muted" style="font-size:11px;">(Sat–Thu)</span>
                                    @else
                                        {{ implode(', ', array_map(fn (int $d) => PromotionsWeekdays::label($d), $effectiveDays)) ?: '—' }}
                                    @endif
                                </td>
                                <td>
                                    <details class="inline-edit-details">
                                        <summary class="btn btn--secondary btn--sm">Edit days</summary>
                                        <form method="POST" action="{{ route('reports.promotions.assignments.update', ['assignment' => (int) $assignment->id]) }}" class="promo-form" style="margin-top:8px;">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="tab" value="assignments">
                                            <input type="hidden" name="promoter_id" value="{{ $promoterId }}">
                                            @include('reports.promotions.partials.visit-days-fields', [
                                                'fieldName' => 'visit_days',
                                                'selected' => PromotionsWeekdays::parseCsv((string) ($assignment->visit_days_override ?? '[]')) ?: $effectiveDays,
                                                'inputIdPrefix' => 'edit_'.$assignment->id,
                                                'dailyVisits' => $isDaily,
                                            ])
                                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save days', 'type' => 'submit'])
                                        </form>
                                        <form method="POST" action="{{ route('reports.promotions.assignments.destroy', ['assignment' => (int) $assignment->id, 'promoter_id' => $promoterId, 'tab' => 'assignments']) }}" style="margin-top:8px;" onsubmit="return confirm('Remove this client from the promoter?');">
                                            @csrf
                                            @method('DELETE')
                                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Remove', 'type' => 'submit'])
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3">No clients assigned yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endif

@if ($tab === 'schedule')
    <p class="hint">Weekly visit grid for one promoter. Week runs Saturday through Thursday (Friday excluded). Columns also skip configured holidays. Export PDF or CSV for the selected promoter.</p>

    @if ($promoters === [])
        <div class="alert alert--warn">Add promoters and assign clients first.</div>
    @else
        <form method="GET" action="{{ route('reports.promotions.index') }}" class="promo-filter-form" id="promo_schedule_form">
            <input type="hidden" name="tab" value="schedule">
            <div class="promo-filter-grid">
                <div>
                    <label for="promoter_id_schedule">Promoter</label>
                    <select id="promoter_id_schedule" name="promoter_id">
                        @foreach ($promoters as $promoter)
                            <option value="{{ (int) $promoter->id }}" @selected($promoterId === (int) $promoter->id)>{{ $promoter->employee_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="week_start">Week starting (Saturday)</label>
                    <input type="date" id="week_start" name="week_start" value="{{ $weekStart }}" required>
                </div>
            </div>
            <div class="inline-action-row">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Show schedule', 'type' => 'submit'])
                @if ($selectedPromoter && count($assignments) > 0)
                    <a href="{{ route('reports.promotions.export.pdf', ['promoter_id' => $promoterId, 'week_start' => $weekStart]) }}" class="btn btn--secondary">Export PDF</a>
                    <a href="{{ route('reports.promotions.export.csv', ['promoter_id' => $promoterId, 'week_start' => $weekStart]) }}" class="btn btn--secondary">Export CSV</a>
                @endif
            </div>
        </form>

        @if ($selectedPromoter)
            <div class="lab-card">
                <h3 class="section-title">
                    {{ $selectedPromoter->employee_name }}
                    @if (! empty($selectedPromoter->vehicle))
                        <span class="muted" style="font-weight:400;"> — {{ $selectedPromoter->vehicle }}</span>
                    @endif
                </h3>
                @if (count($assignments) === 0)
                    <p class="muted">This promoter has no assigned clients.</p>
                @elseif ($scheduleSheet)
                    @include('reports.promotions.partials.schedule-grid', ['sheet' => $scheduleSheet, 'forPdf' => false])
                @else
                    <p class="muted">Could not build schedule.</p>
                @endif
            </div>
        @endif
    @endif
@endif
@endsection

@push('scripts')
<script>
(function () {
    var clientsUrl = @json(route('reports.promotions.api.clients'));
    var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    var clientSearch = document.getElementById('client_search');
    var clientSuggest = document.getElementById('client_suggest');
    var clientAccountId = document.getElementById('client_account_id');
    var clientName = document.getElementById('client_name');
    var clientSelectedLabel = document.getElementById('client_selected_label');
    var assignForm = document.getElementById('promo_assign_form');
    var selectedClient = null;

    function wireVisitDaysForm(formRoot) {
        if (!formRoot) return;
        var dailyToggle = formRoot.querySelector('.promo-daily-visits-toggle');
        var dayInputs = formRoot.querySelectorAll('.promo-weekday input[type="checkbox"]');

        function syncDailyState() {
            var daily = dailyToggle && dailyToggle.checked;
            dayInputs.forEach(function (cb) {
                if (daily) {
                    cb.checked = true;
                    cb.disabled = true;
                } else {
                    cb.disabled = false;
                }
            });
        }

        if (dailyToggle) {
            dailyToggle.addEventListener('change', syncDailyState);
            syncDailyState();
        }

        dayInputs.forEach(function (cb) {
            cb.addEventListener('change', function () {
                if (dailyToggle && dailyToggle.checked) {
                    return;
                }
                var checkedCount = 0;
                dayInputs.forEach(function (other) {
                    if (other.checked) checkedCount++;
                });
                if (checkedCount > 3) {
                    cb.checked = false;
                    alert('Select 1 to 3 visit days per week, or enable daily visits.');
                }
            });
        });
    }

    document.querySelectorAll('.promo-visit-days-form').forEach(wireVisitDaysForm);

    function debounce(fn, ms) {
        var t;
        return function () {
            clearTimeout(t);
            var args = arguments;
            var self = this;
            t = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    function setSelectedClient(row) {
        if (!row) {
            selectedClient = null;
            if (clientAccountId) clientAccountId.value = '';
            if (clientName) clientName.value = clientSearch ? clientSearch.value.trim() : '';
            if (clientSelectedLabel) clientSelectedLabel.textContent = '';
            return;
        }
        selectedClient = row;
        if (clientAccountId) clientAccountId.value = row.account_id || '';
        if (clientName) clientName.value = row.client_name || '';
        if (clientSearch) clientSearch.value = row.client_name || '';
        if (clientSelectedLabel) {
            clientSelectedLabel.textContent = 'Selected: ' + row.client_name + (row.client_code ? ' (' + row.client_code + ')' : '');
        }
    }

    if (clientSearch && clientSuggest) {
        var runSearch = debounce(function () {
            var q = clientSearch.value.trim();
            selectedClient = null;
            if (clientAccountId) clientAccountId.value = '';
            if (clientName) clientName.value = q;
            if (clientSelectedLabel) clientSelectedLabel.textContent = '';
            if (q.length < 2) {
                clientSuggest.style.display = 'none';
                clientSuggest.innerHTML = '';
                return;
            }
            fetch(clientsUrl + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            }).then(function (r) { return r.json(); }).then(function (data) {
                clientSuggest.innerHTML = '';
                if (!data.ok || !data.rows || !data.rows.length) {
                    clientSuggest.style.display = 'none';
                    if (!data.ok && data.message && clientSelectedLabel) {
                        clientSelectedLabel.textContent = data.message;
                    }
                    return;
                }
                data.rows.forEach(function (row) {
                    var div = document.createElement('div');
                    div.setAttribute('role', 'option');
                    div.textContent = (row.client_code ? row.client_code + ' — ' : '') + row.client_name;
                    div.addEventListener('click', function () {
                        setSelectedClient(row);
                        clientSuggest.style.display = 'none';
                    });
                    clientSuggest.appendChild(div);
                });
                clientSuggest.style.display = 'block';
            }).catch(function () {
                clientSuggest.style.display = 'none';
                if (clientSelectedLabel) {
                    clientSelectedLabel.textContent = 'Could not search clients. Check SQL Server connection.';
                }
            });
        }, 300);

        clientSearch.addEventListener('input', runSearch);
        document.addEventListener('click', function (e) {
            if (!clientSuggest.contains(e.target) && e.target !== clientSearch) {
                clientSuggest.style.display = 'none';
            }
        });
    }

    if (assignForm) {
        assignForm.addEventListener('submit', function (e) {
            if (!clientAccountId || !clientAccountId.value.trim()) {
                e.preventDefault();
                alert('Please pick a client from the search results.');
                if (clientSearch) clientSearch.focus();
                return;
            }
            var dayRoot = assignForm.querySelector('.promo-visit-days-form');
            var dailyToggle = dayRoot ? dayRoot.querySelector('.promo-daily-visits-toggle') : null;
            if (dailyToggle && dailyToggle.checked) {
                return;
            }
            var checked = dayRoot ? dayRoot.querySelectorAll('.promo-weekday input[type="checkbox"]:checked').length : 0;
            if (checked < 1 || checked > 3) {
                e.preventDefault();
                alert('Select 1 to 3 visit days, or enable daily visits.');
            }
        });
    }
})();
</script>
@endpush

@push('styles')
<style>
.promo-form-grid,
.promo-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px 12px;
    margin-bottom: 12px;
}
.promo-form-span { grid-column: 1 / -1; }
.promo-weekdays {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
    margin-top: 6px;
}
.promo-weekday {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
}
.promo-checkbox-inline {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.promo-filter-form { margin-bottom: 16px; }
.promo-schedule-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 640px;
}
.promo-schedule-table th,
.promo-schedule-table td {
    border: 1px solid #e2e8f0;
    padding: 8px 10px;
    vertical-align: top;
    min-width: 100px;
}
.promo-schedule-table th {
    background: #f8fafc;
    text-align: left;
}
.field-label {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}
.promo-client-search-wrap { position: relative; }
.promo-suggest {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    max-height: 220px;
    overflow: auto;
    margin-top: 4px;
    display: none;
    background: #fff;
    position: absolute;
    z-index: 20;
    left: 0;
    right: 0;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}
.promo-suggest div {
    padding: 8px 10px;
    cursor: pointer;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
}
.promo-suggest div:hover { background: #eff6ff; }
</style>
@endpush
