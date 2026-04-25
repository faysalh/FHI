<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales &amp; reporting schema</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 16px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            padding: 16px;
        }
        .toolbar {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 8px;
            margin-bottom: 16px;
            align-items: end;
        }
        .toolbar input[type="search"] {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
        }
        .search-hits { margin-bottom: 16px; }
        .search-hits table { font-size: 13px; }
        .search-hits td { word-break: break-word; }
        select, button {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }
        .summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }
        .panel {
            border: 1px solid #ececec;
            border-radius: 8px;
            padding: 12px;
            background: #fff;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border-bottom: 1px solid #ececec;
            padding: 8px;
            text-align: left;
            font-size: 14px;
            vertical-align: top;
        }
        .error {
            background: #ffeaea;
            color: #921d1d;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .muted {
            color: #666;
            font-size: 13px;
        }
        .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        @media (max-width: 900px) {
            .summary, .toolbar {
                grid-template-columns: 1fr;
            }
        }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a {
            padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333;
            background: #eee; font-size: 14px;
        }
        .tabs a.active { background: #2563eb; color: #fff; }
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
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}" class="active">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>
    <h1>Sales &amp; reporting schema</h1>
    <p class="muted">Read-only preview of customer, account, document, and sales-related tables (with data). With <strong>Search fields</strong>, only browsable tables that match appear in the list; column lists, field search results, and sample rows are limited to what matches the query.</p>

    @if (!empty($commonColumnNames))
        <div class="panel" style="margin-bottom: 16px;">
            <h2>Column names common to all browsable tables</h2>
            <p class="muted">Exact name intersection across {{ count($tables) }} table(s). Useful for cross-table joins or consistent filters.</p>
            <p><code>{{ implode(', ', $commonColumnNames) }}</code></p>
        </div>
    @elseif (count($tables) > 0)
        <div class="panel" style="margin-bottom: 16px;">
            <h2>Column names common to all browsable tables</h2>
            <p class="muted">No single column name appears in every table (intersection is empty). Tables use different field sets; compare per domain or join keys manually.</p>
        </div>
    @endif

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif

    <form method="GET" action="{{ route('reports.schema.index') }}" class="toolbar">
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Table</span>
            <select name="table" @if (count($tables) === 0) disabled @endif>
                @forelse ($tables as $table)
                    <option value="{{ $table['full_name'] }}" @selected(($selectedTable['full_name'] ?? null) === $table['full_name'])>
                        {{ $table['full_name'] }} ({{ $table['column_count'] }} columns, {{ $table['row_count'] }} rows)
                    </option>
                @empty
                    <option value="">No browsable table matches this search</option>
                @endforelse
            </select>
        </label>
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Search fields (dbo)</span>
            <input type="search" name="q" value="{{ $searchQueryInput ?? '' }}" placeholder="e.g. sales_man, fld_account_id_ref" autocomplete="off">
        </label>
        <label style="margin:0;">
            <span class="muted" style="display:block;margin-bottom:4px;font-weight:600;">Sample size</span>
            <select name="per_page">
                @foreach ([10, 20, 50] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 10) === $size)>{{ $size }} rows</option>
                @endforeach
            </select>
        </label>
        <button type="submit">Load / search</button>
    </form>

    @if (strlen(trim($searchQueryInput ?? '')) > 0 && !empty($searchHits))
        <div class="panel search-hits">
            <h2>Field search results</h2>
            <p class="muted">Query: <strong>{{ $searchQueryInput }}</strong> — only matching <code>dbo</code> columns/tables (up to 500). Browsable tables in the dropdown are restricted to these matches when the search returns results.</p>
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>Table</th>
                        <th>Column</th>
                        <th>Type</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($searchHits as $hit)
                        <tr>
                            <td><code>{{ $hit['full_name'] }}</code></td>
                            <td><code>{{ $hit['column'] }}</code></td>
                            <td>{{ $hit['data_type'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @elseif (strlen(trim($searchQueryInput ?? '')) > 0)
        <div class="panel search-hits muted">No columns or tables matched your search (<strong>{{ $searchQueryInput }}</strong>) in <code>dbo</code>. Try shorter words or one term at a time.</div>
    @endif

    @if (strlen(trim($searchQueryInput ?? '')) > 0 && !empty($searchHits) && count($tables) === 0)
        <div class="panel muted" style="margin-bottom:16px;">
            <strong>No browsable table</strong> matched this search (matches may only exist on other <code>dbo</code> tables in the list above). Clear the search box and submit to restore the full browsable table list.
        </div>
    @endif

    @if ($selectedTable)
        <div class="summary">
            <div class="panel">
                <h2>Selected Table</h2>
                <div><strong>Schema:</strong> {{ $selectedTable['schema'] }}</div>
                <div><strong>Table:</strong> {{ $selectedTable['table'] }}</div>
                <div><strong>Full name:</strong> {{ $selectedTable['full_name'] }}</div>
                <div><strong>Browsable tables (shown in list):</strong> {{ count($tables) }}</div>
                <div><strong>Column count:</strong> {{ count($columns) }}@if (strlen(trim($searchQueryInput ?? '')) > 0) <span class="muted">(matching search)</span>@endif</div>
                <div><strong>Sample rows:</strong> {{ $rows?->total() ?? 0 }}@if (strlen(trim($searchQueryInput ?? '')) > 0) <span class="muted">(matching search in text columns)</span>@endif</div>
            </div>

            <div class="panel">
                <h2>Columns</h2>
                @if ($columns === [] && strlen(trim($searchQueryInput ?? '')) > 0)
                    <p class="muted">No column names contain all search terms. Clear the search or try different words.</p>
                @else
                <table>
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Nullable</th>
                        <th>Max Length</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($columns as $column)
                        <tr>
                            <td>{{ $column['name'] }}</td>
                            <td>{{ $column['data_type'] }}</td>
                            <td>{{ $column['is_nullable'] }}</td>
                            <td>{{ $column['max_length'] ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>

        <div class="panel">
            <h2>Sample Data @if (strlen(trim($searchQueryInput ?? '')) > 0)<span class="muted">(filtered)</span>@endif</h2>
            @php
                $items = $rows?->items() ?? [];
                $first = $items[0] ?? null;
            @endphp

            @if ($items === [])
                <div>No rows returned for this table.</div>
            @else
                <table>
                    <thead>
                    <tr>
                        @foreach (array_keys((array) $first) as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($items as $row)
                        <tr>
                            @foreach ((array) $row as $value)
                                <td>@if ($value !== null && ! is_bool($value) && is_numeric($value))
                                    {{ display_number($value) }}
                                @elseif (is_scalar($value) || $value === null)
                                    {{ (string) $value }}
                                @else
                                    {{ json_encode($value) }}
                                @endif</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                <div style="margin-top: 12px;">
                    {{ $rows?->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
</body>
</html>
