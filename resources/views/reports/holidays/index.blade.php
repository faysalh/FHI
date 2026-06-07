@extends('reports.layouts.app')
@section('title', 'Holidays')

@section('content')
<header class="page-header">
    <h1>Holidays &amp; Eid</h1>
</header>
<p class="hint">
        Dates listed here are treated as <strong>non-working days</strong> in dashboard calculations (along with every Friday).
        Used for month projections and daily averages on the <a href="{{ route('reports.dashboard-lab.index') }}">Dashboard</a>.
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

@if ($storageError)
    <div class="alert alert--error">{{ $storageError }}</div>
@endif

<section class="holidays-card">
    <h2 class="holidays-card__title">Add non-working day</h2>
    <form method="POST" action="{{ route('reports.holidays.store') }}" class="holidays-form">
        @csrf
        <div class="holidays-form__grid">
            <div>
                <label for="holiday_date">Date</label>
                <input type="date" id="holiday_date" name="holiday_date" value="{{ old('holiday_date') }}" required>
            </div>
            <div>
                <label for="label">Label (optional)</label>
                <input type="text" id="label" name="label" value="{{ old('label') }}" placeholder="e.g. Eid al-Fitr day 1" maxlength="200">
            </div>
            <div class="holidays-form__action">
                @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Add holiday'])
            </div>
        </div>
    </form>
    <p class="holidays-help">
        Add each day of a multi-day Eid separately (e.g. four rows for a four-day Eid al-Adha).
        On first setup, default 2026 Eid dates from config may be imported automatically when the list is empty.
    </p>
</section>

<section class="holidays-card">
    <h2 class="holidays-card__title">Configured holidays ({{ count($rows) }})</h2>
    @if ($rows === [])
        <p class="muted">No holidays configured yet. Fridays are still excluded automatically.</p>
    @else
        @foreach ($byYear as $year => $yearRows)
            <h3 class="holidays-year">{{ $year }}</h3>
            <table class="holidays-table">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Label</th>
                    <th>Day</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($yearRows as $row)
                    @php
                        $d = (string) ($row->holiday_date ?? '');
                        $dayName = $d !== '' ? \Carbon\Carbon::parse($d)->format('l') : '';
                    @endphp
                    <tr>
                        <td>{{ $d }}</td>
                        <td>{{ $row->label ?? '' }}</td>
                        <td class="muted">{{ $dayName }}</td>
                        <td class="holidays-table__actions">
                            <form method="POST" action="{{ route('reports.holidays.destroy', ['holiday' => (int) ($row->id ?? 0)]) }}" onsubmit="return confirm('Remove this holiday date?');">
                                @csrf
                                @method('DELETE')
                                @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Remove holiday'])
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
</section>
@endsection
