<button type="button" class="pwa-install" id="pwa-install-button">Install &amp; create shortcut</button>
<div class="pwa-help" id="pwa-install-help" hidden>
    <div class="pwa-help__card" role="dialog" aria-modal="true" aria-labelledby="pwa-install-title">
        <h2 id="pwa-install-title">Add Akre Chicken to the home screen</h2>
        <div data-pwa-ios>
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
        <button type="button" class="btn btn--primary" data-pwa-close>Close</button>
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

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        window.__pwaPrompt = event;
    });

    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        button.remove();
        help.hidden = true;
    });

    function showHelp() {
        help.querySelector('[data-pwa-ios]').hidden = !ios;
        help.querySelector('[data-pwa-android]').hidden = ios;
        help.hidden = false;
    }

    button.addEventListener('click', function () {
        if (!deferredPrompt) {
            showHelp();
            return;
        }
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function () {
            deferredPrompt = null;
        });
    });

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
