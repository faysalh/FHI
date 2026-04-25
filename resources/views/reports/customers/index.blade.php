<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales &amp; customer reports</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 16px;
            background-color: #f7f7f7;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            padding: 16px;
        }
        .filters {
            display: grid;
            grid-template-columns: 1fr 1fr auto auto;
            gap: 8px;
            margin-bottom: 16px;
        }
        input, select, button {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            background: #fff;
        }
        th, td {
            border-bottom: 1px solid #ececec;
            text-align: left;
            padding: 10px;
            font-size: 14px;
        }
        .error {
            background: #ffeaea;
            color: #921d1d;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        .meta {
            margin-top: 8px;
            color: #666;
            font-size: 13px;
        }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a {
            padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333;
            background: #eee; font-size: 14px;
        }
        .tabs a.active { background: #2563eb; color: #fff; }
        @media (max-width: 720px) {
            .filters {
                grid-template-columns: 1fr;
            }
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead {
                display: none;
            }
            tr {
                border: 1px solid #ececec;
                border-radius: 6px;
                margin-bottom: 10px;
                padding: 8px;
            }
            td {
                border: 0;
                padding: 6px 0;
            }
            td::before {
                content: attr(data-label) ": ";
                font-weight: bold;
            }
        }
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
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}" class="active">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>
    <h1>Sales &amp; customer reports</h1>
    <p style="color:#666;font-size:14px;margin:0 0 12px 0;">Sample report view (customer/account discovery). For schema exploration use <a href="{{ route('reports.schema.index') }}">schema browser</a>. For sales by chicken category use <a href="{{ route('reports.sales.index') }}">Sales report</a> with <strong>Chicken category breakdown</strong>.</p>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif

    <form method="GET" action="{{ route('reports.customers.index') }}" class="filters">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search name or code">
        <input type="text" name="city" value="{{ $filters['city'] ?? '' }}" placeholder="City (if available)">
        <select name="per_page">
            @foreach ([20, 50, 100] as $size)
                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }} / page</option>
            @endforeach
        </select>
        <div>
            <button type="submit">Apply</button>
            <a href="{{ route('reports.customers.index') }}" style="margin-left: 6px;">Reset</a>
        </div>
    </form>

    @if ($rows)
        @php
            $items = $rows->items();
            $first = $items[0] ?? null;
        @endphp
        @if (count($items) === 0)
            <div>No rows found for current filters.</div>
        @else
            <table>
                <thead>
                <tr>
                    @foreach (array_keys((array) $first) as $column)
                        <th>{{ strtoupper(str_replace('_', ' ', (string) $column)) }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @foreach ($items as $row)
                    <tr>
                        @foreach ((array) $row as $column => $value)
                            <td data-label="{{ strtoupper(str_replace('_', ' ', (string) $column)) }}">{{ $value !== null && ! is_bool($value) && is_numeric($value) ? display_number($value) : (string) $value }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>

            <div style="margin-top: 12px;">
                {{ $rows->links() }}
            </div>
            <div class="meta">
                Source table: {{ $table ?? '-' }} | Total: {{ $rows->total() }}
            </div>
        @endif
    @endif
</div>
</body>
</html>
