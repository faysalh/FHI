@extends('reports.layouts.app')
@section('title', 'PDA synchronize')

@section('content')
<header class="page-header">
    <h1>PDA synchronize</h1>
</header>
<p class="hint">
    Run <code>{{ $procedureLabel }}</code> to import invoices and customers sent from PDA devices into the main system.
    This is the same action as clicking <strong>Sync</strong> in AsanAccounting for PDA data.
    Reports normally use a read-only connection; this page uses a separate write connection only for this procedure.
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

<div class="alert alert--warn database-sync-warning">
    <strong>Do not run sync in two places at once.</strong>
    Close AsanMax / AsanAccounting PDA sync on all computers before using this page.
    If you see a deadlock error (<code>PocketSynchronizerVM.RefreshInvoices</code>), click OK there and retry after other sync jobs finish.
</div>

@php
    $autoSync = $autoSync ?? [
        'enabled' => false,
        'interval_seconds' => 60,
        'agent_id' => 'all',
        'skip_when_empty' => true,
        'last_run_at' => null,
        'last_error' => null,
        'last_pending_count' => 0,
    ];
    $pendingPdaCustomers = $pendingPdaCustomers ?? 0;
@endphp

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Automatic PDA sync</h2>
    <p class="hint sqlite-card__hint">
        Runs <code>{{ $procedureLabel }}</code> on a timer when unposted PDA invoices or customers are waiting.
        Skips quietly when nothing is pending.
    </p>
    <form method="POST" action="{{ route('reports.database-sync.auto-settings') }}" class="sqlite-auto-form database-sync-auto-form">
        @csrf
        <label class="sqlite-auto-form__toggle">
            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $autoSync['enabled']))>
            Enable automatic PDA sync
        </label>
        <div class="sqlite-auto-form__grid">
            <div>
                <label for="auto_sync_interval_seconds">Run every (seconds)</label>
                <input
                    type="number"
                    id="auto_sync_interval_seconds"
                    name="interval_seconds"
                    min="10"
                    max="86400"
                    value="{{ old('interval_seconds', $autoSync['interval_seconds']) }}"
                    placeholder="60"
                    required
                >
            </div>
            <div>
                <label for="auto_sync_agent_id">Agent filter</label>
                <select id="auto_sync_agent_id" name="agent_id">
                    <option value="all" @selected(old('agent_id', $autoSync['agent_id']) === 'all')>All agents</option>
                    @foreach ($agents as $agent)
                        <option
                            value="{{ $agent->agent_id }}"
                            @selected(old('agent_id', $autoSync['agent_id']) === $agent->agent_id)
                        >{{ $agent->agent_name !== '' ? $agent->agent_name : $agent->agent_id }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sqlite-auto-form__action">
                @include('reports.partials.icon-button', ['action' => 'apply', 'label' => 'Save auto sync'])
            </div>
        </div>
    </form>
    <div class="sqlite-auto-status">
        @if ($autoSync['last_run_at'])
            <p class="muted">Last automatic sync: <strong>{{ $autoSync['last_run_at'] }}</strong>
                @if ($autoSync['last_pending_count'] > 0)
                    — {{ number_format($autoSync['last_pending_count']) }} pending item(s)
                @endif
            </p>
        @else
            <p class="muted">No automatic sync has run yet.</p>
        @endif
        @if (! empty($autoSync['last_error']))
            <p class="sqlite-auto-status__error">Last error: {{ $autoSync['last_error'] }}</p>
        @endif
        <form method="POST" action="{{ route('reports.database-sync.auto-run') }}" class="sqlite-auto-run">
            @csrf
            <button type="submit" class="btn btn--secondary btn--sm">Run scheduled sync now</button>
        </form>
    </div>
    <details class="sqlite-auto-scheduler">
        <summary>Windows server: enable the scheduler</summary>
        <p class="hint">
            Automatic PDA sync uses Laravel’s scheduler (same as SQLite auto backup). On the server, open <strong>PowerShell as Administrator</strong> and run:
        </p>
        <pre class="sqlite-auto-scheduler__cmd">powershell -ExecutionPolicy Bypass -File "C:\Program Files\ReportingApp\scripts\register-reporting-scheduler.ps1"</pre>
        <p class="hint muted">The task runs every minute; intervals under 60 seconds are handled inside the app.</p>
    </details>
</section>

<section class="sqlite-card">
    <h2 class="sqlite-card__title">Run PDA sync now</h2>
    <p class="hint sqlite-card__hint">
        Processes unposted rows in <code>tbl_pda_store_title</code> and <code>tbl_pda_customers</code>.
        @if ($pendingPdaInvoices > 0 || $pendingPdaCustomers > 0)
            Waiting: <strong>{{ number_format($pendingPdaInvoices) }}</strong> invoice(s),
            <strong>{{ number_format($pendingPdaCustomers) }}</strong> customer(s).
        @else
            There is no unposted PDA work waiting right now.
        @endif
    </p>
    @if (! $isAvailable)
        <p class="alert alert--error">SQL Server is not configured. PDA sync is unavailable in this environment.</p>
    @else
        <form
            method="POST"
            action="{{ route('reports.database-sync.run') }}"
            class="database-sync-form"
            onsubmit="return confirm('Run PDA sync now? Close AsanAccounting sync on all PCs first.');"
        >
            @csrf
            <label for="agent_id">Agent filter</label>
            <select id="agent_id" name="agent_id">
                <option value="all" @selected(old('agent_id', $defaultAgentId) === 'all' || old('agent_id', $defaultAgentId) === '00000000-0000-0000-0000-000000000000')>All agents</option>
                @foreach ($agents as $agent)
                    <option
                        value="{{ $agent->agent_id }}"
                        @selected(old('agent_id', $defaultAgentId) === $agent->agent_id)
                    >{{ $agent->agent_name !== '' ? $agent->agent_name : $agent->agent_id }}</option>
                @endforeach
            </select>
            <div class="database-sync-form__action">
                <button type="submit" class="btn btn-primary">Run PDA sync</button>
            </div>
        </form>
    @endif
</section>
@endsection

@push('styles')
<style>
    .sqlite-card {
        margin-top: 1.25rem;
        padding: 1rem 1.1rem;
        border: 1px solid var(--border, #d8dee9);
        border-radius: 8px;
        background: var(--surface, #fff);
    }
    .sqlite-card__title { margin: 0 0 0.75rem; font-size: 1.05rem; }
    .sqlite-card__hint { margin: 0 0 1rem; }
    .database-sync-form label,
    .database-sync-auto-form label { display: block; margin-bottom: 0.35rem; font-weight: 600; }
    .database-sync-form select,
    .database-sync-auto-form select,
    .database-sync-auto-form input[type="number"] {
        width: min(100%, 28rem);
        max-width: 100%;
    }
    .database-sync-form select { margin-bottom: 1rem; }
    .database-sync-warning { margin-top: 1rem; }
    .sqlite-auto-form__toggle { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; font-weight: 600; }
    .sqlite-auto-form__grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
        align-items: end;
    }
    .sqlite-auto-status { margin-top: 1rem; }
    .sqlite-auto-status__error { color: var(--danger, #c0392b); margin: 0.5rem 0 0; }
    .sqlite-auto-run { margin-top: 0.75rem; }
    .sqlite-auto-scheduler { margin-top: 1rem; }
    .sqlite-auto-scheduler__cmd {
        overflow-x: auto;
        padding: 0.75rem;
        background: #f4f6f8;
        border-radius: 6px;
        font-size: 0.85rem;
    }
</style>
@endpush
