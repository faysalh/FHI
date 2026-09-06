@extends('reports.layouts.app')
@section('title', 'Storage quantity')
@section('container-class', 'report-container--wide')

@section('content')
@php
    $storageAccess = $storageAccess ?? \App\Support\StorageReportAccess::full();
    $isAdv = ($filters['balance_mode'] ?? 'normal') === 'adv';
@endphp
<header class="page-header"><h1>Storage quantity</h1></header>
<p class="hint">Item balances from SQL Server stored procedures. <strong>Normal</strong> uses <code>SP_Get_Item_Balance</code> (Balance + In store). <strong>Adv</strong> uses <code>SP_Get_Item_Balance_Adv</code> with an as-of date/time. Base unit (scale = 1) per item. Read-only.</p>

@if ($errorMessage ?? null)
    <div class="alert alert--error">{{ $errorMessage }}</div>
@endif

<form id="storage-quantity-filter-form" method="GET" action="{{ route('reports.storage-quantity.index') }}">
    <details class="filters-panel" open>
        <summary>Filters</summary>
        <div class="filters-body">
            <div class="filters-grid">
                <div class="span-2">
                    <span class="filter-group-label">Balance mode</span>
                    <div class="column-toggles">
                        <label class="column-toggle">
                            <input type="radio" name="balance_mode" value="normal" @checked(($filters['balance_mode'] ?? 'normal') === 'normal')>
                            Normal (SP_Get_Item_Balance)
                        </label>
                        <label class="column-toggle">
                            <input type="radio" name="balance_mode" value="adv" @checked(($filters['balance_mode'] ?? 'normal') === 'adv')>
                            Adv (SP_Get_Item_Balance_Adv)
                        </label>
                    </div>
                </div>
                <div>
                    <label for="year_id">Year</label>
                    <select id="year_id" name="year_id" required>
                        @foreach (($yearOptions ?? []) as $year)
                            <option value="{{ $year->year_id ?? '' }}" @selected((string) ($filters['year_id'] ?? '') === (string) ($year->year_id ?? ''))>
                                {{ $year->year_name ?? '' }}@if ((int) ($year->is_current ?? 0) === 1) (current) @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="per_page">Rows / page</label>
                    <select id="per_page" name="per_page">
                        @foreach ([10, 25, 50, 100, 250] as $size)
                            <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 250) === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label>Storage</label>
                    @if ($storageAccess->canFilterStorage)
                        <div class="multi-picker" id="picker-storages" data-input-name="storages[]" data-placeholder="Search storage…"></div>
                        @if ($storageAccess->isRestricted())
                            <p class="muted" style="margin:4px 0 0;">Showing only storages assigned to your account.</p>
                        @endif
                    @elseif ($storageAccess->isRestricted())
                        <div class="muted">{{ ($filters['storages'] ?? []) !== [] ? implode(', ', $filters['storages']) : 'None' }}</div>
                        @foreach ($filters['storages'] ?? [] as $assignedStorage)
                            <input type="hidden" name="storages[]" value="{{ $assignedStorage }}">
                        @endforeach
                    @else
                        <div class="muted">All storages when none selected</div>
                    @endif
                </div>
                <div>
                    <label>Categories</label>
                    <div class="multi-picker" id="picker-categories" data-input-name="categories[]" data-placeholder="Search category…"></div>
                </div>
                <div>
                    <label>Exclude categories</label>
                    <div class="multi-picker" id="picker-exclude-categories" data-input-name="exclude_categories[]" data-placeholder="Exclude category…"></div>
                </div>
                <div class="span-2">
                    <label>Items</label>
                    <div class="multi-picker" id="picker-items" data-input-name="items[]" data-placeholder="Search item…"></div>
                </div>
                <div class="span-2">
                    <label>Exclude items</label>
                    <div class="multi-picker" id="picker-exclude-items" data-input-name="exclude_items[]" data-placeholder="Exclude item…"></div>
                </div>
                @if (($storeTitleOptions ?? []) !== [])
                <div>
                    <label for="store_title_id">Store title</label>
                    <select id="store_title_id" name="store_title_id">
                        <option value="">Any</option>
                        @foreach ($storeTitleOptions as $title)
                            <option value="{{ $title->store_title_id ?? '' }}" @selected((string) ($filters['store_title_id'] ?? '') === (string) ($title->store_title_id ?? ''))>{{ $title->store_title_name ?? '' }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div>
                    <label for="expiration_date">Expiration date</label>
                    <input type="date" id="expiration_date" name="expiration_date" value="{{ $filters['expiration_date'] ?? '' }}">
                </div>
                <div class="mode-normal-only" @if($isAdv) hidden @endif>
                    <label for="batch_no">Batch no</label>
                    <input type="text" id="batch_no" name="batch_no" value="{{ $filters['batch_no'] ?? '' }}" maxlength="250">
                </div>
                <div class="mode-adv-only" @if(!$isAdv) hidden @endif>
                    <label for="as_of_datetime">As of date/time</label>
                    <input type="datetime-local" id="as_of_datetime" name="as_of_datetime" value="{{ str_replace(' ', 'T', substr((string) ($filters['as_of_datetime'] ?? ''), 0, 16)) }}">
                </div>
                <div>
                    <label for="serial">Serial</label>
                    <input type="text" id="serial" name="serial" value="{{ $filters['serial'] ?? '' }}" maxlength="250">
                </div>
                <div>
                    <span class="filter-group-label">Balance display</span>
                    <div class="column-toggles">
                        <label class="column-toggle">
                            <input type="checkbox" name="hide_zero_balances" value="1" @checked($filters['hide_zero_balances'] ?? false)>
                            Hide zero balances
                        </label>
                        <label class="column-toggle">
                            <input type="checkbox" name="hide_negative_balances" value="1" @checked($filters['hide_negative_balances'] ?? false)>
                            Hide negative balances
                        </label>
                    </div>
                </div>
            </div>
            <div class="filters-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply filters'])
                @include('reports.partials.filters-reset-link', ['route' => 'reports.storage-quantity.index'])
                <span class="muted">Export:</span>
                <a href="#" class="storage-quantity-export-link export-link" data-export-base="{{ route('reports.storage-quantity.export.csv') }}">CSV</a>
                <a href="#" class="storage-quantity-export-link export-link" data-export-base="{{ route('reports.storage-quantity.export.pdf') }}">PDF</a>
            </div>
        </div>
    </details>
</form>

@if ($rows)
    <div class="totals-bar">
        <div class="total-item">
            <span>Total balance</span>
            <strong class="num">{{ display_number($totals['balance_total'] ?? 0) }}</strong>
        </div>
        @if (!$isAdv)
        <div class="total-item">
            <span>Total in store</span>
            <strong class="num">{{ display_number($totals['in_store_total'] ?? 0) }}</strong>
        </div>
        @endif
        <div class="muted" style="align-self:center;">{{ $rows->total() }} rows · mode: {{ $isAdv ? 'Adv' : 'Normal' }}</div>
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>Category</th>
            <th>Item code</th>
            <th>Item name</th>
            <th>Storage</th>
            <th class="num">Balance</th>
            @if (!$isAdv)<th class="num">In store</th>@endif
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ (($rows->currentPage() - 1) * $rows->perPage()) + $loop->iteration }}</td>
                <td>{{ $row->category_name ?? '' }}</td>
                <td>{{ $row->item_code ?? '' }}</td>
                <td>{{ $row->item_name ?? '' }}</td>
                <td>{{ $row->storage_name ?? '' }}</td>
                <td class="num">{{ display_number((float) ($row->balance ?? 0)) }}</td>
                @if (!$isAdv)<td class="num">{{ display_number((float) ($row->in_store ?? 0)) }}</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>
    @include('reports.partials.pagination', ['paginator' => $rows])
@elseif (!($errorMessage ?? null))
    <p class="report-empty">No rows match these filters.</p>
@endif

@php
    $pickerConfig = [
        'options' => $pickerOptions ?? [],
        'initial' => [
            'storages' => $filters['storages'] ?? [],
            'categories' => $filters['categories'] ?? [],
            'exclude_categories' => $filters['exclude_categories'] ?? [],
            'items' => $filters['items'] ?? [],
            'exclude_items' => $filters['exclude_items'] ?? [],
        ],
    ];
@endphp
<script type="application/json" id="storage-quantity-picker-config">@json($pickerConfig)</script>
<script>
(function () {
    var form = document.getElementById('storage-quantity-filter-form');
    if (!form) return;

    document.querySelectorAll('input[name="balance_mode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var adv = radio.value === 'adv';
            form.querySelectorAll('.mode-adv-only').forEach(function (el) { el.hidden = !adv; });
            form.querySelectorAll('.mode-normal-only').forEach(function (el) { el.hidden = adv; });
        });
    });

    var configEl = document.getElementById('storage-quantity-picker-config');
    var pickerConfig = { options: {}, initial: {} };
    try {
        pickerConfig = JSON.parse(configEl ? (configEl.textContent || '{}') : '{}');
    } catch (e) {
        pickerConfig = { options: {}, initial: {} };
    }

    function initMultiPicker(root) {
        if (!root) return;
        var inputName = root.getAttribute('data-input-name') || '';
        var placeholder = root.getAttribute('data-placeholder') || 'Type to search…';
        var optionKey = root.id ? root.id.replace(/^picker-/, '').replace(/-/g, '_') : '';
        var allOptions = (pickerConfig.options && pickerConfig.options[optionKey]) ? pickerConfig.options[optionKey] : [];
        var initial = (pickerConfig.initial && pickerConfig.initial[optionKey]) ? pickerConfig.initial[optionKey] : [];

        root.innerHTML = ''
            + '<div class="multi-picker-chips"></div>'
            + '<div class="multi-picker-hidden"></div>'
            + '<div class="multi-picker-search-wrap">'
            + '  <input type="text" class="multi-picker-search" autocomplete="off" placeholder="' + placeholder.replace(/"/g, '&quot;') + '">'
            + '  <ul class="multi-picker-suggestions" role="listbox"></ul>'
            + '</div>';

        var chipsEl = root.querySelector('.multi-picker-chips');
        var hiddenEl = root.querySelector('.multi-picker-hidden');
        var searchInput = root.querySelector('.multi-picker-search');
        var listEl = root.querySelector('.multi-picker-suggestions');
        var selectedIds = new Set();
        var byId = {};
        allOptions.forEach(function (o) {
            if (o && o.id) byId[o.id] = o.name || o.id;
        });

        function renderChips() {
            if (!chipsEl || !hiddenEl) return;
            chipsEl.innerHTML = '';
            hiddenEl.innerHTML = '';
            selectedIds.forEach(function (id) {
                var name = byId[id] || id;
                var chip = document.createElement('span');
                chip.className = 'multi-picker-chip';
                chip.innerHTML = '<span></span><button type="button" aria-label="Remove">×</button>';
                chip.querySelector('span').textContent = name;
                chip.querySelector('button').addEventListener('click', function () {
                    selectedIds.delete(id);
                    renderChips();
                });
                chipsEl.appendChild(chip);
                var hi = document.createElement('input');
                hi.type = 'hidden';
                hi.name = inputName;
                hi.value = id;
                hiddenEl.appendChild(hi);
            });
        }

        function showSuggestions(matches) {
            if (!listEl) return;
            listEl.innerHTML = '';
            matches.forEach(function (opt) {
                var li = document.createElement('li');
                li.textContent = opt.name;
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () {
                    selectedIds.add(opt.id);
                    renderChips();
                    if (searchInput) searchInput.value = '';
                    listEl.innerHTML = '';
                });
                listEl.appendChild(li);
            });
            listEl.classList.toggle('is-open', matches.length > 0);
        }

        initial.forEach(function (id) { if (id) selectedIds.add(id); });
        renderChips();

        if (!searchInput) return;
        searchInput.addEventListener('input', function () {
            var needle = searchInput.value.trim().toLowerCase();
            if (needle === '') {
                listEl.innerHTML = '';
                listEl.classList.remove('is-open');
                return;
            }
            var matches = allOptions.filter(function (o) {
                return o && o.id && !selectedIds.has(o.id)
                    && ((o.name || '').toLowerCase().indexOf(needle) !== -1 || (o.id || '').toLowerCase().indexOf(needle) !== -1);
            }).slice(0, 50);
            showSuggestions(matches);
        });
    }

    document.querySelectorAll('#storage-quantity-filter-form .multi-picker').forEach(initMultiPicker);
})();
</script>
@include('reports.partials.export-from-form-script', ['formId' => 'storage-quantity-filter-form', 'linkClass' => 'storage-quantity-export-link'])
@endsection
