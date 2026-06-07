<script>
(function () {
    var csrf = @json(csrf_token());
    var dueUrl = @json(route('reports.tasks.due'));
    var tasksUrl = @json(route('reports.tasks.index'));

    async function ensurePermission() {
        if (!('Notification' in window)) {
            alert('This browser does not support notifications.');
            return false;
        }
        if (Notification.permission === 'granted') {
            return true;
        }
        var result = await Notification.requestPermission();

        return result === 'granted';
    }

    async function checkDueTasks() {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }
        try {
            var response = await fetch(dueUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({})
            });
            if (response.status === 403) {
                return;
            }
            if (!response.ok) {
                return;
            }
            var data = await response.json();
            (data.items || []).forEach(function (item) {
                var title = 'Task reminder — ' + (item.client_name || 'Client');
                var body = (item.notes || '').trim();
                if (body.length > 220) {
                    body = body.slice(0, 220) + '...';
                }
                var note = new Notification(title, {
                    body: body,
                    data: { url: tasksUrl }
                });
                note.onclick = function (event) {
                    event.preventDefault();
                    try { window.focus(); } catch (e) {}
                    window.location.href = (note.data && note.data.url) ? note.data.url : tasksUrl;
                    note.close();
                };
            });
        } catch (e) {
            // Silent on purpose to keep UI clean.
        }
    }

    window.reportTasksNotifications = {
        requestPermission: ensurePermission,
        checkNow: checkDueTasks
    };

    setInterval(checkDueTasks, 60 * 1000);
    checkDueTasks();
})();
</script>
