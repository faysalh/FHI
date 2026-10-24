@extends('reports.layouts.app')
@section('title', ucfirst($kind))

@section('content')
    @php
        $titles = ['sale' => 'Sale', 'order' => 'Order', 'return' => 'Return', 'receipt' => 'Receipt'];
        $priceGroup = \App\Support\SalesItemPriceTiers::columnForAccountGroup($customer['price_group_account'] ?? max(0, ((int) ($customer['price_group'] ?? 1)) - 1));
        $categories = [];
        $tiles = [];
        foreach ($items ?? [] as $item) {
            $categoryId = (string) ($item['category_id'] ?? '');
            if ($categoryId !== '') {
                if (! isset($categories[$categoryId])) {
                    $categories[$categoryId] = [
                        'id' => $categoryId,
                        'name' => (string) ($item['category_name'] ?? ''),
                        'sort' => (int) ($item['category_sort'] ?? 0),
                        'subs' => [],
                    ];
                }
                $subcategoryId = (string) ($item['subcategory_id'] ?? '');
                if ($subcategoryId !== '' && ! isset($categories[$categoryId]['subs'][$subcategoryId])) {
                    $categories[$categoryId]['subs'][$subcategoryId] = [
                        'id' => $subcategoryId,
                        'name' => (string) ($item['subcategory_name'] ?? ''),
                        'sort' => (int) ($item['subcategory_sort'] ?? 0),
                    ];
                }
            }
            foreach ($item['units'] as $unit) {
                $tiles[] = [
                    'item_id' => $item['id'],
                    'name' => $item['name'],
                    'code' => $item['code'],
                    'available' => (float) $item['balance'],
                    'unit_row_id' => $unit['unit_row_id'],
                    'unit' => $unit['name'],
                    'price' => (float) ($unit['prices'][$priceGroup] ?? 0),
                    'has_image' => ! empty($item['has_image']),
                    'category_id' => $categoryId,
                    'subcategory_id' => (string) ($item['subcategory_id'] ?? ''),
                ];
            }
        }
        uasort($categories, static function (array $a, array $b): int {
            return [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']];
        });
        foreach ($categories as &$category) {
            uasort($category['subs'], static function (array $a, array $b): int {
                return [$a['sort'], $a['name']] <=> [$b['sort'], $b['name']];
            });
        }
        unset($category);
    @endphp
    @include('reports.pda.partials.styles')
    @if ($kind === 'sale')
        <p class="pda-sale-back">
            <a href="{{ route('reports.pda.customers.show', ['customer' => $customer['id']]) }}">{{ $customer['name'] }}</a>
            <a href="{{ route('reports.pda.visits.show', ['visit' => $visitId]) }}">Visit</a>
        </p>
    @else
        <header class="page-header">
            <h1>{{ $titles[$kind] ?? 'Voucher' }}</h1>
            <p class="hint">{{ $customer['name'] }} · price group {{ $priceGroup }}. Location is required.</p>
        </header>
        @include('reports.pda.partials.subnav')
    @endif
    <form method="POST" action="{{ route('reports.pda.vouchers.store', ['visit' => $visitId]) }}" @class(['js-pda-locate', 'pda-sale' => $kind === 'sale']) id="pda-voucher-form">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        <input type="hidden" name="discount" value="0">
        <input type="hidden" name="lat" value="">
        <input type="hidden" name="lng" value="">
        <input type="hidden" name="accuracy" value="">
        @if ($kind === 'receipt')
            <label for="pda-paid">Amount collected</label>
            <input id="pda-paid" name="paid" type="number" min="0" step="0.01" value="{{ old('paid', 0) }}" required>
            <label for="pda-notes">Notes</label>
            <input id="pda-notes" name="notes" maxlength="500" value="{{ old('notes') }}">
            <p class="pda-locate-status" data-locate-status></p>
            <button class="pda-btn pda-btn--primary" type="submit">Save receipt</button>
        @else
            <div class="pda-pos">
                <section>
                    <div class="pda-finder">
                        <label for="pda-item-search">Find item</label>
                        <input id="pda-item-search" class="pda-finder__input" type="search" placeholder="Name or code" autocomplete="off" aria-label="Find item" enterkeyhint="search">
                        @if ($categories !== [])
                            <div class="pda-cats" id="pda-cats" role="tablist" aria-label="Categories">
                                <button type="button" class="pda-cat is-on" data-cat="" data-sub="" role="tab" aria-selected="true">All</button>
                                @foreach ($categories as $category)
                                    <button type="button" class="pda-cat" data-cat="{{ $category['id'] }}" data-sub="" role="tab" aria-selected="false" dir="auto">{{ $category['name'] }}</button>
                                    @foreach ($category['subs'] as $subcategory)
                                        <button type="button" class="pda-cat" data-cat="{{ $category['id'] }}" data-sub="{{ $subcategory['id'] }}" data-parent="{{ $category['id'] }}" role="tab" aria-selected="false" dir="auto">{{ $subcategory['name'] }}</button>
                                    @endforeach
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="pda-grid-wrap">
                    <div class="pda-grid" id="pda-grid">
                        @forelse ($tiles as $tile)
                            <button
                                type="button"
                                @class(['pda-tile', 'pda-tile--locked' => $kind === 'sale' && empty($allowZeroStock) && $tile['available'] <= 0])
                                data-item-id="{{ $tile['item_id'] }}"
                                data-unit-row-id="{{ $tile['unit_row_id'] }}"
                                data-name="{{ $tile['name'] }}"
                                data-code="{{ $tile['code'] }}"
                                data-unit="{{ $tile['unit'] }}"
                                data-price="{{ $tile['price'] }}"
                                data-available="{{ $tile['available'] }}"
                                data-category="{{ $tile['category_id'] }}"
                                data-subcategory="{{ $tile['subcategory_id'] }}"
                            >
                                <span class="pda-tile__stock{{ $tile['available'] < 0 ? ' is-neg' : ($tile['available'] <= 0 ? ' is-out' : '') }}">
                                    <b>{{ display_number($tile['available'], 2) }}</b>
                                    <small>Available</small>
                                </span>
                                <span class="pda-tile__copy">
                                    <strong dir="auto">{{ $tile['name'] }}</strong>
                                    <span class="pda-tile__price">{{ display_number($tile['price'], 2) }}@if ($tile['unit'] !== '') <span class="pda-tile__unit">{{ $tile['unit'] }}</span>@endif</span>
                                </span>
                                <span class="pda-tile__photo">
                                    <span class="pda-tile__badge" data-badge></span>
                                    @if ($tile['has_image'])
                                        <img class="pda-tile__img" src="{{ route('reports.pda.items.image', ['item' => $tile['item_id']]) }}" alt="" loading="lazy" decoding="async" onerror="if(!this.dataset.retry){this.dataset.retry='1';this.src=this.src.split('?')[0]+'?retry=1';}else{this.outerHTML='<span class=&quot;pda-tile__img pda-tile__img--empty&quot;></span>';}">
                                    @else
                                        <span class="pda-tile__img pda-tile__img--empty" aria-hidden="true"></span>
                                    @endif
                                </span>
                            </button>
                        @empty
                            <p class="muted">No items are available for this salesman.</p>
                        @endforelse
                    </div>
                    </div>
                    <p class="muted" id="pda-none" hidden>No items matched.</p>
                </section>
                <aside @class(['pda-cart', 'pda-cart--sale' => $kind === 'sale']) aria-label="Ticket">
                    @if ($kind === 'sale')
                        <div class="pda-cart__sheet" id="pda-cart-sheet">
                            <div class="pda-cart__head">
                                <h2>Ticket</h2>
                            </div>
                            <div id="pda-lines"></div>
                            <p class="muted" id="pda-empty">Tap an item to add it.</p>
                            <p class="pda-stock-note" id="pda-stock-note" hidden></p>
                            <div class="pda-pay">
                                <label for="pda-paid">Amount collected now</label>
                                <input id="pda-paid" name="paid" type="number" min="0" step="0.01" value="{{ old('paid', 0) }}">
                                <label for="pda-notes">Notes</label>
                                <input id="pda-notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                            </div>
                        </div>
                        <div class="pda-sale-bar">
                            <button type="button" class="pda-btn" id="pda-ticket-toggle" aria-expanded="false" aria-controls="pda-cart-sheet">Ticket</button>
                            <span id="pda-count" class="pda-cart__count"></span>
                            <span class="pda-total" id="pda-total">Total 0</span>
                            <p class="pda-locate-status" data-locate-status></p>
                            <button class="pda-btn pda-btn--primary" type="submit">Save sale</button>
                        </div>
                    @else
                        <div class="pda-cart__head">
                            <h2>Ticket</h2>
                            <span id="pda-count" class="pda-cart__count"></span>
                        </div>
                        <div id="pda-lines"></div>
                        <p class="muted" id="pda-empty">Tap an item to add it.</p>
                        <p class="pda-stock-note" id="pda-stock-note" hidden></p>
                        <p class="pda-total" id="pda-total">Total 0</p>
                        <label for="pda-paid">Amount collected now</label>
                        <input id="pda-paid" name="paid" type="number" min="0" step="0.01" value="{{ old('paid', 0) }}">
                        <label for="pda-notes">Notes</label>
                        <input id="pda-notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                        <p class="pda-locate-status" data-locate-status></p>
                        <button class="pda-btn pda-btn--primary" type="submit">Save {{ strtolower($titles[$kind] ?? 'voucher') }}</button>
                    @endif
                </aside>
            </div>
        @endif
    </form>
    @include('reports.pda.partials.location-script')
    @if ($kind !== 'receipt')
        @push('scripts')
            <script>
                (function () {
                    var grid = document.getElementById('pda-grid');
                    var lines = document.getElementById('pda-lines');
                    var total = document.getElementById('pda-total');
                    var count = document.getElementById('pda-count');
                    var empty = document.getElementById('pda-empty');
                    var none = document.getElementById('pda-none');
                    var search = document.getElementById('pda-item-search');
                    var cats = document.getElementById('pda-cats');
                    if (!grid || !lines) return;
                    var selectedCat = '';
                    var selectedSub = '';

                    var cart = {};
                    var restored = @json(old('lines', []));
                    var blockZero = @json($kind === 'sale' && empty($allowZeroStock));
                    var stockNote = document.getElementById('pda-stock-note');

                    function money(value) {
                        var amount = Math.round((Number(value) || 0) * 100) / 100;
                        return amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                    }

                    function keyOf(itemId, unitRowId) {
                        return String(itemId).toUpperCase() + '|' + String(unitRowId).toUpperCase();
                    }

                    function tileFor(key) {
                        return grid.querySelector('[data-item-id="' + key.split('|')[0] + '"][data-unit-row-id="' + key.split('|')[1] + '"]');
                    }

                    function noStock(tile) {
                        return blockZero && (Number(tile.getAttribute('data-available')) || 0) <= 0;
                    }

                    function addTile(tile, qty) {
                        if (!tile) return;
                        if (qty > 0 && noStock(tile)) {
                            if (stockNote) {
                                stockNote.hidden = false;
                                stockNote.textContent = (tile.getAttribute('data-name') || 'This item') + ' has no stock.';
                            }
                            return;
                        }
                        if (stockNote && qty > 0) stockNote.hidden = true;
                        var key = keyOf(tile.getAttribute('data-item-id'), tile.getAttribute('data-unit-row-id'));
                        if (!cart[key]) {
                            cart[key] = {
                                itemId: tile.getAttribute('data-item-id'),
                                unitRowId: tile.getAttribute('data-unit-row-id'),
                                name: tile.getAttribute('data-name') || '',
                                unit: tile.getAttribute('data-unit') || '',
                                price: Number(tile.getAttribute('data-price')) || 0,
                                available: Number(tile.getAttribute('data-available')) || 0,
                                qty: 0
                            };
                        }
                        cart[key].qty = Math.round((cart[key].qty + qty) * 1000) / 1000;
                        if (cart[key].qty <= 0) {
                            delete cart[key];
                        }
                        render();
                    }

                    function render() {
                        lines.innerHTML = '';
                        var sum = 0;
                        var index = 0;
                        var keys = Object.keys(cart);
                        keys.forEach(function (key) {
                            var row = cart[key];
                            sum += row.qty * row.price;
                            var box = document.createElement('div');
                            var over = row.qty > row.available;
                            box.className = 'pda-line' + (over ? ' pda-line--over' : '');
                            box.innerHTML =
                                '<div class="pda-line__head"><strong></strong><span class="pda-line__sum"></span></div>' +
                                '<div class="pda-meta"></div>' +
                                '<div class="pda-avail"></div>' +
                                '<input type="hidden" name="lines[' + index + '][item_id]">' +
                                '<input type="hidden" name="lines[' + index + '][unit_row_id]">' +
                                '<input type="hidden" name="lines[' + index + '][price]">' +
                                '<input type="hidden" name="lines[' + index + '][discount]" value="0">' +
                                '<div class="pda-qty">' +
                                    '<button type="button" class="pda-btn" data-minus aria-label="Decrease">−</button>' +
                                    '<input data-qty type="number" min="0.001" step="0.001" name="lines[' + index + '][qty]">' +
                                    '<button type="button" class="pda-btn" data-plus aria-label="Increase">+</button>' +
                                '</div>';
                            box.querySelector('strong').textContent = row.name;
                            box.querySelector('.pda-line__sum').textContent = money(row.qty * row.price);
                            box.querySelector('.pda-meta').textContent = (row.unit || 'Unit') + ' · ' + money(row.price);
                            box.querySelector('.pda-avail').textContent = 'Available ' + money(row.available) + (over ? ' · over available' : '');
                            box.querySelector('[name$="[item_id]"]').value = row.itemId;
                            box.querySelector('[name$="[unit_row_id]"]').value = row.unitRowId;
                            box.querySelector('[name$="[price]"]').value = String(row.price);
                            box.querySelector('[data-qty]').value = String(row.qty);
                            box.querySelector('[data-minus]').addEventListener('click', function () { addTile(tileFor(key), -1); });
                            box.querySelector('[data-plus]').addEventListener('click', function () { addTile(tileFor(key), 1); });
                            box.querySelector('[data-qty]').addEventListener('change', function (event) {
                                var next = Number(event.target.value) || 0;
                                row.qty = 0;
                                addTile(tileFor(key), next);
                            });
                            lines.appendChild(box);
                            var picked = tileFor(key);
                            var badge = picked && picked.querySelector('[data-badge]');
                            if (badge) {
                                badge.textContent = String(row.qty);
                                badge.classList.add('is-on');
                            }
                            if (picked) picked.classList.add('is-picked');
                            index++;
                        });
                        grid.querySelectorAll('.pda-tile').forEach(function (tile) {
                            var key = keyOf(tile.getAttribute('data-item-id'), tile.getAttribute('data-unit-row-id'));
                            if (!cart[key]) {
                                var badge = tile.querySelector('[data-badge]');
                                if (badge) {
                                    badge.textContent = '';
                                    badge.classList.remove('is-on');
                                }
                                tile.classList.remove('is-picked');
                            }
                        });
                        if (empty) empty.hidden = keys.length > 0;
                        if (count) count.textContent = keys.length ? String(keys.length) : '';
                        if (total) total.textContent = 'Total ' + money(sum);
                    }

                    grid.querySelectorAll('.pda-tile').forEach(function (tile) {
                        tile.addEventListener('click', function () { addTile(tile, 1); });
                    });

                    function markTabs() {
                        if (!cats) return;
                        cats.querySelectorAll('.pda-cat').forEach(function (button) {
                            var on = (button.getAttribute('data-cat') || '') === selectedCat
                                && (button.getAttribute('data-sub') || '') === selectedSub;
                            button.classList.toggle('is-on', on);
                            button.setAttribute('aria-selected', on ? 'true' : 'false');
                        });
                    }

                    function syncSubs() {
                        if (!cats) return;
                        cats.querySelectorAll('[data-parent]').forEach(function (button) {
                            button.hidden = selectedCat !== '' && button.getAttribute('data-parent') !== selectedCat;
                        });
                    }

                    function applyFilter() {
                        var q = search ? search.value.trim().toLowerCase() : '';
                        var shown = 0;
                        grid.querySelectorAll('.pda-tile').forEach(function (tile) {
                            var hay = ((tile.getAttribute('data-name') || '') + ' ' + (tile.getAttribute('data-code') || '') + ' ' + (tile.getAttribute('data-unit') || '')).toLowerCase();
                            var match = (q === '' || hay.indexOf(q) !== -1)
                                && (selectedCat === '' || tile.getAttribute('data-category') === selectedCat)
                                && (selectedSub === '' || tile.getAttribute('data-subcategory') === selectedSub);
                            tile.hidden = !match;
                            if (match) shown++;
                        });
                        if (none) none.hidden = shown !== 0;
                    }

                    if (search) search.addEventListener('input', applyFilter);
                    var ticketToggle = document.getElementById('pda-ticket-toggle');
                    var cartSheet = document.getElementById('pda-cart-sheet');
                    if (ticketToggle && cartSheet) {
                        var cartBox = cartSheet.parentElement;
                        ticketToggle.addEventListener('click', function () {
                            var open = !cartBox.classList.contains('is-open');
                            cartBox.classList.toggle('is-open', open);
                            ticketToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                        });
                    }

                    if (cats) {
                        cats.addEventListener('click', function (event) {
                            var button = event.target.closest('.pda-cat');
                            if (!button || button.hidden) return;
                            selectedCat = button.getAttribute('data-cat') || '';
                            selectedSub = button.getAttribute('data-sub') || '';
                            markTabs();
                            syncSubs();
                            applyFilter();
                        });
                        syncSubs();
                    }

                    restored.forEach(function (line) {
                        if (!line || !line.item_id || !line.unit_row_id) return;
                        var tile = tileFor(keyOf(line.item_id, line.unit_row_id));
                        if (tile) addTile(tile, Number(line.qty) || 1);
                    });
                    render();
                })();
            </script>
        @endpush
    @endif
@endsection
