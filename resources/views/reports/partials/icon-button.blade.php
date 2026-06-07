@php
    $action = $action ?? 'save';
    $label = $label ?? match ($action) {
        'save' => 'Save',
        'delete' => 'Delete',
        'complete' => 'Complete',
        'apply' => 'Apply filters',
        'add' => 'Add',
        'run' => 'Run',
        'clear' => 'Clear',
        'move-up' => 'Move up',
        'move-down' => 'Move down',
        'reopen' => 'Re-open',
        'view' => 'View',
        'print' => 'Print',
        'load' => 'Load',
        'explain' => 'Explain',
        'notifications' => 'Enable notifications',
        'logout' => 'Sign out',
        default => ucfirst(str_replace('-', ' ', $action)),
    };
    $btnType = $type ?? 'submit';
    $extraClass = trim((string) ($class ?? ''));
    $btnClass = trim('btn-icon btn-icon--'.$action.' '.$extraClass);
    $buttonAttributes = [
        'type' => $btnType,
        'class' => $btnClass,
        'title' => $label,
        'aria-label' => $label,
    ];
    if (! empty($id)) {
        $buttonAttributes['id'] = (string) $id;
    }
    if (! empty($onclick)) {
        $buttonAttributes['onclick'] = (string) $onclick;
    }
    if (! empty($disabled)) {
        $buttonAttributes['disabled'] = 'disabled';
    }
@endphp
<button @foreach ($buttonAttributes as $attr => $value) {{ $attr }}="{{ $value }}" @endforeach>
    @switch($action)
        @case('delete')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12ZM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4Z"/></svg>
            @break
        @case('complete')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.55 18.7 3.85 13l1.4-1.4 4.3 4.3 9.2-9.2 1.4 1.4-10.6 10.6Z"/></svg>
            @break
        @case('apply')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17Z"/></svg>
            @break
        @case('add')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2Z"/></svg>
            @break
        @case('run')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8 5v14l11-7L8 5Z"/></svg>
            @break
        @case('clear')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41Z"/></svg>
            @break
        @case('move-up')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6 1.41 1.41Z"/></svg>
            @break
        @case('move-down')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41Z"/></svg>
            @break
        @case('reopen')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12.5 8c-2.65 0-5.05 1.54-6.17 4H3l3.89 3.89.07.14L11 12H8c0-2.21 1.79-4 4-4 .88 0 1.68.29 2.34.78l1.52-1.52C13.13 7.06 12.84 7 12.5 7c-3.04 0-5.5 2.46-5.5 5.5S9.46 18 12.5 18c2.76 0 5.09-2.03 5.47-4.72h-2.07c-.33 1.44-1.59 2.47-3.4 2.47-1.77 0-3.2-1.43-3.2-3.2s1.43-3.2 3.2-3.2c.88 0 1.67.36 2.24.93l1.41-1.41L12.5 8Z"/></svg>
            @break
        @case('view')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5Zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3Z"/></svg>
            @break
        @case('print')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3Zm-3 11H8v-5h8v5Zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1Zm-4-9H9V3h6v2Z"/></svg>
            @break
        @case('load')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5Zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14Z"/></svg>
            @break
        @case('explain')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11 18h2v-2h-2v2Zm1-16C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8Zm0-14c-2.21 0-4 1.79-4 4h2c0-1.1.9-2 2-2s2 .9 2 2c0 2-3 1.75-3 5h2c0-2.25 3-2.5 3-5 0-2.21-1.79-4-4-4Z"/></svg>
            @break
        @case('notifications')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2Zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2Zm-2 1H8v-6c0-2.48 1.51-4.5 4-4.5s4 2.02 4 4.5v6Z"/></svg>
            @break
        @case('logout')
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5ZM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5Z"/></svg>
            @break
        @default
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4ZM12 19a3 3 0 1 1 0-6 3 3 0 0 1 0 6ZM6 8V5h9v3H6Z"/></svg>
    @endswitch
</button>
