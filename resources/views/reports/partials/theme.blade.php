{{-- Global reporting UI theme --}}
<style>
    :root {
        --rp-bg: #f1f5f9;
        --rp-surface: #ffffff;
        --rp-border: #e2e8f0;
        --rp-text: #0f172a;
        --rp-muted: #64748b;
        --rp-primary: #4f46e5;
        --rp-primary-hover: #4338ca;
        --rp-accent: #0d9488;
        --rp-danger-bg: #fef2f2;
        --rp-danger-text: #991b1b;
        --rp-danger-border: #fecaca;
        --rp-success-bg: #ecfdf5;
        --rp-success-text: #065f46;
        --rp-warn-bg: #fffbeb;
        --rp-warn-text: #92400e;
        --rp-radius: 8px;
        --rp-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        --rp-font: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body.report-app {
        margin: 0;
        font-family: var(--rp-font);
        font-size: 14px;
        line-height: 1.5;
        color: var(--rp-text);
        background: var(--rp-bg);
    }
    a { color: var(--rp-primary); }
    a:hover { color: var(--rp-primary-hover); }
    code {
        font-size: 0.92em;
        background: #f1f5f9;
        padding: 1px 5px;
        border-radius: 4px;
    }
    .report-main { padding: 16px; }
    .report-container {
        max-width: 1280px;
        margin: 0 auto;
        background: var(--rp-surface);
        border: 1px solid var(--rp-border);
        border-radius: 12px;
        padding: 20px 22px;
        box-shadow: var(--rp-shadow);
    }
    .report-container--wide { max-width: 1440px; }
    .page-header { margin-bottom: 0; }
    .hint {
        margin: 0;
        font-size: 13px;
        color: var(--rp-muted);
        line-height: 1.55;
        max-width: 72rem;
    }
    .muted { color: var(--rp-muted); font-size: 12px; }
    .alert {
        padding: 10px 12px;
        border-radius: var(--rp-radius);
        margin-bottom: 12px;
        font-size: 13px;
        border: 1px solid transparent;
    }
    .alert--error {
        background: var(--rp-danger-bg);
        color: var(--rp-danger-text);
        border-color: var(--rp-danger-border);
    }
    .alert--success {
        background: var(--rp-success-bg);
        color: var(--rp-success-text);
        border-color: #a7f3d0;
    }
    .alert--warn {
        background: var(--rp-warn-bg);
        color: var(--rp-warn-text);
        border-color: #fde68a;
    }
    .alert ul { margin: 0; padding-left: 18px; }
    .error { background: var(--rp-danger-bg); color: var(--rp-danger-text); border: 1px solid var(--rp-danger-border); padding: 10px 12px; border-radius: var(--rp-radius); margin-bottom: 12px; font-size: 13px; }
    .status { background: var(--rp-success-bg); color: var(--rp-success-text); border: 1px solid #a7f3d0; padding: 10px 12px; border-radius: var(--rp-radius); margin-bottom: 12px; font-size: 13px; }
    .warn { background: var(--rp-warn-bg); color: var(--rp-warn-text); border: 1px solid #fde68a; padding: 10px 12px; border-radius: var(--rp-radius); margin-bottom: 12px; font-size: 13px; }
    .table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 12px;
        border: 1px solid var(--rp-border);
        border-radius: var(--rp-radius);
    }
    .table-scroll table { margin-bottom: 0; border: 0; }
    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    th, td {
        border-bottom: 1px solid #f1f5f9;
        padding: 8px 10px;
        text-align: left;
        vertical-align: top;
    }
    th {
        background: #f8fafc;
        font-size: 11px;
        font-weight: 600;
        color: var(--rp-muted);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        white-space: nowrap;
    }
    tbody tr:hover td { background: #fafbfc; }
    .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .totals-box {
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        padding: 12px 14px;
        border-radius: var(--rp-radius);
        margin: 12px 0;
    }
    .totals-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 16px 24px;
        align-items: baseline;
        background: #0f172a;
        color: #f8fafc;
        padding: 12px 16px;
        border-radius: var(--rp-radius);
        margin-bottom: 12px;
    }
    .totals-bar .total-item strong { font-size: 18px; font-variant-numeric: tabular-nums; }
    tr.grand-total td {
        background: #eef2ff;
        font-weight: 700;
        border-top: 2px solid #6366f1;
    }
    .totals-bar .total-item span {
        font-size: 11px;
        color: #94a3b8;
        text-transform: uppercase;
        display: block;
        margin-bottom: 2px;
    }
    .sales-totals-with-categories {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 12px;
    }
    .sales-totals-with-categories > .totals-bar {
        flex: 0 0 auto;
        margin-bottom: 0;
        min-width: 220px;
    }
    .category-totals-panel {
        flex: 1 1 360px;
        background: #f8fafc;
        border: 1px solid var(--rp-border, #e2e8f0);
        border-radius: var(--rp-radius);
        padding: 10px 12px;
        max-height: 220px;
        overflow: auto;
    }
    .category-totals-panel__title {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #64748b;
        margin-bottom: 8px;
    }
    table.category-totals-summary {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    table.category-totals-summary th,
    table.category-totals-summary td {
        border: 1px solid var(--rp-border, #e2e8f0);
        padding: 4px 8px;
    }
    table.category-totals-summary th {
        background: #f1f5f9;
        font-size: 10px;
        text-transform: uppercase;
        color: #64748b;
    }
    tr.category-subtotal td {
        background: #f1f5f9;
        border-top: 1px solid #cbd5e1;
    }
    tr.category-group-start td {
        border-top: 2px solid #cbd5e1;
    }
    .sub-tabs, .subtabs {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-bottom: 16px;
    }
    .sub-tabs a, .subtabs a {
        padding: 6px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid var(--rp-border);
    }
    .sub-tabs a:hover, .subtabs a:hover { background: #e2e8f0; }
    .sub-tabs a.active, .subtabs a.active {
        background: #6366f1;
        color: #fff;
        border-color: #6366f1;
    }
    .card {
        margin-bottom: 14px;
    }
    .card h2, .card h3 { font-size: 15px; margin: 0 0 10px; }
    .pagination-wrap { margin-top: 14px; }
    .pagination-wrap nav { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
    .pagination-wrap a,
    .pagination-wrap span {
        display: inline-block;
        padding: 6px 10px;
        border: 1px solid var(--rp-border);
        border-radius: 6px;
        text-decoration: none;
        font-size: 13px;
        color: var(--rp-text);
        background: #fff;
    }
    .pagination-wrap span[aria-current="page"],
    .pagination-wrap .active {
        background: var(--rp-primary);
        color: #fff;
        border-color: var(--rp-primary);
    }
    .pagination-wrap a:hover { background: #f1f5f9; }
    input:not([type="checkbox"]):not([type="radio"]),
    select,
    textarea {
        width: 100%;
        max-width: 100%;
        padding: 7px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 14px;
        font-family: inherit;
        background: #fff;
    }
    input:focus, select:focus, textarea:focus {
        outline: 2px solid #93c5fd;
        outline-offset: 0;
        border-color: var(--rp-primary);
    }
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 7px 12px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
        border: 1px solid transparent;
        cursor: pointer;
        text-decoration: none;
        font-family: inherit;
    }
    .btn--primary { background: var(--rp-primary); color: #fff; }
    .btn--primary:hover { background: var(--rp-primary-hover); color: #fff; }
    .btn--secondary { background: #fff; color: #334155; border-color: #cbd5e1; }
    .btn--secondary:hover { background: #f8fafc; }
    .btn--accent { background: var(--rp-accent); color: #fff; }
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }
    .badge-yes { background: #dcfce7; color: #166534; }
    .badge-no { background: #fee2e2; color: #991b1b; }
    .info-panel { margin-bottom: 16px; }
    .report-how-to-link-wrap { margin: 0 0 12px; }
    .page-header .report-how-to-link-wrap { margin: 0; }
    .report-how-to-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        padding: 6px 12px;
        border-radius: 6px;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        color: #4338ca;
    }
    .page-header .report-how-to-link {
        background: rgba(255, 255, 255, 0.14);
        border-color: rgba(255, 255, 255, 0.35);
        color: #fff;
    }
    .report-how-to-link:hover {
        background: #e0e7ff;
        color: #3730a3;
    }
    .page-header .report-how-to-link:hover {
        background: rgba(255, 255, 255, 0.24);
        color: #fff;
    }
    .inline-action-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
    }
    .inline-action-row select,
    .inline-action-row input:not([type="hidden"]) {
        flex: 1 1 140px;
        min-width: 0;
        width: auto;
        max-width: 100%;
    }
    .btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border-radius: 6px;
        border: 1px solid #cbd5e1;
        background: #fff;
        cursor: pointer;
        transition: background .15s ease, border-color .15s ease, color .15s ease;
        flex-shrink: 0;
    }
    .btn-icon svg {
        width: 18px;
        height: 18px;
        display: block;
    }
    .btn-icon--save { color: #334155; }
    .btn-icon--save:hover { background: #f1f5f9; border-color: #94a3b8; }
    .btn-icon--apply {
        color: #fff;
        background: var(--rp-primary);
        border-color: var(--rp-primary);
    }
    .btn-icon--apply:hover {
        background: var(--rp-primary-hover);
        border-color: var(--rp-primary-hover);
    }
    .btn-icon--complete {
        color: #166534;
        border-color: #86efac;
        background: #f0fdf4;
    }
    .btn-icon--complete:hover { background: #dcfce7; border-color: #4ade80; }
    .btn-icon--delete,
    .btn-icon--clear {
        color: #b91c1c;
        border-color: #fca5a5;
        background: #fef2f2;
    }
    .btn-icon--delete:hover,
    .btn-icon--clear:hover { background: #fee2e2; border-color: #f87171; }
    .btn-icon--add,
    .btn-icon--run {
        color: #1d4ed8;
        border-color: #93c5fd;
        background: #eff6ff;
    }
    .btn-icon--add:hover,
    .btn-icon--run:hover { background: #dbeafe; border-color: #60a5fa; }
    .btn-icon--move-up,
    .btn-icon--move-down,
    .btn-icon--reopen,
    .btn-icon--view,
    .btn-icon--load,
    .btn-icon--explain,
    .btn-icon--notifications,
    .btn-icon--print,
    .btn-icon--logout { color: #334155; }
    .btn-icon--move-up:hover,
    .btn-icon--move-down:hover,
    .btn-icon--reopen:hover,
    .btn-icon--view:hover,
    .btn-icon--load:hover,
    .btn-icon--explain:hover,
    .btn-icon--notifications:hover,
    .btn-icon--print:hover,
    .btn-icon--logout:hover { background: #f1f5f9; border-color: #94a3b8; }
    @media (max-width: 720px) {
        .report-main {
            padding: 8px;
            padding-bottom: max(8px, var(--rp-safe-bottom));
        }
        .report-container { padding: 14px; }
    }
</style>
