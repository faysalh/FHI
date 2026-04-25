<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deliveries report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1280px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a { padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333; background: #eee; font-size: 14px; }
        .tabs a.active { background: #2563eb; color: #fff; }
        .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; align-items: end; margin-bottom: 12px; }
        label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        .error { background: #ffeaea; color: #921d1d; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .hint { font-size: 13px; color: #666; margin-bottom: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-yes { background: #dcfce7; color: #166534; }
        .badge-no { background: #fee2e2; color: #991b1b; }
        .export-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .export-row a { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; color: #1e40af; font-size: 14px; background: #f8fafc; }
        .status { background: #e8f7ed; color: #166534; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .subtabs { display: flex; gap: 8px; margin-bottom: 12px; }
        .subtabs a { padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 13px; background: #f1f5f9; color: #1e293b; }
        .subtabs a.active { background: #0f766e; color: #fff; }
        .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        .mini-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; }
        .muted { color: #64748b; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index', request()->query()) }}" class="active">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Deliveries report</h1>
    <div class="subtabs">
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'report'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'report' ? 'active' : '' }}">Report</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'setup'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'setup' ? 'active' : '' }}">Setup drivers & companions</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'daily-teams'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'daily-teams' ? 'active' : '' }}">Setup daily teams</a>
        <a href="{{ route('reports.deliveries.index', array_merge(request()->query(), ['tab' => 'batch-assignment'])) }}" class="{{ ($filters['tab'] ?? 'report') === 'batch-assignment' ? 'active' : '' }}">Batch assignment</a>
    </div>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif
    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    @if (($filters['tab'] ?? 'report') === 'setup')
        <p class="hint">Save drivers (with car details) and companions in local SQLite tables.</p>

        <div class="card">
            <h3 style="margin:0 0 10px 0;">Add driver</h3>
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
                <div>
                    <button type="submit">Save driver</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 10px 0;">Add companion</h3>
            <form method="POST" action="{{ route('reports.deliveries.setup.companion', request()->query()) }}" class="mini-grid">
                @csrf
                <div>
                    <label for="companion_name">Companion name</label>
                    <input type="text" id="companion_name" name="companion_name" required>
                </div>
                <div>
                    <button type="submit">Save companion</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 8px 0;">Drivers</h3>
            <table>
                <thead><tr><th>Name</th><th>Car number</th><th>Car model</th></tr></thead>
                <tbody>
                @forelse (($drivers ?? []) as $driver)
                    <tr>
                        <td>{{ $driver->full_name ?? '' }}</td>
                        <td>{{ $driver->car_number ?? '' }}</td>
                        <td>{{ $driver->car_model ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No drivers saved yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3 style="margin:0 0 8px 0;">Companions</h3>
            <table>
                <thead><tr><th>Name</th></tr></thead>
                <tbody>
                @forelse (($companions ?? []) as $companion)
                    <tr><td>{{ $companion->full_name ?? '' }}</td></tr>
                @empty
                    <tr><td class="muted">No companions saved yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif (($filters['tab'] ?? 'report') === 'daily-teams')
        <p class="hint">Create team combinations for a specific day, then use them in the report tab.</p>

        <div class="card">
            <h3 style="margin:0 0 10px 0;">Add daily team</h3>
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
                <div>
                    <button type="submit">Save daily team</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3 style="margin:0 0 8px 0;">Teams for {{ $filters['team_date'] ?? '' }}</h3>
            <table>
                <thead><tr><th>Driver</th><th>Car</th><th>Companion</th></tr></thead>
                <tbody>
                @forelse (($teamsForSetupDate ?? []) as $team)
                    <tr>
                        <td>{{ $team->driver_name ?? '' }}</td>
                        <td>{{ trim((string) (($team->car_number ?? '').' '.($team->car_model ?? ''))) }}</td>
                        <td>{{ $team->companion_name ?? '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No teams for selected date.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif (($filters['tab'] ?? 'report') === 'batch-assignment')
        <p class="hint">
            Upload a PDF containing invoice numbers, choose a team, and all matched invoices in the selected date range
            will be assigned to that team in local SQLite.
        </p>

        <div class="card">
            <h3 style="margin:0 0 10px 0;">Batch assignment from PDF</h3>
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
                <div>
                    <button type="submit">Run batch assignment</button>
                </div>
            </form>
        </div>

        @if (is_array($batchResult ?? null))
            <div class="card">
                <h3 style="margin:0 0 10px 0;">Last batch result</h3>
                <table>
                    <tbody>
                    <tr><td>Extracted invoice-like values</td><td class="num">{{ (int) ($batchResult['extracted_count'] ?? 0) }}</td></tr>
                    <tr><td>Matched invoices in report DB</td><td class="num">{{ (int) ($batchResult['matched_count'] ?? 0) }}</td></tr>
                    <tr><td>Assigned invoices</td><td class="num">{{ (int) ($batchResult['assigned_count'] ?? 0) }}</td></tr>
                    <tr><td>Unmatched values</td><td class="num">{{ (int) ($batchResult['unmatched_count'] ?? 0) }}</td></tr>
                    </tbody>
                </table>
            </div>
        @endif
    @else
        <p class="hint">
            Delivery status is read from <code>dbo.tbl_store_document_detail.fld_is_delivered</code>.
            If value = <strong>1</strong> then status is <strong>Delivered</strong>, otherwise <strong>Not delivered</strong>.
            Team assignment is stored in local SQLite and does not touch the main DB.
        </p>

        <form method="GET" action="{{ route('reports.deliveries.index') }}" class="filters">
            <input type="hidden" name="tab" value="report">
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
            <div style="grid-column: 1 / -1; display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <label class="muted" style="display:flex;gap:8px;align-items:center;margin:0;">
                    <input type="checkbox" name="include_amount" value="1" @checked(!empty($filters['include_amount']))>
                    Show amount
                </label>
                <label class="muted" style="display:flex;gap:8px;align-items:center;margin:0;">
                    <input type="checkbox" name="include_weight" value="1" @checked(!empty($filters['include_weight']))>
                    Show weight
                </label>
            </div>
            <div>
                <label for="cities">Cities (optional, multi-select)</label>
                <select id="cities" name="cities[]" multiple size="6">
                    @foreach (($cityOptions ?? []) as $city)
                        <option value="{{ $city }}" @selected(in_array($city, $filters['cities'] ?? [], true))>{{ $city }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="per_page">Rows per page</label>
                <select id="per_page" name="per_page">
                    @foreach ([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ $size }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <button type="submit">Apply</button>
            </div>
        </form>

        @if (! $errorMessage && $rows)
            <div class="export-row">
                <span style="font-size:14px;color:#555;">Exports use current filters:</span>
                <a href="{{ route('reports.deliveries.export.pdf', request()->query()) }}">Export PDF</a>
                <a href="{{ route('reports.deliveries.export.csv', request()->query()) }}">Export CSV</a>
            </div>

            <table>
                <thead>
                <tr>
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
                @forelse ($rows as $row)
                    @php
                        $delivered = strtolower((string) ($row->delivery_status ?? '')) === 'delivered';
                        $rowDate = (string) ($row->document_date ?? '');
                        $dateTeams = $teamsByDate[$rowDate] ?? [];
                    @endphp
                    <tr>
                        <td>{{ $row->invoice_no ?? $row->invoice_id ?? '' }}</td>
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
                            <span class="badge {{ $delivered ? 'badge-yes' : 'badge-no' }}">
                                {{ $delivered ? 'Delivered' : 'Not delivered' }}
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="{{ route('reports.deliveries.assign-team', request()->query()) }}" style="display:grid;gap:6px;">
                                @csrf
                                <input type="hidden" name="invoice_id" value="{{ $row->invoice_id ?? '' }}">
                                <input type="hidden" name="document_date" value="{{ $row->document_date ?? '' }}">
                                <select name="team_id" required>
                                    <option value="">Select team</option>
                                    @foreach ($dateTeams as $team)
                                        @php
                                            $label = trim((string) (($team->driver_name ?? '').' '.(!empty($team->car_number) || !empty($team->car_model) ? '('.trim(($team->car_number ?? '').' '.($team->car_model ?? '')).')' : '').' + '.($team->companion_name ?? '')));
                                        @endphp
                                        <option value="{{ (int) ($team->id ?? 0) }}" @selected((string) ($row->team_id ?? '') === (string) (int) ($team->id ?? 0))>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" style="padding:6px 8px;">Save team</button>
                                @if (!empty($row->team_name))
                                    <span class="muted">Current: {{ $row->team_name }}</span>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ 9 + (!empty($filters['include_amount']) ? 1 : 0) + (!empty($filters['include_weight']) ? 1 : 0) }}" style="color:#666;">No rows match the filters.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <div style="margin-top: 12px;">{{ $rows->links() }}</div>
        @endif
    @endif
</div>
</body>
</html>

