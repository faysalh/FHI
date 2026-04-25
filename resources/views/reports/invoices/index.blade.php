<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 16px; background: #f5f5f5; }
        .container { max-width: 1300px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 16px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; border-bottom: 1px solid #ddd; padding-bottom: 8px; }
        .tabs a { padding: 8px 14px; border-radius: 6px 6px 0 0; text-decoration: none; color: #333; background: #eee; font-size: 14px; }
        .tabs a.active { background: #2563eb; color: #fff; }
        .filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; align-items: end; margin-bottom: 12px; }
        label { font-size: 13px; color: #555; display: block; margin-bottom: 4px; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        select[multiple] { min-height: 100px; }
        button { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        .error { background: #ffeaea; color: #921d1d; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .hint { font-size: 13px; color: #666; margin-bottom: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; vertical-align: top; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .meta { color: #64748b; font-size: 12px; }
        .details-btn { background: #0f766e; color: #fff; border: 0; border-radius: 6px; padding: 6px 10px; cursor: pointer; font-size: 12px; }
        .action-links { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .action-links a {
            background: #1d4ed8; color: #fff; border-radius: 6px; padding: 6px 10px; text-decoration: none; font-size: 12px;
        }
        .details-row td { background: #f8fafc; }
        .details-card { padding: 10px; }
        .details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-bottom: 8px; }
        .detail-key { color: #64748b; font-size: 12px; }
        .detail-val { font-size: 13px; }
        .mini-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 13px; }
        .mini-table th, .mini-table td { border: 1px solid #dbe5ef; padding: 6px; }
    </style>
</head>
<body>
<div class="container">
    <nav class="tabs">
        <a href="{{ route('reports.sales.index') }}">Sales report</a>
        <a href="{{ route('reports.sales-item-average.index') }}">Sales by item average</a>
        <a href="{{ route('reports.deliveries.index') }}">Deliveries</a>
        <a href="{{ route('reports.invoices.index', request()->query()) }}" class="active">Invoices</a>
        <a href="{{ route('reports.invoice-branding.index') }}">Invoice branding</a>
        <a href="{{ route('reports.cities.index') }}">Cities</a>
        <a href="{{ route('reports.visits.index') }}">Visits</a>
        <a href="{{ route('reports.schema.index') }}">Schema</a>
        <a href="{{ route('reports.customers.index') }}">Sample accounts</a>
        <a href="{{ route('reports.identifier.index') }}">Identifier</a>
    </nav>

    <h1>Invoices report</h1>
    <p class="hint">Filter invoices by store, date, city, salesman, and text. Expand a row to inspect invoice/client/salesman metadata and invoice line items.</p>

    @if ($errorMessage)
        <div class="error">{{ $errorMessage }}</div>
    @endif

    <form method="GET" action="{{ route('reports.invoices.index') }}" class="filters">
        <div>
            <label for="date_from">From</label>
            <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] }}">
        </div>
        <div>
            <label for="date_to">To</label>
            <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] }}">
        </div>
        <div>
            <label for="store">Store (optional)</label>
            <select id="store" name="store">
                <option value="">All stores</option>
                @foreach (($storeOptions ?? []) as $st)
                    <option value="{{ $st }}" @selected(($filters['store'] ?? '') === $st)>{{ $st }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="salesman_id">Salesman (optional)</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach (($salesmen ?? []) as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === $sm['id'])>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="q">Text search (optional)</label>
            <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="invoice/client/store/salesman">
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
        <div><button type="submit">Apply</button></div>
    </form>

    @if ($rows)
        <table id="invoices-table">
            <thead>
            <tr>
                <th>Invoice no</th>
                <th>Date</th>
                <th>Client</th>
                <th>Salesman</th>
                <th>Store</th>
                <th class="num">Quantity (pcs)</th>
                <th class="num">Invoice amount (IQD)</th>
                <th class="num">Client due (IQD)</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="invoice-row" data-invoice-id="{{ $row->invoice_id }}">
                    <td>{{ $row->invoice_no }}</td>
                    <td>
                        <div>{{ $row->invoice_date }}</div>
                        <div class="meta">{{ $row->created_at }}</div>
                    </td>
                    <td>
                        <div>{{ $row->client_name }}</div>
                        <div class="meta">{{ $row->client_code }} | {{ $row->city_name }}</div>
                    </td>
                    <td>{{ $row->salesman_name }}</td>
                    <td>{{ $row->store_name }}</td>
                    <td class="num">{{ display_number((float) ($row->quantity_total ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->invoice_amount ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->client_due_amount ?? 0)) }}</td>
                    <td>
                        <div class="action-links">
                            <button type="button" class="details-btn">View invoice</button>
                            <a href="{{ route('reports.invoices.print', ['invoice_id' => $row->invoice_id]) }}" target="_blank" rel="noopener">Print</a>
                            <a href="{{ route('reports.invoices.export.pdf', ['invoice_id' => $row->invoice_id]) }}">Export PDF</a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="meta">No rows match the filters.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div style="margin-top: 12px;">{{ $rows->links() }}</div>
    @endif
</div>

<script>
(function () {
    var table = document.getElementById('invoices-table');
    if (!table) return;
    var endpoint = @json(route('reports.invoices.items'));
    var openRow = null;

    function fmt(n) {
        if (n === null || n === undefined || isNaN(n)) return '0';
        return new Intl.NumberFormat('en-US', { maximumFractionDigits: 6 }).format(Number(n));
    }
    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function buildItemsTable(rows) {
        var html = '<table class="mini-table"><thead><tr><th>Item</th><th class="num">Quantity (pcs)</th><th class="num">Amount (IQD)</th></tr></thead><tbody>';
        if (!rows.length) {
            html += '<tr><td colspan="3" class="meta">No item rows.</td></tr>';
        } else {
            rows.forEach(function (r) {
                html += '<tr><td>' + esc(r.item_name) + '</td><td class="num">' + fmt(r.quantity) + '</td><td class="num">' + fmt(r.amount) + '</td></tr>';
            });
        }
        html += '</tbody></table>';
        return html;
    }

    table.querySelectorAll('.invoice-row .details-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = btn.closest('.invoice-row');
            if (!row) return;
            var invoiceId = row.getAttribute('data-invoice-id') || '';
            if (!invoiceId) return;

            if (openRow && openRow.previousElementSibling === row) {
                openRow.remove();
                openRow = null;
                return;
            }
            if (openRow) {
                openRow.remove();
                openRow = null;
            }

            var holder = document.createElement('tr');
            holder.className = 'details-row';
            var td = document.createElement('td');
            td.colSpan = 9;
            td.innerHTML = '<div class="details-card"><div class="meta">Loading invoice details...</div></div>';
            holder.appendChild(td);
            row.insertAdjacentElement('afterend', holder);
            openRow = holder;

            fetch(endpoint + '?invoice_id=' + encodeURIComponent(invoiceId), { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        td.innerHTML = '<div class="details-card"><div class="meta">Could not load invoice details.</div></div>';
                        return;
                    }
                    td.innerHTML = '<div class="details-card">' + buildItemsTable(data.rows || []) + '</div>';
                })
                .catch(function () {
                    td.innerHTML = '<div class="details-card"><div class="meta">Could not load invoice details.</div></div>';
                });
        });
    });
})();
</script>
</body>
</html>

