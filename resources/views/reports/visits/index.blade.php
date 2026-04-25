<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visits report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a {
            padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333;
            background: #eee; font-size: 14px;
        }
        .tabs a.active { background: #2563eb; color: #fff; }
        .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; align-items: end; margin-bottom: 16px; }
        label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        select[multiple] { min-height: 120px; }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        .error { background: #ffeaea; color: #921d1d; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .hint { font-size: 13px; color: #666; margin: 0 0 16px 0; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; }
        .badge-yes { background: #dcfce7; color: #166534; }
        .badge-no { background: #fee2e2; color: #991b1b; }
        .exports { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .exports a {
            padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; color: #1e40af; font-size: 14px; background: #f8fafc;
        }
        .exports a:hover { background: #e0e7ff; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index') }}">Invoices</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index', request()->query()) }}" class="active">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Visits report</h1>
    <p class="hint">
        <strong>Clients</strong> and <strong>salesmen</strong> follow the <a href="{{ route('reports.identifier.index') }}">Identifier</a> rules.
        A client is <strong>visited</strong> in a period if there is at least one non-cancelled store document line with a title date in that period for that account.
        If your date range spans <strong>more than one calendar month</strong>, you get one column per month (visit in that month only). Otherwise a single <strong>Visit status</strong> column is used.
        Leave <strong>salesman</strong> empty to list all clients (with city filters applied). Leave <strong>cities</strong> unselected to include all cities.
        City is read from the first matching column on <code>dbo.tbl_accounting_accounts</code> (see <code>config/reporting.php</code> and optional <code>REPORTING_ACCOUNT_CITY_COLUMN</code> in <code>.env</code>).
    </p>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif

    <form method="get" action="{{ route('reports.visits.index') }}" class="filters">
        <div>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div>
            <label for="salesman_id">Salesman (optional)</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach ($salesmen as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? null) === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="cities">Cities (optional, multi-select)</label>
            <select id="cities" name="cities[]" multiple size="8">
                @foreach ($cityOptions as $city)
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

    @if (! $errorMessage)
        <div class="exports">
            <span style="font-size:14px;color:#555;">Exports use current filters:</span>
            <a href="{{ route('reports.visits.export.pdf', request()->query()) }}">Export PDF</a>
            <a href="{{ route('reports.visits.export.csv', request()->query()) }}">Export CSV</a>
        </div>

        <table>
            <thead>
            <tr>
                <th>Client code</th>
                <th>Client name</th>
                <th>City</th>
                <th>Salesman</th>
                @if ($multiMonth ?? false)
                    @foreach ($monthSegments ?? [] as $seg)
                        <th>{{ $seg['label'] }}</th>
                    @endforeach
                @else
                    <th>Visit status</th>
                @endif
            </tr>
            </thead>
            <tbody>
            @php
                $emptyColspan = ($multiMonth ?? false) ? (4 + count($monthSegments ?? [])) : 5;
            @endphp
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row->client_code }}</td>
                    <td>{{ $row->client_name }}</td>
                    <td>{{ $row->city }}</td>
                    <td>{{ $row->salesman_name }}</td>
                    @if ($multiMonth ?? false)
                        @foreach ($monthSegments ?? [] as $seg)
                            @php
                                $alias = $seg['sql_alias'];
                                $hit = isset($row->{$alias}) ? (int) $row->{$alias} === 1 : (isset($row->{strtolower($alias)}) ? (int) $row->{strtolower($alias)} === 1 : false);
                            @endphp
                            <td>
                                @if ($hit)
                                    <span class="badge badge-yes">Visited</span>
                                @else
                                    <span class="badge badge-no">Not visited</span>
                                @endif
                            </td>
                        @endforeach
                    @else
                        <td>
                            @if ((int) ($row->visited ?? 0) === 1)
                                <span class="badge badge-yes">Visited</span>
                            @else
                                <span class="badge badge-no">Not visited</span>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $emptyColspan }}" style="color:#666;">No rows match the filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif
</div>
</body>
</html>
