@extends('reports.layouts.app')
@section('title', 'Users')

@section('content')
<header class="page-header">
    <h1>Users</h1>
</header>
<p class="hint">Create login accounts and choose which report tabs each user can open. Administrators see every report and can manage users.</p>

@if (session('status'))
    <div class="alert alert--success">{{ session('status') }}</div>
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

<section class="users-card">
    <h2 class="users-card__title">Add user</h2>
    <form method="POST" action="{{ route('reports.users.store') }}" class="users-form-add">
        @csrf
        <div class="users-form-grid">
            <div>
                <label for="new_username">Username</label>
                <input type="text" id="new_username" name="username" value="{{ old('username') }}" required autocomplete="off" pattern="[A-Za-z0-9._-]+" maxlength="100">
            </div>
            <div>
                <label for="new_password">Password</label>
                <input type="password" id="new_password" name="password" required autocomplete="new-password">
            </div>
            <div>
                <label for="new_password_confirmation">Confirm password</label>
                <input type="password" id="new_password_confirmation" name="password_confirmation" required autocomplete="new-password">
            </div>
            <div class="users-admin-toggle">
                <label class="chk-label">
                    <input type="checkbox" name="is_super_admin" value="1" @checked(old('is_super_admin')) data-toggle-admin>
                    Administrator (full access)
                </label>
            </div>
        </div>

        <fieldset class="users-permissions" data-permissions-fieldset>
            <legend>Report access</legend>
            @php
                $oldKeys = old('report_keys', []);
                $bySection = [];
                foreach ($permissionMatrix as $item) {
                    $bySection[$item['section_label']][] = $item;
                }
            @endphp
            @foreach ($bySection as $sectionLabel => $items)
                <div class="users-perm-group">
                    <div class="users-perm-group__label">{{ $sectionLabel }}</div>
                    <div class="users-perm-group__items">
                        @foreach ($items as $item)
                            <label class="chk-label">
                                <input type="checkbox" name="report_keys[]" value="{{ $item['key'] }}"
                                    @checked(in_array($item['key'], $oldKeys, true))>
                                {{ $item['label'] }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </fieldset>

        @include('reports.users.partials.deliveries-access-fieldset', [
            'prefix' => 'new',
            'access' => [],
            'deliveriesStorageOptions' => $deliveriesStorageOptions ?? [],
        ])

        @include('reports.users.partials.storage-access-fieldset', [
            'prefix' => 'new',
            'access' => [],
            'storageStorageOptions' => $storageStorageOptions ?? [],
        ])

        @include('reports.partials.icon-button', ['action' => 'add', 'label' => 'Create user'])
    </form>
</section>

<section class="users-card">
    <h2 class="users-card__title">Existing users ({{ count($users) }})</h2>
    @if ($users === [])
        <p class="muted">No users yet. Set <code>REPORTS_BOOTSTRAP_ADMIN_USERNAME</code> and <code>REPORTS_BOOTSTRAP_ADMIN_PASSWORD</code> in <code>.env</code>, then reload this page to create the first administrator.</p>
    @else
        @php
            $bySection = [];
            foreach ($permissionMatrix as $item) {
                $bySection[$item['section_label']][] = $item;
            }
        @endphp
        @foreach ($users as $user)
            @php
                $userId = (int) ($user->id ?? 0);
                $isAdmin = (int) ($user->is_super_admin ?? 0) === 1;
                $userKeys = is_array($user->report_keys ?? null) ? $user->report_keys : [];
            @endphp
            <details class="users-row" @if($loop->first) open @endif>
                <summary>
                    <strong>{{ $user->username }}</strong>
                    @if ($isAdmin)
                        <span class="badge badge--admin">Administrator</span>
                    @else
                        <span class="muted">· {{ count($userKeys) }} report(s)</span>
                    @endif
                    @if ($userId === $currentUserId)
                        <span class="muted">(you)</span>
                    @endif
                </summary>
                <div class="users-row__body">
                    <form method="POST" action="{{ route('reports.users.update', $userId) }}">
                        @csrf
                        @method('PUT')
                        <label class="chk-label users-row__admin">
                            <input type="checkbox" name="is_super_admin" value="1" @checked($isAdmin) data-toggle-admin>
                            Administrator (full access)
                        </label>

                        <fieldset class="users-permissions" data-permissions-fieldset @if($isAdmin) hidden @endif>
                            <legend>Report access</legend>
                            @foreach ($bySection as $sectionLabel => $items)
                                <div class="users-perm-group">
                                    <div class="users-perm-group__label">{{ $sectionLabel }}</div>
                                    <div class="users-perm-group__items">
                                        @foreach ($items as $item)
                                            <label class="chk-label">
                                                <input type="checkbox" name="report_keys[]" value="{{ $item['key'] }}"
                                                    @checked($isAdmin || in_array($item['key'], $userKeys, true))>
                                                {{ $item['label'] }}
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </fieldset>

                        @include('reports.users.partials.deliveries-access-fieldset', [
                            'prefix' => 'user-'.$userId,
                            'userId' => $userId,
                            'access' => is_array($user->deliveries_access ?? null) ? $user->deliveries_access : [],
                            'deliveriesStorageOptions' => $deliveriesStorageOptions ?? [],
                        ])

                        @include('reports.users.partials.storage-access-fieldset', [
                            'prefix' => 'user-'.$userId,
                            'userId' => $userId,
                            'access' => is_array($user->storage_access ?? null) ? $user->storage_access : [],
                            'storageStorageOptions' => $storageStorageOptions ?? [],
                        ])

                        <div class="users-form-grid users-form-grid--narrow">
                            <div>
                                <label>New password <span class="muted">(optional)</span></label>
                                <input type="password" name="password" autocomplete="new-password">
                            </div>
                            <div>
                                <label>Confirm new password</label>
                                <input type="password" name="password_confirmation" autocomplete="new-password">
                            </div>
                        </div>

                        <div class="users-row__actions inline-action-row">
                            @include('reports.partials.icon-button', ['action' => 'save', 'label' => 'Save user changes'])
                        </div>
                    </form>
                    @if ($userId !== $currentUserId)
                        <form method="POST" action="{{ route('reports.users.destroy', $userId) }}" class="users-delete-form" onsubmit="return confirm('Delete user {{ $user->username }}?');">
                            @csrf
                            @method('DELETE')
                            @include('reports.partials.icon-button', ['action' => 'delete', 'label' => 'Delete user'])
                        </form>
                    @endif
                </div>
            </details>
        @endforeach
    @endif
</section>

<script>
(function () {
    function syncAdminToggle(scope) {
        var root = scope || document;
        root.querySelectorAll('[data-toggle-admin]').forEach(function (adminChk) {
            var form = adminChk.closest('form');
            if (!form) return;
            var fieldset = form.querySelector('[data-permissions-fieldset]');
            if (!fieldset) return;
            function apply() {
                var on = adminChk.checked;
                fieldset.hidden = on;
                fieldset.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    cb.disabled = on;
                });
                syncDeliveriesAccess(form);
                syncStorageAccess(form);
            }
            adminChk.addEventListener('change', apply);
            apply();
        });
    }

    function syncDeliveriesAccess(form) {
        if (!form) return;
        var deliveriesChk = form.querySelector('input[name="report_keys[]"][value="deliveries"]');
        var accessFieldset = form.querySelector('[data-deliveries-access-fieldset]');
        if (!accessFieldset) return;
        var adminChk = form.querySelector('[data-toggle-admin]');
        var show = deliveriesChk && deliveriesChk.checked && (!adminChk || !adminChk.checked);
        accessFieldset.hidden = !show;
        accessFieldset.querySelectorAll('input, select').forEach(function (el) {
            el.disabled = !show;
        });
        if (show) {
            syncDefaultStorageVisibility(accessFieldset);
        }
    }

    function syncDefaultStorageVisibility(scope) {
        var root = scope || document;
        root.querySelectorAll('[data-deliveries-access-fieldset]').forEach(function (fieldset) {
            if (fieldset.hidden) return;
            var storageToggle = fieldset.querySelector('[data-deliveries-storage-toggle]');
            var defaultWrap = fieldset.querySelector('[data-deliveries-default-storage]');
            if (!storageToggle || !defaultWrap) return;
            function apply() {
                defaultWrap.hidden = storageToggle.checked;
                var select = defaultWrap.querySelector('select');
                if (select) {
                    select.required = !storageToggle.checked;
                    if (storageToggle.checked) {
                        select.value = '';
                    }
                }
            }
            storageToggle.addEventListener('change', apply);
            apply();
        });
    }

    function syncStorageAccess(form) {
        if (!form) return;
        var storageChk = form.querySelector('input[name="report_keys[]"][value="storage"]');
        var accessFieldset = form.querySelector('[data-storage-access-fieldset]');
        if (!accessFieldset) return;
        var adminChk = form.querySelector('[data-toggle-admin]');
        var show = storageChk && storageChk.checked && (!adminChk || !adminChk.checked);
        accessFieldset.hidden = !show;
        accessFieldset.querySelectorAll('input, select').forEach(function (el) {
            el.disabled = !show;
        });
        if (show) {
            syncStorageAssignedRequired(accessFieldset);
        }
    }

    function syncStorageAssignedRequired(scope) {
        var root = scope || document;
        root.querySelectorAll('[data-storage-access-fieldset]').forEach(function (fieldset) {
            if (fieldset.hidden) return;
            var filterToggle = fieldset.querySelector('[data-storage-filter-toggle]');
            var assignedWrap = fieldset.querySelector('[data-storage-assigned-list]');
            if (!filterToggle || !assignedWrap) return;
            function apply() {
                var select = assignedWrap.querySelector('select');
                if (select) {
                    select.required = !filterToggle.checked;
                }
            }
            filterToggle.addEventListener('change', apply);
            apply();
        });
    }

    function wireStorageAccess(scope) {
        var root = scope || document;
        root.querySelectorAll('form').forEach(function (form) {
            form.querySelectorAll('input[name="report_keys[]"]').forEach(function (chk) {
                chk.addEventListener('change', function () { syncStorageAccess(form); });
            });
            syncStorageAccess(form);
        });
        syncStorageAssignedRequired(root);
    }

    function wireDeliveriesAccess(scope) {
        var root = scope || document;
        root.querySelectorAll('form').forEach(function (form) {
            form.querySelectorAll('input[name="report_keys[]"]').forEach(function (chk) {
                chk.addEventListener('change', function () { syncDeliveriesAccess(form); });
            });
            syncDeliveriesAccess(form);
        });
        syncDefaultStorageVisibility(root);
    }

    syncAdminToggle(document);
    wireDeliveriesAccess(document);
    wireStorageAccess(document);
})();
</script>
@endsection

@push('styles')
<style>
.users-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}
.users-card__title { margin: 0 0 12px; font-size: 16px; }
.users-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px 12px;
    margin-bottom: 12px;
}
.users-form-grid--narrow { max-width: 520px; }
.users-admin-toggle { display: flex; align-items: flex-end; }
.users-permissions {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 0 0 12px;
    background: #f8fafc;
}
.users-permissions legend {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    padding: 0 4px;
}
.users-perm-group { margin-bottom: 10px; }
.users-perm-group:last-child { margin-bottom: 0; }
.users-perm-group__label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
    margin-bottom: 6px;
}
.users-perm-group__items {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 14px;
}
.users-row {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    margin-bottom: 8px;
    background: #fafafa;
}
.users-row summary {
    cursor: pointer;
    padding: 10px 12px;
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.users-row summary::-webkit-details-marker { display: none; }
.users-row__body { padding: 0 12px 12px; border-top: 1px solid #e2e8f0; }
.users-row__admin { margin: 12px 0; display: inline-flex; }
.users-row__actions { margin-top: 8px; }
.users-row__body > .inline-action-row,
.users-row__body > .users-delete-form {
    display: inline-flex;
    vertical-align: middle;
    gap: 8px;
    margin-top: 8px;
}
.users-delete-form { margin-top: 8px; }
.users-deliveries-access {
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 0 0 12px;
    background: #fff;
}
.users-deliveries-access legend {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    padding: 0 4px;
}
.users-deliveries-access__hint { margin: 0 0 8px; font-size: 12px; }
.users-deliveries-access__checks { margin-bottom: 10px; }
.users-deliveries-access__default-storage label { display: block; margin-bottom: 4px; }
.users-deliveries-access__default-storage select { max-width: 320px; width: 100%; }
.users-storage-access {
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 10px 12px;
    margin: 0 0 12px;
    background: #fff;
}
.users-storage-access legend {
    font-size: 12px;
    font-weight: 700;
    color: #64748b;
    padding: 0 4px;
}
.users-storage-access__hint { margin: 0 0 8px; font-size: 12px; }
.users-storage-access__checks { margin-bottom: 10px; }
.users-storage-access__assigned label { display: block; margin-bottom: 4px; }
.users-storage-access__assigned select { max-width: 420px; width: 100%; }
.badge--admin {
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 999px;
    background: #dbeafe;
    color: #1e40af;
    font-weight: 600;
}
.btn-danger {
    background: #dc2626;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    cursor: pointer;
    font-size: 13px;
}
.error-list-plain { margin: 0; padding-left: 18px; }
</style>
@endpush
