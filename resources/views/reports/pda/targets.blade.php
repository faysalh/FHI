@extends('reports.layouts.app')
@section('title', 'PDA targets')

@section('content')
    <header class="page-header">
        <h1>Targets</h1>
        <p class="hint">{{ $salesman['name'] }}</p>
    </header>
    @include('reports.pda.partials.subnav')
    @forelse ($targets as $target)
        <article class="pda-card">
            <strong>{{ $target->fld_name ?? 'Target' }}</strong>
            <div class="pda-meta">
                {{ $target->fld_start_date ?? '' }} – {{ $target->fld_end_date ?? '' }}
                <br>Qty {{ display_number($target->Target_Qty ?? 0, 2) }}
                · Achieved {{ display_number($target->Achived_Qty ?? 0, 2) }}
                · Left {{ display_number($target->Remaining_Qty ?? 0, 2) }}
                <br>New clients {{ display_number($target->fld_new_clients_achieved ?? 0, 0) }} / {{ display_number($target->fld_new_clients_target ?? 0, 0) }}
                @if (isset($target->remaining_days)) · {{ $target->remaining_days }} days left @endif
            </div>
        </article>
    @empty
        <p class="muted">No targets for this salesman.</p>
    @endforelse
@endsection
