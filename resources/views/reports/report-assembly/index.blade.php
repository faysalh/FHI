@extends('reports.layouts.app')
@section('title', 'Report assembly')

@section('content')
<header class="page-header"><h1>Report assembly</h1></header>
<p class="hint">Set the priority of categories, then item priority inside each category. Drag rows to reorder, or use the move buttons. Reports that show item lines will use this ordering automatically.</p>

    @if (session('status'))
        <div class="alert alert--success assembly-save-notice">{{ session('status') }}</div>
    @endif
    @php
        $savedCategoryOrder = $settings['category_priority'] ?? [];
        $orderedCategories = collect($savedCategoryOrder)->merge($categories)->unique()->values()->all();
        $savedItemOrder = $settings['item_priority_by_category'][$selectedCategory] ?? [];
        $orderedItems = collect($savedItemOrder)->merge($items)->unique()->values()->all();
    @endphp

    <div class="layout">
        <div class="card">
            <h2>Category priority</h2>
            <div class="tools inline-action-row">
                @include('reports.partials.icon-button', ['action' => 'move-up', 'label' => 'Move category up', 'type' => 'button', 'onclick' => "moveUp('category-list')"])
                @include('reports.partials.icon-button', ['action' => 'move-down', 'label' => 'Move category down', 'type' => 'button', 'onclick' => "moveDown('category-list')"])
            </div>
            <form method="POST" action="{{ route('reports.report-assembly.categories.save') }}" onsubmit="syncList('category-list','category_priority[]')">
                @csrf
                <ol id="category-list" class="assembly-sortable">
                    @foreach ($orderedCategories as $category)
                        <li tabindex="0" draggable="true">{{ $category }}</li>
                    @endforeach
                </ol>
                @if (count($orderedCategories) === 0)
                    <p class="hint" style="margin-top:8px;">No categories found yet. Add data or open another date-based report first, then return here.</p>
                @endif
                <div id="category-hidden"></div>
                <div style="margin-top:10px;">
                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save category priority'])
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Item priority by category</h2>
            <form method="GET" action="{{ route('reports.report-assembly.index') }}">
                <label for="category">Category</label>
                <select id="category" name="category" onchange="this.form.submit()">
                    @foreach ($orderedCategories as $category)
                        <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </form>
            <div class="tools inline-action-row">
                @include('reports.partials.icon-button', ['action' => 'move-up', 'label' => 'Move item up', 'type' => 'button', 'onclick' => "moveUp('item-list')"])
                @include('reports.partials.icon-button', ['action' => 'move-down', 'label' => 'Move item down', 'type' => 'button', 'onclick' => "moveDown('item-list')"])
            </div>
            <form method="POST" action="{{ route('reports.report-assembly.items.save') }}" onsubmit="syncList('item-list','item_priority[]')">
                @csrf
                <input type="hidden" name="category" value="{{ $selectedCategory }}">
                <ol id="item-list" class="assembly-sortable">
                    @foreach ($orderedItems as $item)
                        <li tabindex="0" draggable="true">{{ $item }}</li>
                    @endforeach
                </ol>
                @if ($selectedCategory === '')
                    <p class="hint" style="margin-top:8px;">Select a category to set item priority.</p>
                @elseif (count($orderedItems) === 0)
                    <p class="hint" style="margin-top:8px;">No items found in this category.</p>
                @endif
                <div id="item-hidden"></div>
                <div style="margin-top:10px;">
                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save item priority'])
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function getActiveLi(listId) {
    var list = document.getElementById(listId);
    if (!list) return null;
    var selected = list.querySelector('li.is-selected');
    if (selected) return selected;
    var active = document.activeElement;
    if (active && active.tagName === 'LI' && active.parentElement === list) return active;
    return list.querySelector('li');
}
function moveUp(listId) {
    var li = getActiveLi(listId);
    if (!li || !li.previousElementSibling) return;
    li.parentElement.insertBefore(li, li.previousElementSibling);
    li.focus();
}
function moveDown(listId) {
    var li = getActiveLi(listId);
    if (!li || !li.nextElementSibling) return;
    li.parentElement.insertBefore(li.nextElementSibling, li);
    li.focus();
}
function syncList(listId, fieldName) {
    var list = document.getElementById(listId);
    var hidden = document.getElementById(listId === 'category-list' ? 'category-hidden' : 'item-hidden');
    hidden.innerHTML = '';
    list.querySelectorAll('li').forEach(function (li) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = fieldName;
        input.value = li.textContent.trim();
        hidden.appendChild(input);
    });
}

function selectLi(li) {
    if (!li || li.tagName !== 'LI') return;
    var list = li.parentElement;
    if (!list) return;
    list.querySelectorAll('li').forEach(function (node) { node.classList.remove('is-selected'); });
    li.classList.add('is-selected');
    li.focus();
}

function initSelectableList(listId) {
    var list = document.getElementById(listId);
    if (!list) return;
    list.querySelectorAll('li').forEach(function (li, idx) {
        if (idx === 0 && !list.querySelector('li.is-selected')) {
            li.classList.add('is-selected');
        }
        li.addEventListener('click', function () { selectLi(li); });
        li.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                var prev = li.previousElementSibling;
                if (prev) selectLi(prev);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                var next = li.nextElementSibling;
                if (next) selectLi(next);
            }
        });
    });
}

initSelectableList('category-list');
initSelectableList('item-list');

function initSortableList(listId) {
    var list = document.getElementById(listId);
    if (!list) return;
    var dragEl = null;
    list.querySelectorAll('li').forEach(function (li) {
        li.addEventListener('dragstart', function () {
            dragEl = li;
            li.classList.add('is-dragging');
        });
        li.addEventListener('dragend', function () {
            li.classList.remove('is-dragging');
            dragEl = null;
        });
        li.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragEl || dragEl === li) return;
            var rect = li.getBoundingClientRect();
            var after = (e.clientY - rect.top) > (rect.height / 2);
            if (after) {
                li.parentElement.insertBefore(dragEl, li.nextElementSibling);
            } else {
                li.parentElement.insertBefore(dragEl, li);
            }
        });
    });
}
initSortableList('category-list');
initSortableList('item-list');
</script>
@endsection

@push('styles')
<style>
.status { background: #ecfdf5; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 12px; }
        .layout { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; }
        .card h2 { margin: 0 0 8px 0; font-size: 16px; }
        ol { margin: 0; padding-left: 22px; max-height: 420px; overflow: auto; }
        li { margin-bottom: 6px; cursor: pointer; border-radius: 4px; padding: 2px 4px; }
        li.is-selected { background: #dbeafe; outline: 1px solid #60a5fa; }
        li.is-dragging { opacity: 0.55; }
        .assembly-save-notice { margin-bottom: 12px; }
        .assembly-sortable li { cursor: grab; }
        .tools { display: flex; gap: 8px; margin-bottom: 10px; }
        select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 6px; margin-bottom: 10px; }
</style>
@endpush
