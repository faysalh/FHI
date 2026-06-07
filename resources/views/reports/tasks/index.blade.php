@extends('reports.layouts.app')
@section('title', 'Tasks')

@section('content')
<header class="page-header">
    <h1>Operations tasks</h1>
</header>
<p class="hint">Create client task notes and receive browser notifications on invoice days. Notifications repeat using your chosen recurrence while this page is open.</p>

@if (session('status'))
    <div class="alert alert--success">{{ session('status') }}</div>
@endif

<form method="GET" action="{{ route('reports.tasks.index') }}" class="tasks-filter-form">
    <details class="filters-panel" open>
        <summary>Filter & sort tasks</summary>
        <div class="filters-body">
            <div class="filters-grid">
                <div>
                    <label for="task_client_filter">Client contains</label>
                    <input type="text" id="task_client_filter" name="client" value="{{ $taskFilters['client'] ?? '' }}" placeholder="Name or account id">
                </div>
                <div>
                    <label for="task_status_filter">Status</label>
                    <select id="task_status_filter" name="status">
                        <option value="all" @selected(($taskFilters['status'] ?? 'all') === 'all')>All</option>
                        <option value="active" @selected(($taskFilters['status'] ?? '') === 'active')>Active only</option>
                        <option value="completed" @selected(($taskFilters['status'] ?? '') === 'completed')>Completed only</option>
                    </select>
                </div>
                <div>
                    <label for="task_sort_filter">Sort by</label>
                    <select id="task_sort_filter" name="sort">
                        <option value="updated" @selected(($taskFilters['sort'] ?? 'updated') === 'updated')>Recently updated</option>
                        <option value="client" @selected(($taskFilters['sort'] ?? '') === 'client')>Client name</option>
                        <option value="created" @selected(($taskFilters['sort'] ?? '') === 'created')>Created date</option>
                        <option value="recurrence" @selected(($taskFilters['sort'] ?? '') === 'recurrence')>Recurrence (minutes)</option>
                    </select>
                </div>
            </div>
            <div class="filters-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply'])
                @include('reports.partials.filters-reset-link', ['route' => 'reports.tasks.index'])
            </div>
        </div>
    </details>
</form>

@if ($errors->any())
    <div class="alert alert--error">
        <ul class="error-list-plain">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<section class="tasks-card">
    <h2 class="tasks-card__title">Add task</h2>
    <form method="POST" action="{{ route('reports.tasks.store') }}" class="tasks-form-add">
        @csrf
        <div class="tasks-form-grid">
            <div>
                <label for="client_search">Client</label>
                <input type="text"
                       id="client_search"
                       list="client_search_options"
                       placeholder="Type client name..."
                       autocomplete="off"
                       value="{{ old('client_name') }}"
                       required>
                <datalist id="client_search_options">
                    @foreach ($clients as $client)
                        <option value="{{ $client['name'] }}"></option>
                    @endforeach
                </datalist>
                <input type="hidden" name="client_account_id" id="client_account_id" value="{{ old('client_account_id') }}">
                <input type="hidden" name="client_name" id="client_name" value="{{ old('client_name') }}">
            </div>
            <div>
                <label for="recurrence_minutes">Repeat every (minutes)</label>
                <input type="number"
                       id="recurrence_minutes"
                       name="recurrence_minutes"
                       min="{{ \App\Services\OperationsTasksSqliteService::MIN_RECURRENCE_MINUTES }}"
                       max="{{ \App\Services\OperationsTasksSqliteService::MAX_RECURRENCE_MINUTES }}"
                       step="1"
                       value="{{ old('recurrence_minutes', 60) }}"
                       required>
                <p class="muted" style="margin:4px 0 0;font-size:12px;">{{ \App\Services\OperationsTasksSqliteService::MIN_RECURRENCE_MINUTES }}–{{ number_format(\App\Services\OperationsTasksSqliteService::MAX_RECURRENCE_MINUTES) }} (7 days). Example: 10 = every 10 minutes.</p>
            </div>
            <div class="tasks-col-span">
                <label for="notes">Task notes</label>
                <textarea id="notes" name="notes" rows="4" maxlength="3000" required>{{ old('notes') }}</textarea>
            </div>
        </div>
        @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Create task'])
    </form>
</section>

<section class="tasks-card">
    <div class="tasks-toolbar inline-action-row">
        <h2 class="tasks-card__title">Current tasks ({{ count($tasks) }})</h2>
        @include('reports.partials.icon-button', ['action' => 'notifications', 'label' => 'Enable browser notifications', 'type' => 'button', 'id' => 'enableNotificationsBtn'])
    </div>
    <p class="muted">Notification checks run every minute while you are signed in. Only users with access to Tasks receive reminders.</p>

    @if ($tasks === [])
        <p class="muted">No tasks yet.</p>
    @else
        <h3 class="tasks-subtitle">Active ({{ count($activeTasks) }})</h3>
        @if ($activeTasks === [])
            <p class="muted">No active tasks.</p>
        @else
            <div class="tasks-table-wrap">
                <table class="tasks-table">
                    <thead>
                        <tr>
                            <th>Client name</th>
                            <th>Repeat (minutes)</th>
                            <th>Task notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($activeTasks as $task)
                        @php $taskId = (int) ($task->id ?? 0); @endphp
                        <tr>
                            <td class="tasks-client">
                                <strong>{{ $task->client_name }}</strong>
                            </td>
                            <td class="tasks-frequency">
                                <form method="POST" action="{{ route('reports.tasks.update', $taskId) }}" class="tasks-row-form">
                                    @csrf
                                    @method('PUT')
                                    <input type="number" name="recurrence_minutes" min="{{ \App\Services\OperationsTasksSqliteService::MIN_RECURRENCE_MINUTES }}" max="{{ \App\Services\OperationsTasksSqliteService::MAX_RECURRENCE_MINUTES }}" value="{{ (int) ($task->recurrence_minutes ?? 60) }}" required>
                            </td>
                            <td class="tasks-notes">
                                    <textarea name="notes" rows="2" maxlength="3000" required>{{ $task->notes }}</textarea>
                                    <input type="hidden" name="is_active" value="1">
                            </td>
                            <td class="tasks-actions">
                                    @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save changes'])
                                </form>
                                <form method="POST" action="{{ route('reports.tasks.complete', $taskId) }}" onsubmit="return confirm('Mark this task as complete?');">
                                    @csrf
                                    @include('reports.partials.icon-button', ['action' => 'complete', 'label' => 'Complete task'])
                                </form>
                                <form method="POST" action="{{ route('reports.tasks.destroy', $taskId) }}" class="tasks-delete-form" onsubmit="return confirm('Delete this task?');">
                                    @csrf
                                    @method('DELETE')
                                    @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete task'])
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h3 class="tasks-subtitle">Completed ({{ count($completedTasks) }})</h3>
        @if ($completedTasks === [])
            <p class="muted">No completed tasks yet.</p>
        @endif
        @foreach ($completedTasks as $task)
            @php $taskId = (int) ($task->id ?? 0); @endphp
            <div class="tasks-item tasks-item--completed">
                <div class="tasks-item__header">
                    <div>
                        <strong>{{ $task->client_name }}</strong>
                        <div class="muted">Completed {{ $task->completed_at ? \Carbon\Carbon::parse((string) $task->completed_at)->diffForHumans() : '' }}</div>
                    </div>
                    <span class="badge badge--paused">Completed</span>
                </div>
                <p class="tasks-item__notes">{{ $task->notes }}</p>
                <form method="POST" action="{{ route('reports.tasks.update', $taskId) }}" class="tasks-actions-row">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="notes" value="{{ $task->notes }}">
                    <input type="hidden" name="recurrence_minutes" value="{{ (int) ($task->recurrence_minutes ?? 60) }}">
                    <input type="hidden" name="is_active" value="1">
                    @include('reports.partials.icon-button', ['action' => 'reopen', 'label' => 'Re-open task'])
                </form>
            </div>
        @endforeach
    @endif
</section>
@endsection

@push('scripts')
<script>
(function () {
    var clientMap = @json(array_values(array_map(static fn (array $c): array => ['id' => (string) ($c['id'] ?? ''), 'name' => (string) ($c['name'] ?? '')], $clients)));
    var clientSearch = document.getElementById('client_search');
    var clientAccountId = document.getElementById('client_account_id');
    var clientName = document.getElementById('client_name');
    var enableBtn = document.getElementById('enableNotificationsBtn');
    var addForm = document.querySelector('.tasks-form-add');

    function syncClientFields() {
        if (!clientSearch || !clientName || !clientAccountId) return false;
        var q = clientSearch.value.trim().toLowerCase();
        var hit = null;
        for (var i = 0; i < clientMap.length; i++) {
            var row = clientMap[i];
            if ((row.name || '').trim().toLowerCase() === q) {
                hit = row;
                break;
            }
        }
        if (hit) {
            clientName.value = hit.name;
            clientAccountId.value = hit.id;
            return true;
        }
        clientName.value = clientSearch.value.trim();
        clientAccountId.value = '';
        return false;
    }

    if (clientSearch) {
        clientSearch.addEventListener('input', syncClientFields);
        clientSearch.addEventListener('change', syncClientFields);
        clientSearch.addEventListener('blur', syncClientFields);
        syncClientFields();
    }
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            if (!syncClientFields()) {
                e.preventDefault();
                alert('Please choose a client from the suggestions list.');
                clientSearch.focus();
            }
        });
    }

    if (enableBtn && window.reportTasksNotifications) {
        enableBtn.addEventListener('click', async function () {
            var ok = await window.reportTasksNotifications.requestPermission();
            if (ok) {
                alert('Browser notifications enabled.');
                window.reportTasksNotifications.checkNow();
            }
        });
    }
})();
</script>
@endpush

@push('styles')
<style>
.tasks-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}
.tasks-card__title { margin: 0 0 10px; font-size: 16px; }
.tasks-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 10px 12px;
    margin-bottom: 12px;
}
.tasks-col-span { grid-column: 1 / -1; }
.tasks-col-span textarea { width: 100%; }
.tasks-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.tasks-subtitle {
    margin: 14px 0 8px;
    font-size: 14px;
    color: #334155;
}
.tasks-table-wrap {
    overflow-x: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.tasks-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 860px;
    background: #fff;
}
.tasks-table th,
.tasks-table td {
    border-bottom: 1px solid #e2e8f0;
    padding: 10px;
    vertical-align: top;
}
.tasks-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 12px;
    text-align: left;
}
.tasks-table tr:last-child td {
    border-bottom: none;
}
.tasks-client { width: 22%; }
.tasks-frequency { width: 14%; }
.tasks-notes { width: 42%; }
.tasks-actions { width: 22%; }
.tasks-row-form input[type="number"] {
    width: 100%;
}
.tasks-row-form textarea {
    width: 100%;
    min-height: 66px;
    resize: vertical;
}
.tasks-actions {
    display: flex;
    flex-direction: row;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}
.tasks-actions form {
    margin: 0;
    display: inline-flex;
}
.tasks-actions { min-width: 140px; }
.tasks-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 10px;
    padding: 12px;
    background: #fff;
}
.tasks-item--active { border-left: 4px solid #16a34a; }
.tasks-item--completed {
    border-left: 4px solid #94a3b8;
    background: #f8fafc;
}
.tasks-item__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 8px;
}
.tasks-item__notes {
    margin: 8px 0 10px;
    color: #334155;
    white-space: pre-wrap;
}
.tasks-actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.tasks-switch { display: flex; align-items: end; }
.badge--active {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #dcfce7;
    color: #166534;
    font-weight: 600;
}
.badge--paused {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #fef3c7;
    color: #92400e;
    font-weight: 600;
}
.error-list-plain { margin: 0; padding-left: 18px; }
@media (max-width: 980px) {
    .tasks-table {
        min-width: 700px;
    }
}
</style>
@endpush

