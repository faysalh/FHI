@extends('reports.layouts.app')
@section('title', 'Damages')

@section('content')
<header class="page-header"><h1>Damaged goods</h1></header>

    @if (! $catalogAvailable)
        <div class="warn">The main reporting connection is not SQL Server right now — item and client search, prices, and verification on save will not work in this environment.</div>
    @endif

    <nav class="sub-tabs" aria-label="Damages sections">
        <a href="{{ route('reports.damages.index', array_merge(request()->except('tab'), ['tab' => 'damages'])) }}" class="{{ $tab === 'damages' ? 'active' : '' }}">Damages list</a>
        <a href="{{ route('reports.damages.index', array_merge(request()->except('tab'), ['tab' => 'packaging'])) }}" class="{{ $tab === 'packaging' ? 'active' : '' }}">Packaging</a>
    </nav>

    <p class="lab-desc">Record damaged goods locally (SQLite). Packaging rules define pieces per carton for amount calculation. Use <strong>How to use this page</strong> for pricing rules and examples.</p>

    <form method="get" action="{{ route('reports.damages.index') }}" id="filter-form">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <details class="filters-panel" open>
            <summary>Filters</summary>
                <div class="filters-body">
                    @include('reports.partials.quick-date-buttons')
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
            <label for="client_q">Client contains</label>
            <input type="text" id="client_q" name="client_q" value="{{ $filters['client_q'] }}" placeholder="Name filter">
        </div>
        <div>
            <label for="item_q">Item contains</label>
            <input type="text" id="item_q" name="item_q" value="{{ $filters['item_q'] }}" placeholder="Item name filter">
        </div>
        <div>
            <label for="salesman_id">Salesman</label>
            <select id="salesman_id" name="salesman_id">
                <option value="">All salesmen</option>
                @foreach ($salesmen as $sm)
                    <option value="{{ $sm['id'] }}" @selected(($filters['salesman_id'] ?? '') === (string) ($sm['id'] ?? ''))>{{ $sm['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="per_page">Rows per page</label>
            <select id="per_page" name="per_page">
                @foreach ([10, 25, 50, 100, 250] as $size)
                    <option value="{{ $size }}" @selected((int) $filters['per_page'] === $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
                </div>
                <div class="filters-actions">
                    @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                    @include('reports.partials.filters-reset-link', ['route' => 'reports.damages.index', 'params' => ['tab' => $tab]])
                    @if ($tab === 'damages')
                        <span class="muted">Export:</span>
                        <a href="#" class="damages-export-link export-link" data-export-base="{{ route('reports.damages.export.csv') }}">CSV</a>
                        <a href="#" class="damages-export-link export-link" data-export-base="{{ route('reports.damages.export.pdf') }}">PDF</a>
                    @endif
                </div>
            </div>
        </details>
    </form>

    @if ($tab === 'damages')
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Client</th>
                <th>Salesman</th>
                <th>Item</th>
                <th class="num">Damaged pieces</th>
                <th class="num">Pieces / carton</th>
                <th class="num">Carton price</th>
                <th class="num">Amount</th>
                <th>Notes</th>
                <th style="width:1%;white-space:nowrap;"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($entries as $row)
                <tr>
                    <td>{{ ($entries->currentPage() - 1) * $entries->perPage() + $loop->iteration }}</td>
                    <td>{{ $row->occurred_date }}</td>
                    <td>{{ $row->client_name_snapshot }}</td>
                    <td>{{ $row->salesman_name_snapshot ?? '—' }}</td>
                    <td>{{ $row->item_name_snapshot }}</td>
                    <td class="num">{{ display_number((int) ($row->damaged_pieces ?? 0)) }}</td>
                    <td class="num">{{ display_number((int) ($row->pieces_per_main_unit ?? 1)) }}</td>
                    <td class="num">{{ display_number((float) ($row->carton_price ?? 0)) }}</td>
                    <td class="num">{{ display_number((float) ($row->amount_total ?? 0)) }}</td>
                    <td>{{ $row->notes }}</td>
                    <td>
                        <form method="post" action="{{ route('reports.damages.entries.delete') }}" onsubmit="return confirm('Delete this damage entry from local storage?');" style="margin:0;">
                            @csrf
                            <input type="hidden" name="id" value="{{ $row->id }}">
                            @foreach (['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page', 'tab', 'page'] as $k)
                                <input type="hidden" name="{{ $k }}" value="{{ request($k) }}">
                            @endforeach
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete damage entry'])
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="color:#666;">No local damage entries for these filters.</td></tr>
            @endforelse
            </tbody>
            @if ($entries->count() > 0)
            <tfoot>
            <tr>
                <td colspan="5" class="num" style="text-align:right;font-weight:600;background:#f1f5f9;">This page</td>
                <td class="num" style="font-weight:600;background:#f1f5f9;">{{ display_number($sumPiecesPage) }}</td>
                <td style="background:#f1f5f9;"></td>
                <td style="background:#f1f5f9;"></td>
                <td class="num" style="font-weight:600;background:#f1f5f9;">{{ display_number($sumAmountPage) }}</td>
                <td style="background:#f1f5f9;"></td>
                <td style="background:#f1f5f9;"></td>
            </tr>
            <tr>
                <td colspan="5" class="num" style="text-align:right;font-weight:700;background:#e2e8f0;">All matching filters</td>
                <td class="num" style="font-weight:700;background:#e2e8f0;">{{ display_number($sumPiecesAll) }}</td>
                <td style="background:#e2e8f0;"></td>
                <td style="background:#e2e8f0;"></td>
                <td class="num" style="font-weight:700;background:#e2e8f0;">{{ display_number($sumAmountAll) }}</td>
                <td style="background:#e2e8f0;"></td>
                <td style="background:#e2e8f0;"></td>
            </tr>
            </tfoot>
            @endif
        </table>
        <div style="margin-top: 12px;">{{ $entries->links() }}</div>

        <div class="card" id="add-damage">
            <h2>Add damaged goods</h2>
            <form method="post" action="{{ route('reports.damages.entries.store') }}">
                @csrf
                @foreach (['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page', 'tab'] as $k)
                    <input type="hidden" name="{{ $k }}" value="{{ request($k) }}">
                @endforeach
                <div class="filters" style="margin-bottom: 0;">
                    <div>
                        <label for="occurred_date">Damage date</label>
                        <input type="date" id="occurred_date" name="occurred_date" value="{{ old('occurred_date', $filters['date_to']) }}" required>
                    </div>
                    <div style="grid-column: span 2;">
                        <label>Item (search main DB)</label>
                        <input type="text" id="dmg_item_search" placeholder="Type to search…" autocomplete="off" value="{{ old('dmg_item_search') }}">
                        <input type="hidden" name="main_item_id" id="dmg_main_item_id" value="{{ old('main_item_id') }}" required>
                        <div class="suggest" id="dmg_item_suggest"></div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;" id="dmg_item_label"></div>
                    </div>
                    <div style="grid-column: span 2;">
                        <label>Client (search main DB)</label>
                        <input type="text" id="dmg_client_search" placeholder="Type to search…" autocomplete="off" value="{{ old('dmg_client_search') }}">
                        <input type="hidden" name="client_account_id" id="dmg_client_account_id" value="{{ old('client_account_id') }}" required>
                        <div class="suggest" id="dmg_client_suggest"></div>
                        <div style="font-size:12px;color:#64748b;margin-top:4px;" id="dmg_client_label"></div>
                    </div>
                    <div style="grid-column: span 2;">
                        <label for="entry_salesman_id">Salesman (optional)</label>
                        <select name="salesman_id" id="entry_salesman_id">
                            <option value="">Use client’s salesman from main DB</option>
                            @foreach ($salesmen as $sm)
                                <option value="{{ $sm['id'] }}" @selected(old('salesman_id') === (string) ($sm['id'] ?? ''))>{{ $sm['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="damaged_pieces">Damaged pieces count</label>
                        <input type="number" min="1" step="1" name="damaged_pieces" id="damaged_pieces" value="{{ old('damaged_pieces', '1') }}" required>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="notes">Notes (optional)</label>
                        <textarea name="notes" id="notes">{{ old('notes') }}</textarea>
                    </div>
                    <div>
                        @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save damage entry'])
                    </div>
                </div>
            </form>
        </div>
    @endif

    @if ($tab === 'packaging')
        <div class="card">
            <h2>Add or update packaging</h2>
            <form method="post" action="{{ route('reports.damages.packaging.store') }}">
                @csrf
                @foreach (['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page', 'tab'] as $k)
                    <input type="hidden" name="{{ $k }}" value="{{ request($k) }}">
                @endforeach
                <div class="filters">
                    <div style="grid-column: span 2;">
                        <label>Item (search)</label>
                        <input type="text" id="pkg_item_search" placeholder="Type item name or code…" autocomplete="off">
                        <input type="hidden" name="main_item_id" id="pkg_main_item_id" required>
                        <input type="hidden" name="item_name" id="pkg_item_name" required>
                        <div class="suggest" id="pkg_item_suggest"></div>
                    </div>
                    <div>
                        <label for="pieces_per_main_unit">Pieces per carton / main unit</label>
                        <input type="number" min="1" step="1" name="pieces_per_main_unit" id="pieces_per_main_unit" value="1" required>
                    </div>
                    <div>
                        @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save packaging'])
                    </div>
                </div>
            </form>
        </div>

        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Item name (snapshot)</th>
                <th class="num">Pieces per main unit</th>
                <th>Main item id</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($packaging as $p)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $p->item_name }}</td>
                    <td class="num">{{ display_number((int) ($p->pieces_per_main_unit ?? 1)) }}</td>
                    <td style="font-size:12px;color:#64748b;">{{ $p->main_item_id }}</td>
                    <td>
                        <form method="post" action="{{ route('reports.damages.packaging.delete') }}" onsubmit="return confirm('Delete this packaging rule?');" style="display:inline;">
                            @csrf
                            <input type="hidden" name="id" value="{{ $p->id }}">
                            @foreach (['date_from', 'date_to', 'client_q', 'item_q', 'salesman_id', 'per_page', 'tab'] as $k)
                                <input type="hidden" name="{{ $k }}" value="{{ request($k) }}">
                            @endforeach
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete packaging rule'])
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" style="color:#666;">No packaging rules yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
</div>
<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const routes = {
        items: @json(route('reports.damages.api.items')),
        clients: @json(route('reports.damages.api.clients')),
    };

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }

    function wireItemSuggest(searchInput, suggestEl, hiddenId, hiddenName, asOfInput, labelEl) {
        const run = debounce(function () {
            const q = searchInput.value.trim();
            const asOf = asOfInput ? asOfInput.value : '';
            if (q.length < 2) {
                suggestEl.style.display = 'none';
                suggestEl.innerHTML = '';
                return;
            }
            fetch(routes.items + '?q=' + encodeURIComponent(q) + '&as_of=' + encodeURIComponent(asOf || ''), {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            }).then(r => r.json()).then(data => {
                suggestEl.innerHTML = '';
                if (!data.ok || !data.rows || !data.rows.length) {
                    suggestEl.style.display = 'none';
                    return;
                }
                data.rows.forEach(row => {
                    const div = document.createElement('div');
                    div.textContent = row.item_name + (row.item_code ? ' — ' + row.item_code : '');
                    div.addEventListener('click', () => {
                        hiddenId.value = row.item_id;
                        if (hiddenName) hiddenName.value = row.item_name;
                        searchInput.value = row.item_name;
                        if (labelEl) labelEl.textContent = 'Selected: ' + row.item_name + (row.item_code ? ' (' + row.item_code + ')' : '');
                        suggestEl.style.display = 'none';
                    });
                    suggestEl.appendChild(div);
                });
                suggestEl.style.display = 'block';
            }).catch(() => { suggestEl.style.display = 'none'; });
        }, 300);
        searchInput.addEventListener('input', run);
        document.addEventListener('click', (e) => {
            if (!suggestEl.contains(e.target) && e.target !== searchInput) suggestEl.style.display = 'none';
        });
    }

    function wireClientSuggest(searchInput, suggestEl, hiddenId, labelEl) {
        const run = debounce(function () {
            const q = searchInput.value.trim();
            if (q.length < 2) {
                suggestEl.style.display = 'none';
                suggestEl.innerHTML = '';
                return;
            }
            fetch(routes.clients + '?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            }).then(r => r.json()).then(data => {
                suggestEl.innerHTML = '';
                if (!data.ok || !data.rows || !data.rows.length) {
                    suggestEl.style.display = 'none';
                    return;
                }
                data.rows.forEach(row => {
                    const div = document.createElement('div');
                    div.textContent = (row.client_code ? row.client_code + ' — ' : '') + row.client_name;
                    div.addEventListener('click', () => {
                        hiddenId.value = row.account_id;
                        searchInput.value = row.client_name;
                        if (labelEl) labelEl.textContent = 'Selected: ' + row.client_name + (row.client_code ? ' (' + row.client_code + ')' : '');
                        suggestEl.style.display = 'none';
                    });
                    suggestEl.appendChild(div);
                });
                suggestEl.style.display = 'block';
            }).catch(() => { suggestEl.style.display = 'none'; });
        }, 300);
        searchInput.addEventListener('input', run);
        document.addEventListener('click', (e) => {
            if (!suggestEl.contains(e.target) && e.target !== searchInput) suggestEl.style.display = 'none';
        });
    }

    const occurred = document.getElementById('occurred_date');
    if (document.getElementById('dmg_item_search')) {
        wireItemSuggest(
            document.getElementById('dmg_item_search'),
            document.getElementById('dmg_item_suggest'),
            document.getElementById('dmg_main_item_id'),
            null,
            occurred,
            document.getElementById('dmg_item_label')
        );
    }
    if (document.getElementById('dmg_client_search')) {
        wireClientSuggest(
            document.getElementById('dmg_client_search'),
            document.getElementById('dmg_client_suggest'),
            document.getElementById('dmg_client_account_id'),
            document.getElementById('dmg_client_label')
        );
    }
    if (document.getElementById('pkg_item_search')) {
        wireItemSuggest(
            document.getElementById('pkg_item_search'),
            document.getElementById('pkg_item_suggest'),
            document.getElementById('pkg_main_item_id'),
            document.getElementById('pkg_item_name'),
            document.getElementById('date_to'),
            null
        );
    }
})();
</script>
@include('reports.partials.quick-date-buttons-script', ['formId' => 'filter-form'])
@include('reports.partials.export-from-form-script', ['formId' => 'filter-form', 'linkClass' => 'damages-export-link'])
@endsection

@push('styles')
<style>
.sub-tabs { display: flex; gap: 8px; margin-bottom: 14px; }
        .sub-tabs a { padding: 6px 12px; border-radius: 6px; text-decoration: none; background: #e2e8f0; color: #1e293b; font-size: 14px; }
        .sub-tabs a.active { background: #0f172a; color: #fff; }
        input, select, button, textarea { border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        button.primary { background: #2563eb; color: #fff; border: none; cursor: pointer; }
        button.danger { background: #b91c1c; color: #fff; border: none; cursor: pointer; }
        .status { background: #ecfdf5; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .warn { background: #fffbeb; color: #92400e; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #ececec; padding: 8px; text-align: left; }
        th { background: #f9fafb; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
        .card h2 { margin: 0 0 10px 0; font-size: 16px; }
        .suggest { border: 1px solid #e5e7eb; border-radius: 6px; max-height: 200px; overflow: auto; margin-top: 4px; display: none; background: #fff; }
        .suggest div { padding: 6px 8px; cursor: pointer; font-size: 13px; border-bottom: 1px solid #f1f5f9; }
        .suggest div:hover { background: #eff6ff; }
        textarea { width: 100%; box-sizing: border-box; min-height: 56px; }
</style>
@endpush

