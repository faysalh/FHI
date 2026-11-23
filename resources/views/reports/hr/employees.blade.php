@extends('reports.layouts.app')
@section('title', 'Employees')

@section('content')
    <header class="page-header">
        <h1>Employees</h1>
        <p class="hint">Add a driver, companion, Face ID person, or someone else. Emergency contact is stored with the employee file.</p>
    </header>

    @include('reports.hr.partials.subnav')

    @if (session('status'))
        <div class="alert alert--success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert--error" role="alert">{{ session('error') }}</div>
    @endif
    @if ($sourceError)
        <p class="hint">{{ $sourceError }}</p>
    @endif
    @if ($errors->any())
        <div class="alert alert--error" role="alert">{{ $errors->first() }}</div>
    @endif

    @php
        $isEdit = $editing !== null;
        $groups = [];
        foreach ($sourceOptions as $option) {
            $groups[$option['group']][] = $option;
        }
    @endphp

    <h2>{{ $isEdit ? 'Edit employee' : 'Add employee' }}</h2>
    <form method="POST" action="{{ $isEdit ? route('reports.hr.employees.update', $editing->id) : route('reports.hr.employees.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif
        <div class="filters-grid">
            @if ($isEdit)
                <div>
                    <label>Person</label>
                    <p>{{ $editing->full_name }} <span class="muted">({{ $editing->source_label }}{{ !empty($editing->source_missing) ? ', no longer in the original list' : '' }})</span></p>
                </div>
                @if (($editing->source_type ?? '') === \App\Services\HrSqliteService::SOURCE_OTHER)
                    <div>
                        <label for="full_name">Name</label>
                        <input id="full_name" name="full_name" value="{{ old('full_name', $editing->full_name) }}" required maxlength="200">
                    </div>
                @endif
            @else
                <div>
                    <label for="source_ref">Person</label>
                    <select id="source_ref" name="source_ref" required>
                        <option value="other" @selected(old('source_ref', 'other') === 'other')>Other person</option>
                        @foreach ($groups as $group => $options)
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $option)
                                    <option value="{{ $option['value'] }}" @selected(old('source_ref') === $option['value'])>{{ $option['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div id="other-name">
                    <label for="full_name">Name</label>
                    <input id="full_name" name="full_name" value="{{ old('full_name') }}" maxlength="200">
                </div>
            @endif
            <div>
                <label for="job_title">Job title</label>
                <input id="job_title" name="job_title" value="{{ old('job_title', $editing->job_title ?? '') }}" maxlength="120">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input id="phone" name="phone" value="{{ old('phone', $editing->phone ?? '') }}" maxlength="50">
            </div>
            <div>
                <label for="address">Address</label>
                <input id="address" name="address" value="{{ old('address', $editing->address ?? '') }}" maxlength="300">
            </div>
            <div>
                <label for="hire_date">Hire date</label>
                <input id="hire_date" type="date" name="hire_date" value="{{ old('hire_date', $editing->hire_date ?? '') }}">
            </div>
            <div>
                <label for="national_id">ID number</label>
                <input id="national_id" name="national_id" value="{{ old('national_id', $editing->national_id ?? '') }}" maxlength="50">
            </div>
            <div>
                <label for="emergency_name">Emergency contact</label>
                <input id="emergency_name" name="emergency_name" value="{{ old('emergency_name', $editing->emergency_name ?? '') }}" maxlength="200">
            </div>
            <div>
                <label for="emergency_relationship">Relationship</label>
                <input id="emergency_relationship" name="emergency_relationship" value="{{ old('emergency_relationship', $editing->emergency_relationship ?? '') }}" maxlength="80">
            </div>
            <div>
                <label for="emergency_phone">Emergency phone</label>
                <input id="emergency_phone" name="emergency_phone" value="{{ old('emergency_phone', $editing->emergency_phone ?? '') }}" maxlength="50">
            </div>
            <div>
                <label for="notes">Notes</label>
                <input id="notes" name="notes" value="{{ old('notes', $editing->notes ?? '') }}" maxlength="2000">
            </div>
            <div>
                @php
                    $activeChecked = $errors->any()
                        ? old('is_active') !== null
                        : ($isEdit ? (int) $editing->is_active === 1 : true);
                @endphp
                <label for="is_active">
                    <input id="is_active" type="checkbox" name="is_active" value="1" @checked($activeChecked)>
                    Active
                </label>
            </div>
        </div>
        <div class="btn-group" style="margin:12px 0;">
            <button class="btn" type="submit">{{ $isEdit ? 'Save changes' : 'Add employee' }}</button>
            @if ($isEdit)
                <a class="btn btn--secondary" href="{{ route('reports.hr.employees.index') }}">Cancel</a>
            @endif
        </div>
    </form>

    <h2>On file</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Source</th>
                <th>Phone</th>
                <th>Emergency</th>
                <th>Active</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($employees as $employee)
                <tr>
                    <td>{{ $employee->full_name }}</td>
                    <td>{{ $employee->source_label }}</td>
                    <td>{{ $employee->phone }}</td>
                    <td>
                        {{ $employee->emergency_name }}
                        @if ($employee->emergency_phone)
                            <span class="muted">{{ $employee->emergency_phone }}</span>
                        @endif
                    </td>
                    <td>{{ (int) $employee->is_active === 1 ? 'Yes' : 'No' }}</td>
                    <td><a href="{{ route('reports.hr.employees.index', ['edit' => $employee->id]) }}">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="6">No employees yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    @unless ($isEdit)
        <script>
        (function () {
            var select = document.getElementById('source_ref');
            var nameWrap = document.getElementById('other-name');
            var nameInput = document.getElementById('full_name');
            if (!select || !nameWrap) return;
            function sync() {
                var other = select.value === 'other';
                nameWrap.hidden = !other;
                if (nameInput) nameInput.required = other;
            }
            select.addEventListener('change', sync);
            sync();
        })();
        </script>
    @endunless
@endsection
