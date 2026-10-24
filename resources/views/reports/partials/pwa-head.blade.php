<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#ffe100">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Akre Chicken">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png">
<script>
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        document.documentElement.classList.add('pwa-standalone');
    }
    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        window.__pwaPrompt = event;
    });
</script>
<style>
    .pwa-standalone .pwa-install { display: none !important; }
    .pwa-install {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 44px;
        padding: 10px 14px;
        border: 0;
        border-radius: 8px;
        background: #4f46e5;
        color: #fff;
        font: inherit;
        font-size: 14px;
        font-weight: 700;
        line-height: 1.2;
        cursor: pointer;
    }
    .report-topbar .pwa-install {
        background: #fff;
        color: #1e1b4b;
    }
    .login-card .pwa-install { width: 100%; margin-top: 10px; }
    .pwa-help[hidden] { display: none; }
    .pwa-help {
        position: fixed;
        inset: 0;
        z-index: 80;
        display: grid;
        place-items: center;
        padding: 16px;
        padding-bottom: calc(16px + env(safe-area-inset-bottom));
        background: rgba(15, 23, 42, 0.45);
    }
    .pwa-help__card {
        width: min(420px, 100%);
        background: #fff;
        color: #0f172a;
        border-radius: 12px;
        padding: 18px;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.25);
    }
    .pwa-help__card h2 { margin: 0 0 8px; font-size: 1.15rem; }
    .pwa-help__card p { margin: 0 0 10px; color: #475569; }
    .pwa-help__card ol { margin: 0 0 14px; padding-left: 20px; }
    .pwa-help__card li { margin: 6px 0; }
    .pwa-help__card .btn { min-height: 44px; }
</style>
