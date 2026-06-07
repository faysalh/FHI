@php
    $pickerId = $pickerId ?? 'city';
    $selectedCities = $selectedCities ?? [];
@endphp
<script>
(function () {
    var root = document.getElementById(@json($pickerId.'-city-picker'));
    if (!root) return;
    var jsonEl = document.getElementById(@json($pickerId.'-city-options-json'));
    var allCities = [];
    try {
        allCities = JSON.parse(jsonEl ? (jsonEl.textContent || '[]') : '[]');
    } catch (e) { allCities = []; }
    var selectedIds = new Set();
    var chipsEl = document.getElementById(@json($pickerId.'-city-chips'));
    var hiddenEl = document.getElementById(@json($pickerId.'-city-hidden-inputs'));
    var searchInput = document.getElementById(@json($pickerId.'-city-search'));
    var listEl = document.getElementById(@json($pickerId.'-city-suggestions'));
    var initialIds = @json($selectedCities);
    var byId = {};
    allCities.forEach(function (c) { if (c && c.id) byId[c.id] = c.name || c.id; });
    var activeIndex = -1;

    function renderChips() {
        chipsEl.innerHTML = '';
        hiddenEl.innerHTML = '';
        selectedIds.forEach(function (id) {
            var name = byId[id] || id;
            var chip = document.createElement('span');
            chip.className = 'customer-chip';
            chip.setAttribute('data-id', id);
            var label = document.createElement('span');
            label.textContent = name;
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.setAttribute('aria-label', 'Remove ' + name);
            rm.textContent = '×';
            rm.addEventListener('click', function () {
                selectedIds.delete(id);
                renderChips();
                searchInput.focus();
            });
            chip.appendChild(label);
            chip.appendChild(rm);
            chipsEl.appendChild(chip);
            var hi = document.createElement('input');
            hi.type = 'hidden';
            hi.name = 'cities[]';
            hi.value = id;
            hiddenEl.appendChild(hi);
        });
    }

    function closeSuggestions() {
        listEl.classList.remove('is-open');
        listEl.innerHTML = '';
        activeIndex = -1;
    }

    function highlightActive() {
        var items = listEl.querySelectorAll('li:not(.muted-suggest)');
        items.forEach(function (li, i) {
            li.classList.toggle('is-active', i === activeIndex);
        });
    }

    function showSuggestions(matches) {
        listEl.innerHTML = '';
        if (matches.length === 0) {
            var li = document.createElement('li');
            li.className = 'muted-suggest';
            li.textContent = 'No matching cities. Try another spelling.';
            listEl.appendChild(li);
        } else {
            matches.forEach(function (c) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.textContent = c.name;
                li.addEventListener('mousedown', function (e) { e.preventDefault(); });
                li.addEventListener('click', function () {
                    selectedIds.add(c.id);
                    renderChips();
                    searchInput.value = '';
                    closeSuggestions();
                    searchInput.focus();
                });
                listEl.appendChild(li);
            });
        }
        listEl.classList.add('is-open');
        activeIndex = matches.length ? 0 : -1;
        highlightActive();
    }

    function filterCities(q) {
        var needle = (q || '').trim().toLowerCase();
        if (needle === '') return [];
        var out = [];
        for (var i = 0; i < allCities.length; i++) {
            var c = allCities[i];
            if (!c || !c.id || selectedIds.has(c.id)) continue;
            var name = (c.name || '').toLowerCase();
            if (name.indexOf(needle) !== -1) {
                out.push(c);
                if (out.length >= 50) break;
            }
        }
        return out;
    }

    initialIds.forEach(function (id) { if (id) selectedIds.add(id); });
    renderChips();

    if (!searchInput || searchInput.disabled) return;

    searchInput.addEventListener('input', function () {
        var q = searchInput.value;
        if (q.trim() === '') {
            closeSuggestions();
            return;
        }
        showSuggestions(filterCities(q));
    });

    searchInput.addEventListener('keydown', function (e) {
        var items = listEl.querySelectorAll('li:not(.muted-suggest)');
        if (!listEl.classList.contains('is-open') || items.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            highlightActive();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
            highlightActive();
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                items[activeIndex].click();
            }
        } else if (e.key === 'Escape') {
            closeSuggestions();
        }
    });

    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) closeSuggestions();
    });
})();
</script>
