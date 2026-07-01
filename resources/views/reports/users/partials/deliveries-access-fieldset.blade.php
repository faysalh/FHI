@php
    $prefix = $prefix ?? 'new';
    $access = is_array($access ?? null) ? $access : [];
    $checked = static function (string $field) use ($access): bool {
        $oldKey = 'deliveries_can_'.$field;
        if (old($oldKey) !== null) {
            return (bool) old($oldKey);
        }

        return (bool) ($access['can_'.$field] ?? false);
    };
    $defaultStorage = old('deliveries_default_storage', $access['default_storage'] ?? '');
@endphp
<fieldset class="users-deliveries-access" data-deliveries-access-fieldset hidden>
    <legend>Deliveries report access</legend>
    <p class="hint users-deliveries-access__hint">Choose which filters this user can change on the Deliveries report tab. Unchecked filters are locked or hidden. If storage filter is off, pick the fixed storage they always see.</p>
    <div class="users-perm-group__items users-deliveries-access__checks">
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_filter_date" value="1" @checked($checked('filter_date'))>
            Date range
        </label>
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_filter_city" value="1" @checked($checked('filter_city'))>
            City filter
        </label>
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_filter_storage" value="1" @checked($checked('filter_storage')) data-deliveries-storage-toggle>
            Storage filter
        </label>
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_filter_salesman" value="1" @checked($checked('filter_salesman'))>
            Salesman filter
        </label>
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_filter_status" value="1" @checked($checked('filter_status'))>
            Status filter
        </label>
        <label class="chk-label">
            <input type="checkbox" name="deliveries_can_edit_status" value="1" @checked($checked('edit_status'))>
            Change delivery status (column)
        </label>
    </div>
    <div class="users-deliveries-access__default-storage" data-deliveries-default-storage>
        <label for="deliveries_default_storage_{{ $prefix }}">Default storage <span class="muted">(required when storage filter is off)</span></label>
        <select id="deliveries_default_storage_{{ $prefix }}" name="deliveries_default_storage">
            <option value="">Select storage</option>
            @foreach (($deliveriesStorageOptions ?? []) as $storageOption)
                <option value="{{ $storageOption }}" @selected((string) $defaultStorage === (string) $storageOption)>{{ $storageOption }}</option>
            @endforeach
        </select>
    </div>
</fieldset>
