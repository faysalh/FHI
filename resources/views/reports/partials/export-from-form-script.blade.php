@php
    $formId = $formId ?? '';
    $linkClass = $linkClass ?? 'report-export-link';
@endphp
<script>
(function () {
    var form = document.getElementById(@json($formId));
    if (!form) return;
    document.querySelectorAll('a.{{ $linkClass }}').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var base = a.getAttribute('data-export-base');
            if (!base) return;
            var params = new URLSearchParams(new FormData(form));
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            window.location.href = base + sep + params.toString();
        });
    });
})();
</script>
