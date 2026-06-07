@extends('reports.layouts.app')
@section('title', 'Deliveries report')

@section('content')
<header class="page-header"><h1>Deliveries report</h1></header>
    <div class="subtabs">
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'report'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'report' ? 'active' : '' }}">Report</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'setup'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'setup' ? 'active' : '' }}">Setup drivers & companions</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'daily-teams'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'daily-teams' ? 'active' : '' }}">Setup daily teams</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'batch-assignment'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'batch-assignment' ? 'active' : '' }}">Batch assignment</a>
    </div>

    @if (($filters['tab'] ?? 'report') === 'setup')
        <p class="hint">Save drivers (with car details) and companions in local SQLite tables. Edit inline and save, or delete (removes related daily teams and invoice assignments).</p>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Add driver</h3>
            <form method="POST" action="{{ route('reports.deliveries.setup.driver', request()->query()) }}" class="mini-grid">
                @csrf
                <div>
                    <label for="driver_name">Driver name</label>
                    <input type="text" id="driver_name" name="driver_name" required>
                </div>
                <div>
                    <label for="car_number">Car number</label>
                    <input type="text" id="car_number" name="car_number">
                </div>
                <div>
                    <label for="car_model">Car model</label>
                    <input type="text" id="car_model" name="car_model">
                </div>
                <div class="inline-action-row" style="align-items:flex-end;">
                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save driver'])
                </div>
            </form>
        </div>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Add companion</h3>
            <form method="POST" action="{{ route('reports.deliveries.setup.companion', request()->query()) }}" class="mini-grid">
                @csrf
                <div>
                    <label for="companion_name">Companion name</label>
                    <input type="text" id="companion_name" name="companion_name" required>
                </div>
                <div class="inline-action-row" style="align-items:flex-end;">
                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save companion'])
                </div>
            </form>
        </div>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Drivers</h3>
            <table class="setup-people-table">
                <thead><tr><th>Name</th><th>Car number</th><th>Car model</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse (($drivers ?? []) as $driver)
                    @php
                        $driverId = (int) ($driver->id ?? 0);
                        $driverFormId = 'driver-update-'.$driverId;
                    @endphp
                    <tr>
                        <td><input type="text" form="{{ $driverFormId }}" name="driver_name" value="{{ $driver->full_name ?? '' }}" required></td>
                        <td><input type="text" form="{{ $driverFormId }}" name="car_number" value="{{ $driver->car_number ?? '' }}"></td>
                        <td><input type="text" form="{{ $driverFormId }}" name="car_model" value="{{ $driver->car_model ?? '' }}"></td>
                        <td class="setup-people-actions">
                            <form id="{{ $driverFormId }}" method="POST" action="{{ route('reports.deliveries.setup.driver.update', array_merge(['person' => $driverId], request()->query())) }}" class="setup-people-actions__form">
                                @csrf
                                @method('PUT')
                                @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save driver'])
                            </form>
                            <form method="POST" action="{{ route('reports.deliveries.setup.driver.delete', array_merge(['person' => $driverId], request()->query())) }}" class="setup-people-actions__form" onsubmit="return confirm('Delete this driver? Daily teams using them and their invoice assignments will be removed.');">
                                @csrf
                                @method('DELETE')
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete driver'])
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No drivers saved yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Companions</h3>
            <table class="setup-people-table">
                <thead><tr><th>Name</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse (($companions ?? []) as $companion)
                    @php
                        $companionId = (int) ($companion->id ?? 0);
                        $companionFormId = 'companion-update-'.$companionId;
                    @endphp
                    <tr>
                        <td><input type="text" form="{{ $companionFormId }}" name="companion_name" value="{{ $companion->full_name ?? '' }}" required></td>
                        <td class="setup-people-actions">
                            <form id="{{ $companionFormId }}" method="POST" action="{{ route('reports.deliveries.setup.companion.update', array_merge(['person' => $companionId], request()->query())) }}" class="setup-people-actions__form">
                                @csrf
                                @method('PUT')
                                @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save companion'])
                            </form>
                            <form method="POST" action="{{ route('reports.deliveries.setup.companion.delete', array_merge(['person' => $companionId], request()->query())) }}" class="setup-people-actions__form" onsubmit="return confirm('Delete this companion? Daily teams using them and their invoice assignments will be removed.');">
                                @csrf
                                @method('DELETE')
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete companion'])
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="muted">No companions saved yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif (($filters['tab'] ?? 'report') === 'daily-teams')
        <p class="hint">Create team combinations for a specific day, then use them in the report tab.</p>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Add daily team</h3>
            <form method="POST" action="{{ route('reports.deliveries.setup.daily-team', request()->query()) }}" class="mini-grid">
                @csrf
                <div>
                    <label for="team_date">Team date</label>
                    <input type="date" id="team_date" name="team_date" value="{{ $filters['team_date'] ?? '' }}" required>
                </div>
                <div>
                    <label for="driver_id">Driver</label>
                    <select id="driver_id" name="driver_id" required>
                        <option value="">Select driver</option>
                        @foreach (($drivers ?? []) as $driver)
                            <option value="{{ (int) ($driver->id ?? 0) }}">{{ $driver->full_name ?? '' }}{{ !empty($driver->car_number) || !empty($driver->car_model) ? ' ('.trim(($driver->car_number ?? '').' '.($driver->car_model ?? '')).')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="companion_id">Companion</label>
                    <select id="companion_id" name="companion_id" required>
                        <option value="">Select companion</option>
                        @foreach (($companions ?? []) as $companion)
                            <option value="{{ (int) ($companion->id ?? 0) }}">{{ $companion->full_name ?? '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="inline-action-row" style="align-items:flex-end;">
                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save daily team'])
                </div>
            </form>
        </div>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Teams for {{ $filters['team_date'] ?? '' }}</h3>
            <table>
                <thead><tr><th>Driver</th><th>Car</th><th>Companion</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse (($teamsForSetupDate ?? []) as $team)
                    <tr>
                        <td>{{ $team->driver_name ?? '' }}</td>
                        <td>{{ trim((string) (($team->car_number ?? '').' '.($team->car_model ?? ''))) }}</td>
                        <td>{{ $team->companion_name ?? '' }}</td>
                        <td>
                            <form method="POST" action="{{ route('reports.deliveries.setup.daily-team.delete', array_merge(['team' => (int) ($team->id ?? 0)], request()->query())) }}" style="display:inline;" onsubmit="return confirm('Delete this daily team? Invoices assigned to this team will be unassigned.');">
                                @csrf
                                @method('DELETE')
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete daily team'])
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No teams for selected date.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif (($filters['tab'] ?? 'report') === 'batch-assignment')
        <p class="hint">
            Upload a PDF containing invoice numbers and choose a team. Every matched invoice is assigned to that team,
            including invoices already assigned to a different team (for example returned items you batch again).
            Invoice dates outside the From/To range below are still matched; those dates are for navigation only.
        </p>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Batch assignment from PDF</h3>
            <form method="POST" action="{{ route('reports.deliveries.batch-assign', request()->query()) }}" enctype="multipart/form-data" class="mini-grid">
                @csrf
                <div>
                    <label for="batch_team_id">Team</label>
                    <select id="batch_team_id" name="team_id" required>
                        <option value="">Select team</option>
                        @foreach (($teamFilterOptions ?? []) as $teamOpt)
                            @php
                                $teamLabel = trim((string) (($teamOpt->driver_name ?? '').' '.(!empty($teamOpt->car_number) || !empty($teamOpt->car_model) ? '('.trim(($teamOpt->car_number ?? '').' '.($teamOpt->car_model ?? '')).')' : '').' + '.($teamOpt->companion_name ?? '')));
                            @endphp
                            <option value="{{ (int) ($teamOpt->id ?? 0) }}">
                                {{ $teamOpt->team_date ?? '' }} - {{ $teamLabel }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="batch_date_from">From</label>
                    <input type="date" id="batch_date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}" required>
                </div>
                <div>
                    <label for="batch_date_to">To</label>
                    <input type="date" id="batch_date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}" required>
                </div>
                <div>
                    <label for="batch_pdf">PDF file</label>
                    <input type="file" id="batch_pdf" name="batch_pdf" accept="application/pdf" required>
                </div>
                <div class="inline-action-row" style="align-items:flex-end;">
                    @include('reports.partials.icon-button', ['action' => 'run', 'label' => 'Run batch assignment'])
                </div>
            </form>
        </div>

        <div class="lab-card deliveries-setup-card">
            <h3 class="section-title">Clear team assignments</h3>
            <p class="hint" style="margin-top:0;">
                Remove every invoice assigned to a team so you can batch-assign again from a PDF.
            </p>
            <form
                method="POST"
                action="{{ route('reports.deliveries.clear-team-assignments', request()->query()) }}"
                class="mini-grid"
                onsubmit="return confirm('Remove all invoice assignments for the selected team? This cannot be undone.');"
            >
                @csrf
                <div>
                    <label for="clear_team_id">Team</label>
                    <select id="clear_team_id" name="team_id" required>
                        <option value="">Select team</option>
                        @foreach (($teamFilterOptions ?? []) as $teamOpt)
                            @php
                                $teamLabel = trim((string) (($teamOpt->driver_name ?? '').' '.(!empty($teamOpt->car_number) || !empty($teamOpt->car_model) ? '('.trim(($teamOpt->car_number ?? '').' '.($teamOpt->car_model ?? '')).')' : '').' + '.($teamOpt->companion_name ?? '')));
                            @endphp
                            <option value="{{ (int) ($teamOpt->id ?? 0) }}">
                                {{ $teamOpt->team_date ?? '' }} - {{ $teamLabel }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>&nbsp;</label>
                    @include('reports.partials.icon-button', ['action' => 'clear', 'label' => 'Clear team assignments'])
                </div>
            </form>
        </div>

        @if (is_array($batchResult ?? null))
            <div class="lab-card deliveries-setup-card">
                <h3 class="section-title">Last batch result</h3>
                <table>
                    <tbody>
                    <tr><td>Extracted invoice-like values</td><td class="num">{{ (int) ($batchResult['extracted_count'] ?? 0) }}</td></tr>
                    <tr><td>Matched invoices in report DB</td><td class="num">{{ (int) ($batchResult['matched_count'] ?? 0) }}</td></tr>
                    <tr><td>Assigned to selected team</td><td class="num">{{ (int) ($batchResult['assigned_count'] ?? 0) }}</td></tr>
                    <tr><td>Moved from another team</td><td class="num">{{ (int) ($batchResult['reassigned_count'] ?? 0) }}</td></tr>
                    <tr><td>Unmatched values</td><td class="num">{{ (int) ($batchResult['unmatched_count'] ?? 0) }}</td></tr>
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <p class="hint">
            Delivery status is read from <code>dbo.tbl_store_document_detail.fld_is_delivered</code>.
            If value = <strong>1</strong> then status is <strong>Delivered</strong>, otherwise <strong>Not delivered</strong>.
            Click a status badge to update matching non-cancelled DB detail rows for that invoice.
            Team assignment is stored in local SQLite. When a team filter is selected, all invoices assigned to that team
            are shown regardless of the From/To dates (other filters still apply).
        </p>

        <form method="GET" action="{{ route('reports.deliveries.index') }}" id="deliveries-filter-form">
            <input type="hidden" name="tab" value="report">
            <details class="filters-panel" open>
                <summary>Filters</summary>
                <div class="filters-body">
                    <div class="filters-grid">
            <div>
                <label for="date_from">From</label>
                <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
            </div>
            <div>
                <label for="date_to">To</label>
                <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
            </div>
            <div>
                <label for="storage">Storage (optional)</label>
                <select id="storage" name="storage">
                    <option value="">All storages</option>
                    @foreach (($storageOptions ?? []) as $st)
                        <option value="{{ $st }}" @selected(($filters['storage'] ?? '') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="delivery_status">Delivery status (optional)</label>
                <select id="delivery_status" name="delivery_status">
                    <option value="" @selected(($filters['delivery_status'] ?? '') === '')>All</option>
                    <option value="delivered" @selected(($filters['delivery_status'] ?? '') === 'delivered')>Delivered only</option>
                    <option value="not_delivered" @selected(($filters['delivery_status'] ?? '') === 'not_delivered')>Not delivered only</option>
                </select>
            </div>
            <div>
                <label for="team_id">Team (optional)</label>
                <select id="team_id" name="team_id">
                    <option value="">All teams</option>
                    @foreach (($teamFilterOptions ?? []) as $teamOpt)
                        @php
                            $teamLabel = trim((string) (($teamOpt->driver_name ?? '').' '.(!empty($teamOpt->car_number) || !empty($teamOpt->car_model) ? '('.trim(($teamOpt->car_number ?? '').' '.($teamOpt->car_model ?? '')).')' : '').' + '.($teamOpt->companion_name ?? '')));
                        @endphp
                        <option value="{{ (int) ($teamOpt->id ?? 0) }}" @selected((string) ($filters['team_id'] ?? '') === (string) (int) ($teamOpt->id ?? 0))>
                            {{ $teamOpt->team_date ?? '' }} - {{ $teamLabel }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="cities" title="Ctrl/Cmd+click for multiple; empty = all cities">Cities (optional, multi-select)</label>
                <select id="cities" name="cities[]" multiple size="4" class="select-compact-multi">
                    @foreach (($cityOptions ?? []) as $city)
                        <option value="{{ $city }}" @selected(in_array($city, $filters['cities'] ?? [], true))>{{ $city }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="per_page">Rows per page</label>
                <select id="per_page" name="per_page">
                    @foreach ([10, 25, 50, 100, 250] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 250) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
            <div class="span-full filters-breakdown">
                <div class="chk-row">
                <label class="chk-label">
                    <input type="checkbox" name="include_amount" value="1" @checked(!empty($filters['include_amount']))>
                    Show amount
                </label>
                <label class="chk-label">
                    <input type="checkbox" name="include_weight" value="1" @checked(!empty($filters['include_weight']))>
                    Show weight
                </label>
                </div>
            </div>
                    </div>
                    <div class="filters-actions">
                        @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                        @include('reports.partials.filters-reset-link', ['route' => 'reports.deliveries.index', 'params' => ['tab' => 'report']])
                        <span class="muted">Export:</span>
                        <a href="#" class="deliveries-export-link export-link" data-export-base="{{ route('reports.deliveries.export.pdf') }}">PDF</a>
                        <a href="#" class="deliveries-export-link export-link" data-export-base="{{ route('reports.deliveries.export.csv') }}">CSV</a>
                    </div>
                </div>
            </details>
        </form>
        @include('reports.partials.export-from-form-script', ['formId' => 'deliveries-filter-form', 'linkClass' => 'deliveries-export-link'])

        @if (! $errorMessage && $rows)
            @if (!empty($grandTotals))
                @include('reports.partials.metric-grand-totals-bar', [
                    'grandTotals' => $grandTotals,
                    'showAmount' => !empty($filters['include_amount']),
                    'showWeight' => !empty($filters['include_weight']),
                ])
            @endif
            <table>
                <thead>
                <tr>
                    <th class="num">#</th>
                    <th>Invoice number</th>
                    <th>Date</th>
                    <th>Client code</th>
                    <th>Client name</th>
                    <th>City</th>
                    <th>Storage</th>
                    <th class="num">Quantity (pcs)</th>
                    @if (!empty($filters['include_amount']))
                        <th class="num">Amount</th>
                    @endif
                    @if (!empty($filters['include_weight']))
                        <th class="num">Weight</th>
                    @endif
                    <th>Status</th>
                    <th>Team</th>
                </tr>
                </thead>
                <tbody>
                @php
                    $rowStart = (($rows->currentPage() - 1) * $rows->perPage()) + 1;
                    $colspanBeforeQty = 6;
                    $colspanAfterQty = 2 + (!empty($filters['include_amount']) ? 1 : 0) + (!empty($filters['include_weight']) ? 1 : 0);
                @endphp
                @forelse ($rows as $row)
                    @php
                        $delivered = strtolower((string) ($row->delivery_status ?? '')) === 'delivered';
                        $rowDate = (string) ($row->document_date ?? '');
                        $dateTeams = $teamsByDate[$rowDate] ?? [];
                    @endphp
                    <tr>
                        <td class="num">{{ $rowStart + $loop->index }}</td>
                        <td>
                            <div>{{ $row->invoice_no ?? $row->invoice_id ?? '' }}</div>
                        </td>
                        <td>{{ $row->document_date ?? '' }}</td>
                        <td>{{ $row->client_code ?? '' }}</td>
                        <td>{{ $row->client_name ?? '' }}</td>
                        <td>{{ $row->city_name ?? '' }}</td>
                        <td>{{ $row->storage_name ?? '' }}</td>
                        <td class="num">{{ display_number((float) ($row->quantity ?? 0)) }}</td>
                        @if (!empty($filters['include_amount']))
                            <td class="num">{{ display_number((float) ($row->amount ?? 0)) }}</td>
                        @endif
                        @if (!empty($filters['include_weight']))
                            <td class="num">{{ display_number((float) ($row->weight_total ?? 0)) }}</td>
                        @endif
                        <td>
                            <form method="POST" action="{{ route('reports.deliveries.status', request()->query()) }}" class="status-toggle">
                                @csrf
                                <input type="hidden" name="invoice_id" value="{{ $row->invoice_id ?? '' }}">
                                <input type="hidden" name="current_status" value="{{ $delivered ? 'delivered' : 'not_delivered' }}">
                                <button
                                    type="submit"
                                    class="badge {{ $delivered ? 'badge-yes' : 'badge-no' }}"
                                    title="Click to mark {{ $delivered ? 'not delivered' : 'delivered' }}"
                                >
                                    {{ $delivered ? 'Delivered' : 'Not delivered' }}
                                </button>
                            </form>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('reports.deliveries.assign-team', request()->query()) }}">
                                @csrf
                                <input type="hidden" name="invoice_id" value="{{ $row->invoice_id ?? '' }}">
                                <input type="hidden" name="document_date" value="{{ $row->document_date ?? '' }}">
                                <div class="inline-action-row">
                                    <select name="team_id" required>
                                        <option value="">Select team</option>
                                        @foreach ($dateTeams as $team)
                                            @php
                                                $teamOptionLabel = trim((string) (($team->driver_name ?? '').' '.(!empty($team->car_number) || !empty($team->car_model) ? '('.trim(($team->car_number ?? '').' '.($team->car_model ?? '')).')' : '').' + '.($team->companion_name ?? '')));
                                            @endphp
                                            <option value="{{ (int) ($team->id ?? 0) }}" @selected((string) ($row->team_id ?? '') === (string) (int) ($team->id ?? 0))>{{ $teamOptionLabel }}</option>
                                        @endforeach
                                    </select>
                                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save team'])
                                </div>
                                @if (!empty($row->team_name))
                                    <span class="muted sub-meta">Current: {{ $row->team_name }}</span>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 10 + (!empty($filters['include_amount']) ? 1 : 0) + (!empty($filters['include_weight']) ? 1 : 0) }}" style="color:#666;">No rows match the filters.</td>
                    </tr>
                @endforelse
                </tbody>
                @if ($rows->count() > 0 && !empty($grandTotals))
                    @include('reports.partials.metric-grand-totals-tfoot', [
                        'grandTotals' => $grandTotals,
                        'labelColspan' => $colspanBeforeQty,
                        'trailingColspan' => $colspanAfterQty,
                        'showAmount' => !empty($filters['include_amount']),
                        'showWeight' => !empty($filters['include_weight']),
                    ])
                @endif
            </table>
            @include('reports.partials.pagination', ['paginator' => $rows])
        @endif
    @endif
@endsection

@push('styles')
<style>
table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-yes { background: #dcfce7; color: #166534; }
        .badge-no { background: #fee2e2; color: #991b1b; }
        .status-toggle { display: inline; }
        .status-toggle button { border: 0; cursor: pointer; }
        .status { background: #e8f7ed; color: #166534; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .subtabs { display: flex; gap: 8px; margin-bottom: 12px; }
        .subtabs a { padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 13px; background: #f1f5f9; color: #1e293b; }
        .subtabs a.active { background: #0f766e; color: #fff; }
        .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        .mini-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .sub-meta { color: #64748b; font-size: 12px; margin-top: 2px; }
        button.danger { background: #b91c1c; color: #fff; border: none; cursor: pointer; padding: 8px 12px; border-radius: 6px; }
        .setup-people-table input[type="text"] { width: 100%; min-width: 0; padding: 6px 8px; font-size: 13px; }
        .setup-people-actions {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
            width: 1%;
            vertical-align: middle;
            padding-left: 6px;
            padding-right: 6px;
        }
        .setup-people-actions__form {
            display: inline-flex;
            margin: 0;
            flex: 0 0 auto;
        }
</style>
@endpush

