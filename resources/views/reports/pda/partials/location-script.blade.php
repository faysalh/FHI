@once
    @push('scripts')
        <script>
            document.querySelectorAll('form.js-pda-locate').forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (form.dataset.located === '1') {
                        return;
                    }
                    event.preventDefault();
                    var status = form.querySelector('[data-locate-status]');
                    function fail(message) {
                        if (status) {
                            status.textContent = message;
                        }
                    }
                    if (!navigator.geolocation) {
                        fail('This browser cannot share location. The voucher was not saved.');
                        return;
                    }
                    if (status) {
                        status.textContent = 'Waiting for location permission…';
                    }
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        form.querySelector('[name="lat"]').value = String(pos.coords.latitude);
                        form.querySelector('[name="lng"]').value = String(pos.coords.longitude);
                        var accuracy = form.querySelector('[name="accuracy"]');
                        if (accuracy && typeof pos.coords.accuracy === 'number') {
                            accuracy.value = String(pos.coords.accuracy);
                        }
                        form.dataset.located = '1';
                        form.submit();
                    }, function () {
                        fail('Location permission is required before this can be saved.');
                    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
                });
            });
        </script>
    @endpush
@endonce
