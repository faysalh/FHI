@php
    $formId = $formId ?? '';
    $fromId = $fromId ?? 'date_from';
    $toId = $toId ?? 'date_to';
    $from2Id = $from2Id ?? null;
    $to2Id = $to2Id ?? null;
@endphp
<script>
(function () {
    var form = document.getElementById(@json($formId));
    if (!form) return;
    var fromInput = document.getElementById(@json($fromId));
    var toInput = document.getElementById(@json($toId));
    var from2Input = @json($from2Id) ? document.getElementById(@json($from2Id)) : null;
    var to2Input = @json($to2Id) ? document.getElementById(@json($to2Id)) : null;

    function pad(n) { return String(n).padStart(2, '0'); }
    function fmtDate(d) {
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    }
    function setPair(fromEl, toEl, start, end) {
        if (!fromEl || !toEl) return;
        fromEl.value = fmtDate(start);
        toEl.value = fmtDate(end);
    }

    function applyQuickRange(kind) {
        var now = new Date();
        var start;
        var end;
        var start2;
        var end2;

        if (kind === 'this-month') {
            start = new Date(now.getFullYear(), now.getMonth(), 1);
            end = now;
        } else if (kind === 'last-30') {
            end = now;
            start = new Date(now);
            start.setDate(start.getDate() - 29);
        } else if (kind === 'last-month') {
            start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            end = new Date(now.getFullYear(), now.getMonth(), 0);
        } else if (kind === 'this-month-vs-last-month') {
            start2 = new Date(now.getFullYear(), now.getMonth(), 1);
            end2 = now;
            start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
            end = new Date(now.getFullYear(), now.getMonth(), 0);
            setPair(from2Input || fromInput, to2Input || toInput, start2, end2);
            setPair(fromInput, toInput, start, end);
            form.submit();
            return;
        } else if (kind === 'last-30-vs-prior-30') {
            end2 = now;
            start2 = new Date(now);
            start2.setDate(start2.getDate() - 29);
            end = new Date(start2);
            end.setDate(end.getDate() - 1);
            start = new Date(end);
            start.setDate(start.getDate() - 29);
            setPair(from2Input || fromInput, to2Input || toInput, start2, end2);
            setPair(fromInput, toInput, start, end);
            form.submit();
            return;
        } else {
            return;
        }

        setPair(fromInput, toInput, start, end);
        form.submit();
    }

    form.querySelectorAll('.pie-quick-date-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            applyQuickRange(btn.getAttribute('data-range'));
        });
    });
})();
</script>
