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
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }
        .kiosk-wrap {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            gap: 16px;
        }
        .kiosk-video-wrap {
            width: min(100%, 640px);
            aspect-ratio: 4/3;
            background: #000;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid var(--surface);
            box-shadow: 0 20px 50px rgba(0,0,0,0.35);
        }
        #kiosk-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transform: scaleX(-1);
        }
        .kiosk-status {
            min-height: 3.5em;
            text-align: center;
            font-size: clamp(18px, 4vw, 28px);
            font-weight: 600;
            max-width: 640px;
            line-height: 1.4;
        }
        .kiosk-status--in { color: var(--accent); }
        .kiosk-status--out { color: var(--accent-out); }
        .kiosk-hint {
            color: var(--muted);
            font-size: 14px;
            text-align: center;
            max-width: 480px;
        }
        .kiosk-loading {
            color: var(--muted);
            font-size: 15px;
        }
    </style>
</head>
<body>
<div class="kiosk-wrap">
    <div class="kiosk-video-wrap">
        <video id="kiosk-video" autoplay muted playsinline></video>
    </div>
    <div id="kiosk-status" class="kiosk-status kiosk-loading">{{ __('Starting camera…') }}</div>
    <p class="kiosk-hint">{{ __('Look at the camera. Only enrolled faces are logged.') }}</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
window.FaceIdKioskConfig = {
    modelsUrl: @json(asset('face-api-models')),
    punchUrl: @json($punchUrl),
    csrfToken: @json(csrf_token()),
    scanIntervalMs: 2000,
    labels: {
        starting: @json(__('Starting camera…')),
        ready: @json(__('Ready')),
        clockIn: @json(__('Clocked in')),
        clockOut: @json(__('Clocked out')),
        welcome: @json(__('Welcome')),
    }
};
</script>
<script src="{{ asset('js/face-id-kiosk.js') }}"></script>
</body>
</html>
