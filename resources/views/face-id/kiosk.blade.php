<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Attendance kiosk') }}</title>
    <style>
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: #22c55e;
            --accent-out: #f59e0b;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body {
            margin: 0;
            min-height: 100%;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        body {
            height: 100%;
            overflow: hidden;
        }
        .kiosk-wrap {
            min-height: 100dvh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding:
                max(8px, env(safe-area-inset-top))
                max(8px, env(safe-area-inset-right))
                max(8px, env(safe-area-inset-bottom))
                max(8px, env(safe-area-inset-left));
            gap: 8px;
            overflow: hidden;
        }
        .kiosk-video-wrap {
            position: relative;
            isolation: isolate;
            width: 100%;
            flex: 1 1 auto;
            min-height: 0;
            background: #000;
            border-radius: 20px;
            overflow: hidden;
            border: 2px solid var(--surface);
            box-shadow: 0 20px 50px rgba(0,0,0,0.35);
        }
        #kiosk-video {
            position: absolute;
            inset: 0;
            z-index: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transform: scaleX(-1);
            -webkit-transform: scaleX(-1);
        }
        .kiosk-punch {
            position: absolute;
            top: 12px;
            left: 50%;
            z-index: 3;
            transform: translate3d(-50%, 0, 0);
            -webkit-transform: translate3d(-50%, 0, 0);
            padding: 7px 16px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.78);
            color: var(--accent);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            pointer-events: none;
        }
        .kiosk-punch.is-out { color: var(--accent-out); }
        .kiosk-guide {
            position: absolute;
            z-index: 2;
            left: 19%;
            top: 13%;
            width: 62%;
            height: 74%;
            color: #e2e8f0;
            pointer-events: none;
            transform: translate3d(0, 0, 0);
            -webkit-transform: translate3d(0, 0, 0);
        }
        .kiosk-guide.is-in { color: #4ade80; }
        .kiosk-guide.is-out { color: #fbbf24; }
        .kiosk-guide-svg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: visible;
        }
        .kiosk-guide-ring-shape {
            fill: none;
            stroke: currentColor;
            stroke-width: 4px;
            vector-effect: non-scaling-stroke;
            stroke-dasharray: 10 8;
            animation: kiosk-march 1.1s linear infinite;
        }
        .kiosk-guide-eye,
        .kiosk-guide-nose,
        .kiosk-guide-chin,
        .kiosk-guide-edge {
            position: absolute;
            background: currentColor;
            border-radius: 99px;
        }
        .kiosk-guide-eye {
            top: 40%;
            width: 16%;
            height: 5px;
            opacity: 0.95;
        }
        .kiosk-guide-eye--l { left: 24%; }
        .kiosk-guide-eye--r { right: 24%; }
        .kiosk-guide-nose {
            left: 50%;
            top: 47%;
            width: 5px;
            height: 14%;
            transform: translateX(-50%);
        }
        .kiosk-guide-chin {
            left: 32%;
            right: 32%;
            bottom: 22%;
            height: 5px;
        }
        .kiosk-guide-edge { opacity: 0.55; }
        .kiosk-guide-edge--top { top: 5%; left: 28%; width: 44%; height: 5px; }
        .kiosk-guide-edge--bottom { bottom: 7%; left: 32%; width: 36%; height: 5px; }
        .kiosk-guide-edge--left { left: 7%; top: 28%; width: 5px; height: 44%; }
        .kiosk-guide-edge--right { right: 7%; top: 28%; width: 5px; height: 44%; }
        .kiosk-guide.is-search .kiosk-guide-edge {
            animation: kiosk-edge 1.5s ease-in-out infinite;
        }
        .kiosk-guide.is-search .kiosk-guide-edge--right { animation-delay: 0.2s; }
        .kiosk-guide.is-search .kiosk-guide-edge--bottom { animation-delay: 0.4s; }
        .kiosk-guide.is-search .kiosk-guide-edge--left { animation-delay: 0.6s; }
        .kiosk-guide.is-warn .kiosk-guide-ring-shape { stroke-dasharray: none; animation: none; }
        .kiosk-guide.is-nudge-left .kiosk-guide-edge--left,
        .kiosk-guide.is-nudge-right .kiosk-guide-edge--right,
        .kiosk-guide.is-nudge-up .kiosk-guide-edge--top,
        .kiosk-guide.is-nudge-down .kiosk-guide-edge--bottom,
        .kiosk-guide.is-ready .kiosk-guide-edge {
            opacity: 1;
            box-shadow: 0 0 14px currentColor;
            animation: none;
        }
        .kiosk-guide.is-ready .kiosk-guide-ring-shape {
            stroke-dasharray: none;
            animation: none;
            filter: drop-shadow(0 0 8px currentColor);
        }
        .kiosk-guide.is-closer .kiosk-guide-svg { animation: kiosk-closer 1s ease-in-out infinite; }
        .kiosk-guide.is-farther .kiosk-guide-svg { animation: kiosk-farther 1s ease-in-out infinite; }
        @keyframes kiosk-march { to { stroke-dashoffset: -36; } }
        @keyframes kiosk-edge {
            0%, 100% { opacity: 0.25; }
            50% { opacity: 1; }
        }
        @keyframes kiosk-closer {
            50% { transform: scale(1.05); }
        }
        @keyframes kiosk-farther {
            50% { transform: scale(0.94); }
        }
        .kiosk-status {
            flex: 0 0 auto;
            min-height: 2.4em;
            text-align: center;
            font-size: clamp(16px, 4.4vw, 26px);
            font-weight: 600;
            max-width: 720px;
            line-height: 1.3;
            padding: 0 8px;
        }
        .kiosk-status--in { color: var(--accent); }
        .kiosk-status--out { color: var(--accent-out); }
        .kiosk-hint {
            color: var(--muted);
            font-size: 13px;
            text-align: center;
            max-width: 640px;
            margin: 0;
            line-height: 1.35;
        }
        @media (min-width: 840px) {
            .kiosk-wrap { justify-content: center; padding: 16px; gap: 12px; }
            .kiosk-video-wrap {
                width: min(100%, 760px);
                height: min(82vh, 980px);
                flex: 0 0 auto;
            }
        }
        .kiosk-loading {
            color: var(--muted);
            font-size: 15px;
        }
    </style>
</head>
<body>
<div class="kiosk-wrap">
    <div class="kiosk-video-wrap" id="kiosk-stage">
        <video id="kiosk-video" autoplay muted playsinline></video>
        <div id="kiosk-punch" class="kiosk-punch">{{ __('Clock in') }}</div>
        <div id="kiosk-guide" class="kiosk-guide is-search is-in">
            <svg class="kiosk-guide-svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <ellipse class="kiosk-guide-ring-shape" cx="50" cy="50" rx="47" ry="47"></ellipse>
            </svg>
            <span class="kiosk-guide-eye kiosk-guide-eye--l"></span>
            <span class="kiosk-guide-eye kiosk-guide-eye--r"></span>
            <span class="kiosk-guide-nose"></span>
            <span class="kiosk-guide-chin"></span>
            <span class="kiosk-guide-edge kiosk-guide-edge--top"></span>
            <span class="kiosk-guide-edge kiosk-guide-edge--right"></span>
            <span class="kiosk-guide-edge kiosk-guide-edge--bottom"></span>
            <span class="kiosk-guide-edge kiosk-guide-edge--left"></span>
        </div>
    </div>
    <div id="kiosk-status" class="kiosk-status kiosk-loading">{{ __('Starting…') }}</div>
    <p class="kiosk-hint">{{ __('Look directly at the camera and hold still. Attendance is logged only when three fresh samples agree at 70% identity confidence or higher. Check-in is recorded from 5:00 to 11:00; other times are checkout. Allow camera and location when prompted — GPS is saved with each punch.') }}</p>
    <p id="kiosk-debug" class="kiosk-hint" hidden></p>
</div>

<script src="/js/face-api.min.js?v=3"></script>
<script>
window.FaceIdKioskConfig = {
    modelsUrl: '/face-api-models',
    punchUrl: @json($punchUrl),
    csrfToken: @json(csrf_token()),
    scanIntervalIdleMs: 2000,
    scanIntervalActiveMs: 500,
    labels: {
        starting: @json(__('Starting…')),
        requestingLocation: @json(__('Allow location access…')),
        requestingCamera: @json(__('Allow camera access…')),
        waitingLocation: @json(__('Finding location… you can stand at the camera.')),
        locationDenied: @json(__('Location permission denied. Enable location for this site in browser settings.')),
        locationUnavailable: @json(__('Location unavailable on this device.')),
        locationTimeout: @json(__('Still finding location. Stay at the camera.')),
        locationUnsupported: @json(__('Geolocation is not supported on this device.')),
        ready: @json(__('Ready')),
        verifying: @json(__('Face locked — verifying 3 samples…')),
        notRecognized: @json(__('Not recognized above 70% — look at the camera and try again')),
        alreadyRecorded: @json(__('Attendance already recorded — please step away')),
        clockIn: @json(__('Clocked in')),
        clockOut: @json(__('Clocked out')),
        clockInWindow: @json(__('Clock in')),
        clockOutWindow: @json(__('Clock out')),
        welcome: @json(__('Welcome')),
    }
};
</script>
<script src="/js/face-id-detector.js?v=6"></script>
<script src="{{ asset('js/face-id-kiosk.js') }}?v=6"></script>
<script>
(function () {
    if (/[?&]debug=1(?:&|$)/.test(window.location.search)) {
        var el = document.getElementById('kiosk-debug');
        if (el) el.hidden = false;
    }
})();
</script>
</body>
</html>
