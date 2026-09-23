{{-- Face enrollment modal — include from face-id index on Employees tab --}}
<div id="face-enroll-modal" class="face-enroll-modal" hidden aria-hidden="true">
    <div class="face-enroll-modal__backdrop" data-face-enroll-close></div>
    <div class="face-enroll-modal__panel" role="dialog" aria-modal="true" aria-labelledby="face-enroll-title">
        <h3 id="face-enroll-title">{{ __('Enroll face') }}</h3>
        <p class="hint" id="face-enroll-employee-name"></p>

        <ul class="face-enroll-tips" aria-label="{{ __('Enrollment tips') }}">
            <li>{{ __('Look straight at the camera') }}</li>
            <li>{{ __('Neutral expression — mouth closed, no exaggerated smile') }}</li>
            <li>{{ __('Remove sunglasses or mask; keep hair off forehead and cheeks') }}</li>
            <li>{{ __('One person only in the frame') }}</li>
        </ul>

        <div class="face-enroll-stage" id="face-enroll-stage">
            <video id="face-enroll-video" autoplay muted playsinline webkit-playsinline></video>
            <canvas id="face-enroll-overlay" aria-hidden="true"></canvas>
            <div class="face-enroll-guide-mask" aria-hidden="true">
                <div class="face-enroll-guide-oval"></div>
            </div>
        </div>

        <p class="face-enroll-status" id="face-enroll-status">{{ __('Loading camera…') }}</p>

        <details class="face-enroll-debug-details">
            <summary class="muted">{{ __('Debug info') }}</summary>
            <div id="face-enroll-debug" class="face-enroll-debug" aria-live="polite"></div>
        </details>

        <div class="btn-row face-enroll-actions">
            <button type="button" class="btn" id="face-enroll-start" hidden>{{ __('Start camera') }}</button>
            <button type="button" class="btn" id="face-enroll-capture" disabled>{{ __('Capture face') }}</button>
            <button type="button" class="btn btn-muted" data-face-enroll-close>{{ __('Cancel') }}</button>
        </div>
    </div>
</div>

<style>
    .face-enroll-modal {
        position: fixed;
        inset: 0;
        z-index: 10000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 12px;
        box-sizing: border-box;
    }
    .face-enroll-modal.is-open,
    .face-enroll-modal:not([hidden]) {
        display: flex !important;
    }
    .face-enroll-modal[hidden] {
        display: none !important;
    }
    .face-enroll-modal__backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
    }
    .face-enroll-modal__panel {
        position: relative;
        z-index: 1;
        background: #fff;
        border-radius: 12px;
        padding: 16px;
        max-width: 560px;
        width: 100%;
        max-height: calc(100vh - 24px);
        overflow-y: auto;
        box-shadow: 0 10px 40px rgba(15, 23, 42, 0.25);
    }
    .face-enroll-tips {
        margin: 0 0 12px;
        padding-left: 18px;
        font-size: 13px;
        color: #64748b;
        line-height: 1.45;
    }
    .face-enroll-tips li {
        margin-bottom: 4px;
    }
    .face-enroll-stage {
        position: relative;
        background: #0f172a;
        border-radius: 8px;
        overflow: hidden;
        width: 100%;
        min-height: 360px;
        aspect-ratio: 3 / 4;
    }
    #face-enroll-video {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
        transform: scaleX(-1);
        background: #000;
    }
    #face-enroll-overlay {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        transform: scaleX(-1);
    }
    .face-enroll-guide-mask {
        position: absolute;
        inset: 0;
        pointer-events: none;
    }
    .face-enroll-guide-mask::before {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.35);
        mask-image: radial-gradient(ellipse 55% 70% at 50% 50%, transparent 98%, black 100%);
        -webkit-mask-image: radial-gradient(ellipse 55% 70% at 50% 50%, transparent 98%, black 100%);
    }
    .face-enroll-guide-oval {
        position: absolute;
        left: 50%;
        top: 50%;
        width: 55%;
        height: 70%;
        transform: translate(-50%, -50%);
        border: 2px dashed rgba(255, 255, 255, 0.85);
        border-radius: 50%;
        box-shadow: 0 0 0 9999px transparent;
    }
    .face-enroll-status {
        margin: 10px 0 0;
        font-size: 14px;
        color: #475569;
        min-height: 1.4em;
    }
    .face-enroll-status--ready {
        color: #065f46;
        font-weight: 600;
    }
    .face-enroll-status--warn {
        color: #92400e;
    }
    .face-enroll-status--error {
        color: #b91c1c;
    }
    .face-enroll-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
        margin-top: 12px;
    }
    .face-enroll-debug-details {
        margin-top: 8px;
        font-size: 12px;
    }
    .face-enroll-debug-details summary {
        cursor: pointer;
    }
    .face-enroll-debug {
        margin-top: 6px;
        padding: 8px;
        background: #f8fafc;
        border-radius: 6px;
        font-family: ui-monospace, monospace;
        font-size: 11px;
        line-height: 1.5;
        color: #334155;
        word-break: break-word;
    }
    @media (max-width: 480px) {
        .face-enroll-modal__panel {
            padding: 12px;
        }
        .face-enroll-stage {
            min-height: 320px;
        }
        .face-enroll-tips {
            font-size: 12px;
        }
    }
</style>
