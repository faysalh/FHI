@php
    $selected = strtoupper((string) ($selected ?? ''));
    $fieldId = $fieldId ?? 'pda_salesman_user_id';
@endphp
<div class="users-form-grid users-form-grid--narrow" style="margin-top:12px;">
    <div>
        <label for="{{ $fieldId }}">PDA salesman</label>
        <select id="{{ $fieldId }}" name="pda_salesman_user_id">
            <option value="">Not linked</option>
            @foreach ($pdaSalesmen ?? [] as $salesman)
                <option value="{{ $salesman['user_id'] }}" @selected($selected === $salesman['user_id'])>
                    {{ $salesman['name'] }}@if ($salesman['code'] !== '') ({{ $salesman['code'] }})@endif
                </option>
            @endforeach
        </select>
        <p class="hint">The PDA tab only shows this salesman’s customers. Leave blank if this login will not use PDA.</p>
    </div>
</div>
