@extends('reports.layouts.app')
@section('title', $form['title'])

@section('content')
    <header class="page-header"><h1>{{ $form['title'] }}</h1></header>
    @include('reports.pda.partials.subnav')
    @if ($customer)
        <form method="POST" action="{{ route('reports.pda.questionnaires.store', ['questionnaire' => $form['id']]) }}">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $customer['id'] }}">
            @if ($visitId !== '')
                <input type="hidden" name="visit_id" value="{{ $visitId }}">
            @endif
            <p class="hint">Answers for {{ $customer['name'] }}.</p>
            @foreach ($questions as $question)
                @php
                    $options = array_values(array_filter($sources, static fn (array $source): bool => $source['source_id'] !== '' && $source['source_id'] === $question['source_id']));
                @endphp
                <label for="q-{{ $question['id'] }}">{{ $question['title'] }}@if ($question['required']) *@endif</label>
                @if ($options !== [])
                    <select id="q-{{ $question['id'] }}" name="answers[{{ $question['id'] }}]" @if($question['required']) required @endif>
                        <option value="">Choose</option>
                        @foreach ($options as $option)
                            <option value="{{ $option['title'] }}">{{ $option['title'] }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="q-{{ $question['id'] }}" name="answers[{{ $question['id'] }}]" @if($question['required']) required @endif maxlength="2000">
                @endif
            @endforeach
            <button class="pda-btn pda-btn--primary" type="submit">Save answers</button>
        </form>
    @else
        <p class="hint">Open this questionnaire from a visit to fill it in. Answers already saved are below.</p>
    @endif
    <h2>Saved answers</h2>
    @php
        $resultRows = $results;
        $columns = $resultRows !== [] ? array_keys(get_object_vars($resultRows[0])) : [];
    @endphp
    @if ($columns === [])
        <p class="muted">No answers yet.</p>
    @else
        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resultRows as $row)
                        <tr>
                            @foreach ($columns as $column)
                                <td>{{ $row->{$column} ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
