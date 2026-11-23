@once
    @push('scripts')
        <script>
            document.querySelectorAll('form.js-pda-locate').forEach(function (form) {
                var status = form.querySelector('[data-locate-status]');

                function submitButtons() {
                    return form.querySelectorAll('button[type="submit"]');
                }

                function setSubmitEnabled(enabled) {
                    submitButtons().forEach(function (button) {
                        button.disabled = !enabled;
                    });
                }

                function fail(message) {
                    form.dataset.locating = '';
                    form.dataset.saving = '';
                    if (status) {
                        status.textContent = message;
                    }
                    setSubmitEnabled(true);
                }

                function applyPosition(pos) {
                    if (form.dataset.saving === '1') {
                        return;
                    }
                    form.querySelector('[name="lat"]').value = String(pos.coords.latitude);
                    form.querySelector('[name="lng"]').value = String(pos.coords.longitude);
                    var accuracy = form.querySelector('[name="accuracy"]');
                    if (accuracy && typeof pos.coords.accuracy === 'number') {
                        accuracy.value = String(pos.coords.accuracy);
                    }
                    form.dataset.located = '1';
                    form.dataset.locating = '';
                    form.dataset.saving = '1';
                    setSubmitEnabled(false);
                    form.submit();
                }

                function saveWithoutLocation() {
                    if (form.dataset.saving === '1') {
                        return;
                    }
                    form.querySelector('[name="lat"]').value = '';
                    form.querySelector('[name="lng"]').value = '';
                    var accuracy = form.querySelector('[name="accuracy"]');
                    if (accuracy) {
                        accuracy.value = '';
                    }
                    if (status) {
                        status.textContent = 'Location could not be read. Saving without a location.';
                    }
                    form.dataset.located = '1';
                    form.dataset.locating = '';
                    form.dataset.saving = '1';
                    setSubmitEnabled(false);
                    form.submit();
                }

                function locationBlocked(error) {
                    return !!(error && error.code === 1);
                }

                function requestPosition(highAccuracy) {
                    return new Promise(function (resolve, reject) {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: highAccuracy,
                            timeout: highAccuracy ? 12000 : 8000,
                            maximumAge: highAccuracy ? 0 : 60000
                        });
                    });
                }

                function locate() {
                    if (form.dataset.saving === '1' || form.dataset.locating === '1' || form.dataset.located === '1') {
                        return;
                    }
                    form.dataset.locating = '1';
                    setSubmitEnabled(false);
                    if (!window.isSecureContext) {
                        form.dataset.locating = '';
                        fail('The phone will not ask for location on this address. Open the site with https, then save again.');
                        return;
                    }
                    if (!navigator.geolocation) {
                        form.dataset.locating = '';
                        fail('This browser cannot share location. The sale was not saved.');
                        return;
                    }
                    if (status) {
                        status.textContent = 'Allow location when the phone asks…';
                    }
                    requestPosition(true).then(applyPosition).catch(function (error) {
                        if (locationBlocked(error)) {
                            fail('Location is blocked for this site. Allow Location for this site in the browser, then save again.');
                            return;
                        }
                        if (status) {
                            status.textContent = 'Trying location again…';
                        }
                        requestPosition(false).then(applyPosition).catch(function (second) {
                            if (locationBlocked(second)) {
                                fail('Location is blocked for this site. Allow Location for this site in the browser, then save again.');
                                return;
                            }
                            saveWithoutLocation();
                        });
                    });
                }

                form.addEventListener('click', function (event) {
                    var button = event.target && event.target.closest ? event.target.closest('button[type="submit"]') : null;
                    if (!button || !form.contains(button)) {
                        return;
                    }
                    if (form.dataset.saving === '1' || form.dataset.locating === '1' || form.dataset.located === '1') {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }
                    event.preventDefault();
                    locate();
                }, true);

                form.addEventListener('submit', function (event) {
                    if (form.dataset.saving === '1') {
                        event.preventDefault();
                        event.stopPropagation();
                        return;
                    }
                    if (form.dataset.located === '1') {
                        form.dataset.saving = '1';
                        setSubmitEnabled(false);
                        return;
                    }
                    event.preventDefault();
                    locate();
                });
            });
        </script>
    @endpush
@endonce
