@extends('reports.layouts.app')
@section('title', 'Questionnaires')

@section('content')
    <header class="page-header">
        <h1>Questionnaires</h1>
        <p class="hint">Open one to see answers already collected. Filling a form is done from a customer visit.</p>
    </header>
    @include('reports.pda.partials.subnav')
    @forelse ($forms as $form)
        <a class="pda-card" href="{{ route('reports.pda.questionnaires.show', ['questionnaire' => $form['id']]) }}">
            <strong>{{ $form['title'] }}</strong>
            @if ($form['mandatory'])<div class="pda-meta">Required on a visit</div>@endif
        </a>
    @empty
        <p class="muted">No active questionnaires for this salesman.</p>
    @endforelse
@endsection
