@once
    @push('scripts')
        <script>
            document.querySelectorAll('form.js-pda-locate').forEach(function (form) {
                var status = form.querySelector('[data-locate-status]');
                var saveButton = form.querySelector('button[type="submit"]');

                function fail(message) {
                    form.dataset.locating = '';
                    if (status) {
                        status.textContent = message;
                    }
                    if (saveButton) {
                        saveButton.disabled = false;
                    }
                }

                function applyPosition(pos) {
                    form.querySelector('[name="lat"]').value = String(pos.coords.latitude);
                    form.querySelector('[name="lng"]').value = String(pos.coords.longitude);
                    var accuracy = form.querySelector('[name="accuracy"]');
                    if (accuracy && typeof pos.coords.accuracy === 'number') {
                        accuracy.value = String(pos.coords.accuracy);
                    }
                    form.dataset.located = '1';
                    if (saveButton) {
                        saveButton.disabled = false;
                    }
                    form.submit();
                }

                function requestPosition(highAccuracy) {
                    return new Promise(function (resolve, reject) {
                        navigator.geolocation.getCurrentPosition(resolve, reject, {
                            enableHighAccuracy: highAccuracy,
                            timeout: highAccuracy ? 12000 : 20000,
                            maximumAge: highAccuracy ? 0 : 60000
                        });
                    });
                }

                function locate() {
                    if (form.dataset.locating === '1' || form.dataset.located === '1') {
                        return;
                    }
                    form.dataset.locating = '1';
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
                    if (saveButton) {
                        saveButton.disabled = true;
                    }
                    requestPosition(true).then(applyPosition).catch(function (error) {
                        if (error && error.code === 1) {
                            fail('Location is blocked for this site. Allow Location for this site in the browser, then save again.');
                            return;
                        }
                        if (status) {
                            status.textContent = 'Trying location again…';
                        }
                        requestPosition(false).then(applyPosition).catch(function (second) {
                            if (second && second.code === 1) {
                                fail('Location is blocked for this site. Allow Location for this site in the browser, then save again.');
                                return;
                            }
                            fail('Location could not be read. Turn on location for this phone, then save again.');
                        });
                    });
                }

                if (saveButton) {
                    saveButton.addEventListener('click', function (event) {
                        if (form.dataset.located === '1') {
                            return;
                        }
                        event.preventDefault();
                        locate();
                    });
                }

                form.addEventListener('submit', function (event) {
                    if (form.dataset.located === '1') {
                        return;
                    }
                    event.preventDefault();
                    locate();
                });
            });
        </script>
    @endpush
@endonce
