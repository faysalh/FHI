@extends('reports.layouts.app')
@section('title', 'Governorates')

@section('content')
<header class="page-header">
    <h1>Saved governorates</h1>
</header>
<p class="hint">
        Define governorate city mappings used by the <a href="{{ route('reports.cities.index') }}">Cities</a> report
        (governorate breakdown, pie charts), <a href="{{ route('reports.sales.index') }}">Sales</a> governorate filter,
        and the <a href="{{ route('reports.dashboard-lab.index') }}">Dashboard</a>.
        Stored locally in the same SQLite database as delivery teams.
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

<datalist id="report-city-names-datalist">
    @foreach ($cityNames as $cityName)
        <option value="{{ $cityName }}"></option>
    @endforeach
</datalist>

<section class="holidays-card">
    <h2 class="holidays-card__title">{{ !empty($editingGovernorate) ? 'Edit governorate' : 'Save governorate' }}</h2>
    <form method="POST" action="{{ route('reports.governorates.store') }}" class="gov-editor-form">
        @csrf
        <input type="hidden" name="governorate_id" value="{{ !empty($editingGovernorate['id']) ? (int) $editingGovernorate['id'] : '' }}">
        <div class="gov-editor-field">
            <label for="governorate_name_form">Governorate label</label>
            <input type="text" id="governorate_name_form" name="governorate_name" value="{{ old('governorate_name', $editingGovernorate['name'] ?? '') }}" placeholder="e.g. Erbil Governorate" required>
        </div>
        <div class="gov-editor-field">
            <label for="governorate_city_form">Governorate city</label>
            <input type="text" id="governorate_city_form" name="governorate_city" list="report-city-names-datalist" value="{{ old('governorate_city', $editingGovernorate['governorate_city'] ?? '') }}" placeholder="Type or pick from suggestions (e.g. Duhok)" required maxlength="200">
            <p class="muted" style="margin:4px 0 0;">Use the exact spelling stored on accounts for reporting.</p>
        </div>
        <div class="gov-editor-field">
            <label for="governorate_members_form">Member cities</label>
            <select id="governorate_members_form" name="governorate_members[]" multiple size="6" class="gov-editor-members">
                @foreach ($cityNames as $cityName)
                    @php
                        $selectedMembers = old('governorate_members', $editingGovernorate['members'] ?? []);
                        $selectedMembers = is_array($selectedMembers) ? $selectedMembers : [];
                    @endphp
                    <option value="{{ $cityName }}" @selected(in_array($cityName, $selectedMembers, true))>{{ $cityName }}</option>
                @endforeach
            </select>
            <p class="muted" style="margin:4px 0 0;">Optional. Governorate city is always included automatically.</p>
        </div>
        <div class="gov-editor-actions">
            @include('reports.partials.icon-button', [
                'action' => 'save',
                'label' => !empty($editingGovernorate) ? 'Update governorate' : 'Save governorate',
            ])
            @if (!empty($editingGovernorate))
                <a href="{{ route('reports.governorates.index') }}" class="btn btn--secondary">New governorate</a>
            @endif
        </div>
    </form>
</section>

<section class="holidays-card">
    <h2 class="holidays-card__title">Saved governorates ({{ count($savedGovernorates) }})</h2>
    @if ($savedGovernorates === [])
        <p class="muted">No governorates saved yet. Add one above — the governorate city can be typed (e.g. Duhok) even if it does not appear in the visits city list.</p>
    @else
        <table class="holidays-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Governorate city</th>
                <th class="num">Cities</th>
                <th>Use in reports</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($savedGovernorates as $savedGov)
                <tr>
                    <td>{{ $savedGov->name ?? '' }}</td>
                    <td>{{ $savedGov->governorate_city ?? '' }}</td>
                    <td class="num">{{ (int) ($savedGov->member_count ?? 0) }}</td>
                    <td>
                        <a href="{{ route('reports.cities.index', ['city_page' => 'governorate-breakdown', 'saved_governorate_id' => (int) ($savedGov->id ?? 0)]) }}">Breakdown</a>
                        ·
                        <a href="{{ route('reports.cities.index', ['city_page' => 'pie-charts', 'saved_governorate_id' => (int) ($savedGov->id ?? 0)]) }}">Pie charts</a>
                    </td>
                    <td class="holidays-table__actions">
                        <a href="{{ route('reports.governorates.index', ['edit' => (int) ($savedGov->id ?? 0)]) }}">Edit</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</section>
@endsection
