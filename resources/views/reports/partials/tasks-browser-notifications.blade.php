<div id="reportTasksInPageAlerts" class="report-tasks-inpage-alerts" hidden aria-live="polite"></div>
<style>
    .report-tasks-inpage-alerts {
        position: fixed;
        right: 16px;
        bottom: 16px;
        z-index: 1200;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: min(420px, calc(100vw - 32px));
    }
    .report-tasks-inpage-alerts:not([hidden]) {
        display: flex;
    }
    .report-tasks-inpage-alert {
        background: #0f172a;
        color: #f8fafc;
        border: 1px solid #334155;
        border-radius: 10px;
        padding: 12px 14px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25);
        font-size: 13px;
    }
    .report-tasks-inpage-alert strong {
        display: block;
        margin-bottom: 4px;
        font-size: 14px;
    }
    .report-tasks-inpage-alert p {
        margin: 0 0 8px;
        white-space: pre-wrap;
        color: #e2e8f0;
    }
    .report-tasks-inpage-alert a {
        color: #93c5fd;
        font-weight: 600;
        text-decoration: none;
    }
    .report-tasks-inpage-alert button {
        background: #334155;
        color: #f8fafc;
        border: 0;
        border-radius: 6px;
        padding: 4px 10px;
        font-size: 12px;
        cursor: pointer;
    }
</style>
<script>
(function () {
    var dueUrl = @json(route('reports.tasks.due'));
    var ackUrl = @json(route('reports.tasks.due-ack'));
    var tasksUrl = @json(route('reports.tasks.index'));
    var inPageStorageKey = 'reportTasksInPageReminders';

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function isSecureContext() {
        return window.isSecureContext === true;
    }

    function inPageRemindersEnabled() {
        if (!isSecureContext()) {
            return true;
        }

        return window.localStorage.getItem(inPageStorageKey) === '1';
    }

    function setInPageReminders(enabled) {
        if (enabled) {
            window.localStorage.setItem(inPageStorageKey, '1');
        } else {
            window.localStorage.removeItem(inPageStorageKey);
        }
    }

    function browserNotificationsAvailable() {
        return ('Notification' in window) && isSecureContext();
    }

    function permissionState() {
        if (!browserNotificationsAvailable()) {
            return inPageRemindersEnabled() ? 'in-page' : 'insecure-or-unsupported';
        }
        if (inPageRemindersEnabled()) {
            return 'in-page';
        }

        return Notification.permission;
    }

    async function requestPermission() {
        if (!isSecureContext()) {
            setInPageReminders(true);

            return {
                ok: true,
                mode: 'in-page',
                message: 'This app is opened over HTTP (not HTTPS). Browsers block the notification permission pop-up on HTTP.\n\nOn-page task reminders are now enabled instead. Keep a signed-in reports tab open.',
            };
        }

        if (!('Notification' in window)) {
            setInPageReminders(true);

            return {
                ok: true,
                mode: 'in-page',
                message: 'This browser does not support desktop notifications. On-page task reminders are enabled instead.',
            };
        }

        if (Notification.permission === 'granted') {
            setInPageReminders(false);

            return {
                ok: true,
                mode: 'browser',
                message: 'Desktop notifications are already enabled for this browser.',
            };
        }

        if (Notification.permission === 'denied') {
            setInPageReminders(true);

            return {
                ok: true,
                mode: 'in-page',
                message: 'Desktop notifications are blocked for this site in your browser settings.\n\nOn-page task reminders are enabled instead. To use Windows pop-ups, allow notifications for this site in the browser address bar / site settings, then click this button again.',
            };
        }

        var result = await Notification.requestPermission();
        if (result === 'granted') {
            setInPageReminders(false);

            return {
                ok: true,
                mode: 'browser',
                message: 'Desktop notifications enabled.',
            };
        }

        setInPageReminders(true);

        return {
            ok: true,
            mode: 'in-page',
            message: result === 'denied'
                ? 'You chose Block, or the browser blocked the prompt. On-page task reminders are enabled instead.'
                : 'Desktop notification permission was not granted. On-page task reminders are enabled instead.',
        };
    }

    function inPageAlertBox() {
        var box = document.getElementById('reportTasksInPageAlerts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'reportTasksInPageAlerts';
            box.className = 'report-tasks-inpage-alerts';
            box.hidden = true;
            document.body.appendChild(box);
        }

        return box;
    }

    function showInPageReminder(item) {
        var box = inPageAlertBox();
        var card = document.createElement('div');
        card.className = 'report-tasks-inpage-alert';
        var title = document.createElement('strong');
        title.textContent = 'Task reminder — ' + (item.client_name || 'Client');
        var body = document.createElement('p');
        var text = (item.notes || '').trim();
        body.textContent = text.length > 220 ? text.slice(0, 220) + '...' : text;
        var actions = document.createElement('div');
        var link = document.createElement('a');
        link.href = tasksUrl;
        link.textContent = 'Open tasks';
        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.textContent = 'Dismiss';
        dismiss.style.marginLeft = '8px';
        dismiss.addEventListener('click', function () {
            card.remove();
            if (!box.children.length) {
                box.hidden = true;
            }
        });
        actions.appendChild(link);
        actions.appendChild(dismiss);
        card.appendChild(title);
        card.appendChild(body);
        card.appendChild(actions);
        box.appendChild(card);
        box.hidden = false;
    }

    async function ackTaskIds(taskIds) {
        if (!taskIds.length) {
            return;
        }
        try {
            await fetch(ackUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ task_ids: taskIds })
            });
        } catch (e) {
            // Retry on next poll if ack fails.
        }
    }

    async function deliverDueItems(items) {
        var useBrowser = browserNotificationsAvailable() && Notification.permission === 'granted' && !inPageRemindersEnabled();
        var acknowledged = [];

        (items || []).forEach(function (item) {
            if (useBrowser) {
                try {
                    var note = new Notification('Task reminder — ' + (item.client_name || 'Client'), {
                        body: ((item.notes || '').trim()).slice(0, 220),
                        data: { url: tasksUrl }
                    });
                    note.onclick = function (event) {
                        event.preventDefault();
                        try { window.focus(); } catch (e) {}
                        window.location.href = (note.data && note.data.url) ? note.data.url : tasksUrl;
                        note.close();
                    };
                    if (item.id) {
                        acknowledged.push(item.id);
                    }
                } catch (e) {
                    showInPageReminder(item);
                    if (item.id) {
                        acknowledged.push(item.id);
                    }
                }
            } else {
                showInPageReminder(item);
                if (item.id) {
                    acknowledged.push(item.id);
                }
            }
        });

        await ackTaskIds(acknowledged);
    }

    async function checkDueTasks() {
        var canPoll = (browserNotificationsAvailable() && Notification.permission === 'granted')
            || inPageRemindersEnabled();
        if (!canPoll) {
            return;
        }

        try {
            var response = await fetch(dueUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({})
            });
            if (response.status === 403 || response.status === 419) {
                return;
            }
            if (!response.ok) {
                return;
            }
            var data = await response.json();
            await deliverDueItems(data.items || []);
        } catch (e) {
            // Silent on purpose to keep UI clean.
        }
    }

    window.reportTasksNotifications = {
        requestPermission: requestPermission,
        checkNow: checkDueTasks,
        permissionState: permissionState,
        isSecureContext: isSecureContext,
        inPageRemindersEnabled: inPageRemindersEnabled
    };

    if (!isSecureContext()) {
        setInPageReminders(true);
    }

    setInterval(checkDueTasks, 60 * 1000);
    checkDueTasks();
})();
</script>
