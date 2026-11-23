<button type="button" class="pwa-install" id="pwa-install-button">Install &amp; create shortcut</button>
<div class="pwa-help" id="pwa-install-help" hidden>
    <div class="pwa-help__card" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">
        <h2 id="pwa-install-title">Install Akre Chicken</h2>
        <div data-pwa-ios hidden>
            <p>iPhone and iPad add the shortcut from Safari.</p>
            <ol>
                <li>Tap the Share button at the bottom of Safari.</li>
                <li>Tap Add to Home Screen.</li>
                <li>Tap Add. The Akre Chicken logo is the icon.</li>
            </ol>
        </div>
        <div data-pwa-android hidden>
            <p>Open the browser menu, then tap Install app or Add to Home screen.</p>
        </div>
        <div data-pwa-desktop hidden>
            <p>A shortcut file was saved. Open it from Downloads, or move it onto the desktop. It opens Akre Chicken.</p>
            <p>Chrome and Edge can also install it as an app. Open the browser menu and choose Install Akre Chicken, or use the install icon in the address bar. The chicken logo is the icon.</p>
        </div>
        <div class="pwa-help__actions">
            <button type="button" class="btn" data-pwa-shortcut hidden>Save shortcut again</button>
            <button type="button" class="btn btn--primary" data-pwa-close>Close</button>
        </div>
    </div>
</div>
<script>
(function () {
    var button = document.getElementById('pwa-install-button');
    var help = document.getElementById('pwa-install-help');
    if (!button || !help || button.dataset.bound === '1') {
        return;
    }
    button.dataset.bound = '1';

    var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (standalone) {
        button.remove();
        return;
    }

    var deferredPrompt = window.__pwaPrompt || null;
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var android = /android/i.test(navigator.userAgent);
    var desktop = !ios && !android && !/mobile/i.test(navigator.userAgent);

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        window.__pwaPrompt = event;
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        window.__pwaPrompt = null;
        button.remove();
        help.hidden = true;
    });

    function saveShortcut() {
        var file = '[InternetShortcut]\r\nURL=' + window.location.origin + '/\r\n';
        var blob = new Blob([file], { type: 'application/internet-shortcut' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'Akre Chicken.url';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function showHelp(mode) {
        help.querySelector('[data-pwa-ios]').hidden = mode !== 'ios';
        help.querySelector('[data-pwa-android]').hidden = mode !== 'android';
        help.querySelector('[data-pwa-desktop]').hidden = mode !== 'desktop';
        var again = help.querySelector('[data-pwa-shortcut]');
        if (again) again.hidden = mode !== 'desktop';
        help.hidden = false;
    }

    function installOrExplain() {
        var promptEvent = deferredPrompt || window.__pwaPrompt || null;
        if (promptEvent && typeof promptEvent.prompt === 'function') {
            deferredPrompt = null;
            window.__pwaPrompt = null;
            promptEvent.prompt();
            if (promptEvent.userChoice) {
                promptEvent.userChoice.then(function (choice) {
                    if (choice && choice.outcome === 'accepted') {
                        button.remove();
                        help.hidden = true;
                    }
                }).catch(function () {
                    showHelp(desktop ? 'desktop' : (ios ? 'ios' : 'android'));
                    if (desktop) saveShortcut();
                });
            }
            return;
        }
        if (desktop) {
            saveShortcut();
            showHelp('desktop');
            return;
        }
        showHelp(ios ? 'ios' : 'android');
    }

    button.addEventListener('click', installOrExplain);
    var again = help.querySelector('[data-pwa-shortcut]');
    if (again) again.addEventListener('click', saveShortcut);

    help.addEventListener('click', function (event) {
        if (event.target === help || event.target.closest('[data-pwa-close]')) {
            help.hidden = true;
        }
    });

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
    }
})();
</script>
