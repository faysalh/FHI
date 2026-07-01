@php
    $prefix = $prefix ?? 'new';
    $access = is_array($access ?? null) ? $access : [];
    $canFilter = (bool) old('storage_can_filter_storage', $access['can_filter_storage'] ?? true);
    $selectedStorages = old('storage_allowed_storages', $access['allowed_storages'] ?? []);
    if (! is_array($selectedStorages)) {
        $selectedStorages = $selectedStorages !== null && $selectedStorages !== '' ? [(string) $selectedStorages] : [];
    }
@endphp
<fieldset class="users-storage-access" data-storage-access-fieldset hidden>
    <legend>Storage report access</legend>
    <p class="hint users-storage-access__hint">Assign one or more storages to limit what this user can see on the Stock snapshot report. Leave storages empty to allow all storages. If storage filter is off, the user always sees their assigned storages only.</p>
    <div class="users-perm-group__items users-storage-access__checks">
        <label class="chk-label">
            <input type="checkbox" name="storage_can_filter_storage" value="1" @checked($canFilter) data-storage-filter-toggle>
            Storage filter (pick subset of assigned storages)
        </label>
    </div>
    <div class="users-storage-access__assigned" data-storage-assigned-list>
        <label for="storage_allowed_storages_{{ $prefix }}">Assigned storages <span class="muted">(required when filter is off)</span></label>
        <select id="storage_allowed_storages_{{ $prefix }}" name="storage_allowed_storages[]" multiple size="6" class="select-compact-multi">
            @foreach (($storageStorageOptions ?? []) as $storageOption)
                <option value="{{ $storageOption }}" @selected(in_array($storageOption, $selectedStorages, true))>{{ $storageOption }}</option>
            @endforeach
        </select>
    </div>
</fieldset>
