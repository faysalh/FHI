@extends('reports.layouts.app')
@section('title', 'IT Work Log')

@section('content')
<header class="page-header">
    <h1>IT Work Log</h1>
</header>
<p class="hint">
    Start a work entry when you begin a task; finish it when you are done. Entries are personal to your account.
</p>

@if (session('status'))
    <div class="alert alert--success">{{ session('status') }}</div>
@endif
@if (session('error'))
    <div class="alert alert--error">{{ session('error') }}</div>
@endif
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
    <h2 class="tasks-card__title">Start work</h2>
    <form method="POST" action="{{ route('reports.it-work-log.store') }}" class="tasks-form-add">
        @csrf
        <div class="tasks-form-grid">
            <div>
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}" maxlength="500" required placeholder="What are you working on?">
            </div>
            <div>
                <label for="notes">Notes (optional)</label>
                <textarea id="notes" name="notes" rows="2" maxlength="5000" placeholder="Optional details">{{ old('notes') }}</textarea>
            </div>
        </div>
        <div class="filters-actions" style="margin-top: 0.75rem;">
            @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Start work'])
            <span class="muted">Start time is recorded automatically.</span>
        </div>
    </form>
</section>

<form method="GET" action="{{ route('reports.it-work-log.index') }}" id="it-work-log-filter-form" class="tasks-filter-form">
    <details class="filters-panel" open>
        <summary>Filter &amp; export</summary>
        <div class="filters-body">
            <div class="filters-grid">
                <div>
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>All</option>
                        <option value="in_progress" @selected(($filters['status'] ?? '') === 'in_progress')>In progress</option>
                        <option value="finished" @selected(($filters['status'] ?? '') === 'finished')>Finished</option>
                    </select>
                </div>
                <div>
                    <label for="date_from">Started from</label>
                    <input type="date" id="date_from" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
                </div>
                <div>
                    <label for="date_to">Started to</label>
                    <input type="date" id="date_to" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
                </div>
                <div>
                    <label for="q">Search</label>
                    <input type="text" id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Title or notes">
                </div>
            </div>
            <div class="filters-actions">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Apply'])
                @include('reports.partials.filters-reset-link', ['route' => 'reports.it-work-log.index'])
                <span class="muted">Export:</span>
                <a href="#" class="it-work-log-export-link export-link" data-export-base="{{ route('reports.it-work-log.export.csv') }}">CSV</a>
                <a href="#" class="it-work-log-export-link export-link" data-export-base="{{ route('reports.it-work-log.export.pdf') }}">PDF</a>
            </div>
        </div>
    </details>
</form>

<section class="tasks-card">
    <h2 class="tasks-card__title">In progress ({{ count($inProgress) }})</h2>
    @if ($inProgress === [])
        <p class="muted">No work in progress.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Notes</th>
                    <th>Started</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($inProgress as $entry)
                @php
                    $entryId = (int) ($entry->id ?? 0);
                    $duration = \App\Services\ItWorkLogSqliteService::formatDuration(
                        (string) ($entry->started_at ?? ''),
                        null
                    );
                @endphp
                <tr>
                    <td>
                        <form method="POST" action="{{ route('reports.it-work-log.update', $entryId) }}" id="it-work-edit-{{ $entryId }}">
                            @csrf
                            @method('PUT')
                            <input type="text" name="title" value="{{ $entry->title }}" maxlength="500" required>
                    </td>
                    <td>
                            <textarea name="notes" rows="2" maxlength="5000">{{ $entry->notes }}</textarea>
                    </td>
                    <td class="muted">{{ $entry->started_at }}</td>
                    <td>{{ $duration }}</td>
                    <td class="tasks-actions">
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save changes'])
                        </form>
                        <form method="POST" action="{{ route('reports.it-work-log.finish', $entryId) }}" onsubmit="return confirm('Finish this work entry?');">
                            @csrf
                            @include('reports.partials.icon-button', ['action' => 'complete', 'label' => 'Finish work'])
                        </form>
                        <form method="POST" action="{{ route('reports.it-work-log.destroy', $entryId) }}" onsubmit="return confirm('Delete this work entry?');">
                            @csrf
                            @method('DELETE')
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete'])
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="tasks-card">
    <h2 class="tasks-card__title">Finished ({{ count($finished) }})</h2>
    @if ($finished === [])
        <p class="muted">No finished work yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Notes</th>
                    <th>Started</th>
                    <th>Finished</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($finished as $entry)
                @php
                    $entryId = (int) ($entry->id ?? 0);
                    $duration = \App\Services\ItWorkLogSqliteService::formatDuration(
                        (string) ($entry->started_at ?? ''),
                        (string) ($entry->finished_at ?? '')
                    );
                @endphp
                <tr>
                    <td>
                        <form method="POST" action="{{ route('reports.it-work-log.update', $entryId) }}" id="it-work-edit-finished-{{ $entryId }}">
                            @csrf
                            @method('PUT')
                            <input type="text" name="title" value="{{ $entry->title }}" maxlength="500" required>
                    </td>
                    <td>
                            <textarea name="notes" rows="2" maxlength="5000">{{ $entry->notes }}</textarea>
                    </td>
                    <td class="muted">{{ $entry->started_at }}</td>
                    <td class="muted">{{ $entry->finished_at }}</td>
                    <td>{{ $duration }}</td>
                    <td class="tasks-actions">
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save changes'])
                        </form>
                        <form method="POST" action="{{ route('reports.it-work-log.destroy', $entryId) }}" onsubmit="return confirm('Delete this work entry?');">
                            @csrf
                            @method('DELETE')
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete'])
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection

@push('scripts')
@include('reports.partials.export-from-form-script', ['formId' => 'it-work-log-filter-form', 'linkClass' => 'it-work-log-export-link'])
@endpush
