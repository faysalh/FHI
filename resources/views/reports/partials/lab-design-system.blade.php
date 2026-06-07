{{-- Shared design system aligned with Dashboard (lab) --}}
<style>
    :root {
        --rp-primary: #4f46e5;
        --rp-primary-hover: #4338ca;
        --rp-accent: #0d9488;
        --rp-accent-light: #14b8a6;
        --lab-gradient: linear-gradient(135deg, #312e81 0%, #4f46e5 45%, #0d9488 100%);
        --lab-shadow: 0 8px 24px rgba(49, 46, 129, 0.18);
    }

    /* Page hero (standard reports) */
    .page-header,
    .lab-hero {
        background: var(--lab-gradient);
        color: #f8fafc;
        border-radius: 12px;
        padding: 20px 22px;
        margin-bottom: 18px;
        box-shadow: var(--lab-shadow);
    }
    .page-header h1,
    .lab-hero h1 {
        margin: 0;
        font-size: 1.6rem;
        font-weight: 700;
        color: #fff;
        letter-spacing: -0.02em;
    }
    .page-header__top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px 16px;
        width: 100%;
    }
    .page-header__actions {
        margin: 0;
        flex: 0 0 auto;
    }
    .page-header .hint,
    .lab-hero p {
        display: none !important;
        margin: 0;
        font-size: 13px;
        line-height: 1.55;
        max-width: 52rem;
        color: rgba(248, 250, 252, 0.92);
    }
    .page-header .hint a,
    .lab-hero a,
    .lab-hero__links a {
        color: #e0e7ff;
    }
    .page-header .hint a:hover,
    .lab-hero a:hover { color: #fff; }
    .page-header .hint code,
    .lab-hero code {
        background: rgba(255, 255, 255, 0.18);
        color: #f8fafc;
    }
    .lab-hero__badge {
        display: inline-block;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        background: rgba(255, 255, 255, 0.2);
        padding: 3px 8px;
        border-radius: 4px;
        margin-bottom: 8px;
    }
    .lab-hero__links { margin-top: 12px; font-size: 13px; }

    /* Toolbars & filter panels */
    .lab-toolbar,
    .dash-toolbar,
    .toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 12px 20px;
        align-items: flex-end;
        margin-bottom: 16px;
        padding: 12px 14px;
        background: #f8fafc;
        border: 1px solid var(--rp-border);
        border-radius: var(--rp-radius);
    }
    .toolbar {
        display: grid;
        grid-template-columns: 1fr 1fr auto auto;
        align-items: end;
    }
    @media (max-width: 900px) {
        .toolbar { grid-template-columns: 1fr; }
    }
    .lab-toolbar label,
    .dash-toolbar label,
    .toolbar label span {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--rp-muted);
        margin-bottom: 4px;
    }
    .lab-toolbar select,
    .dash-toolbar select { min-width: 220px; max-width: 100%; }

    /* Cards */
    .lab-card,
    .dash-card,
    .card,
    .info-panel,
    .holidays-card,
    .panel {
        background: #fff;
        border: 1px solid var(--rp-border);
        border-radius: var(--rp-radius);
        padding: 16px 18px;
        margin-bottom: 16px;
    }
    .panel { overflow-x: auto; }
    .lab-card { height: 100%; margin-bottom: 0; }
    .lab-card--accent,
    .info-panel {
        border-top: 3px solid #6366f1;
    }
    .info-panel { background: #fff; font-size: 13px; }
    .info-panel strong { font-size: 14px; }
    .lab-card__title,
    .holidays-card__title,
    .card h2,
    .card h3,
    .panel h2 {
        margin: 0 0 8px;
        font-size: 1.05rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .dash-card h2 {
        margin: 0 0 12px;
        font-size: 1.1rem;
        font-weight: 700;
    }
    .lab-card__title span.lab-tag,
    .dash-card__eyebrow {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #6366f1;
        background: #eef2ff;
        padding: 2px 6px;
        border-radius: 4px;
    }
    .dash-card__eyebrow {
        display: inline-block;
        margin: 0 0 4px;
        background: #eef2ff;
    }
    .lab-desc {
        margin: 0 0 14px;
        padding: 10px 12px;
        background: #f8fafc;
        border-left: 3px solid #cbd5e1;
        font-size: 12px;
        color: #475569;
        line-height: 1.55;
        border-radius: 0 6px 6px 0;
    }

    /* Section headings inside cards / setup tabs */
    .section-title {
        margin: 0 0 10px;
        font-size: 1rem;
        font-weight: 700;
        color: var(--rp-text, #0f172a);
    }

    /* Grids */
    .lab-grid {
        display: grid;
        grid-template-columns: repeat(12, 1fr);
        gap: 16px;
    }
    .lab-span-12 { grid-column: span 12; }
    .lab-span-8 { grid-column: span 8; }
    .lab-span-6 { grid-column: span 6; }
    .lab-span-4 { grid-column: span 4; }
    @media (max-width: 960px) {
        .lab-span-8, .lab-span-6, .lab-span-4 { grid-column: span 12; }
    }
    .dash-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
        margin-top: 8px;
    }
    .dash-today-row {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
    }
    .dash-card--wide { grid-column: 1 / -1; }
    .dash-avg-layout {
        display: grid;
        grid-template-columns: minmax(200px, 1fr) minmax(240px, 1.4fr);
        gap: 20px;
        align-items: start;
    }
    @media (max-width: 720px) {
        .dash-avg-layout { grid-template-columns: 1fr; }
    }
    .lab-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media (max-width: 720px) {
        .lab-split { grid-template-columns: 1fr; }
    }
    .summary {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }
    @media (max-width: 900px) {
        .summary { grid-template-columns: 1fr; }
    }

    /* KPIs & metrics */
    .lab-kpi-rows {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .lab-kpi-row + .lab-kpi-row {
        padding-top: 16px;
        border-top: 1px solid var(--rp-border);
    }
    .lab-kpis,
    .dash-metrics {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        gap: 10px;
    }
    .lab-kpi__label,
    .dash-metric__label {
        font-size: 11px;
        color: var(--rp-muted);
        margin: 0 0 2px;
    }
    .lab-kpi__value,
    .dash-metric__value {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        letter-spacing: -0.02em;
    }
    .dash-metric__value { font-size: 1.35rem; }
    .dash-metric__value--sm { font-size: 1.05rem; }
    .lab-kpi__delta {
        font-size: 11px;
        font-weight: 600;
        margin-top: 2px;
    }
    .lab-kpi__delta--up { color: #059669; }
    .lab-kpi__delta--down { color: #dc2626; }
    .lab-kpi__delta--flat { color: var(--rp-muted); }

    /* Progress & charts */
    .lab-progress { margin-top: 12px; }
    .lab-progress__bar {
        height: 8px;
        background: #e2e8f0;
        border-radius: 999px;
        overflow: hidden;
    }
    .lab-progress__fill {
        height: 100%;
        background: linear-gradient(90deg, #6366f1, #14b8a6);
        border-radius: 999px;
        transition: width 0.3s ease;
    }
    .lab-progress__labels {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--rp-muted);
        margin-top: 4px;
    }
    .lab-card--pace {
        display: flex;
        flex-direction: column;
    }
    .lab-pace-compare {
        margin-top: 16px;
        padding: 12px;
        background: #f8fafc;
        border: 1px solid var(--rp-border);
        border-radius: 8px;
        flex: 1;
    }
    .lab-pace-compare__head,
    .lab-pace-compare__row {
        display: grid;
        grid-template-columns: minmax(72px, 1fr) minmax(90px, 1.2fr) minmax(120px, 1.6fr);
        gap: 8px 12px;
        align-items: baseline;
    }
    .lab-pace-compare__head {
        margin-bottom: 8px;
        padding-bottom: 8px;
        border-bottom: 1px solid var(--rp-border);
    }
    .lab-pace-compare__row {
        font-size: 13px;
        padding: 4px 0;
    }
    .lab-pace-compare__row .num {
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    .lab-pace-compare__row .lab-kpi__delta {
        font-size: 11px;
        margin: 0;
    }
    .lab-pace-foot {
        display: flex;
        flex-wrap: wrap;
        gap: 12px 20px;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid var(--rp-border);
        font-size: 12px;
        color: var(--rp-muted);
    }
    .lab-pace-foot strong {
        color: var(--rp-text, #0f172a);
        font-variant-numeric: tabular-nums;
    }
    .lab-chart,
    .dash-chart-wrap {
        position: relative;
        min-height: 220px;
        max-height: 280px;
    }
    .lab-chart canvas,
    .dash-chart-wrap canvas { max-height: 260px; }
    .lab-chart-empty,
    .dash-chart-empty {
        min-height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--rp-muted);
        font-size: 13px;
        text-align: center;
        padding: 12px;
    }

    /* Tables */
    .lab-table,
    .dash-weight-table,
    .holidays-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .lab-table th,
    .lab-table td,
    .dash-weight-table th,
    .dash-weight-table td,
    .holidays-table th,
    .holidays-table td {
        padding: 6px 8px;
        border-bottom: 1px solid var(--rp-border);
        text-align: left;
    }
    .lab-table th.num,
    .lab-table td.num,
    .dash-weight-table th.num,
    .dash-weight-table td.num { text-align: right; }
    .lab-table th,
    .dash-weight-table th,
    .holidays-table th {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        color: var(--rp-muted);
        letter-spacing: 0.03em;
    }
    .lab-table tr.lab-table__sum td {
        font-weight: 700;
        background: #f1f5f9;
        border-top: 2px solid var(--rp-border);
    }
    .dash-weight-table tbody tr:last-child td { border-bottom: none; }
    .holidays-table__actions { text-align: right; white-space: nowrap; }

    /* Loading & misc */
    .lab-loading,
    .dash-loading {
        opacity: 0.5;
        pointer-events: none;
    }

    /* Branding bar */
    .branding-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border: 1px solid var(--rp-border);
        border-radius: var(--rp-radius);
        background: #f8fafc;
        margin-bottom: 16px;
        border-left: 3px solid #6366f1;
    }
    .branding-bar__name { font-size: 15px; font-weight: 700; color: #0f172a; }
    .branding-bar__meta { font-size: 12px; color: #475569; margin-top: 2px; }
    .branding-bar__logo { max-height: 104px; max-width: 240px; object-fit: contain; }

    /* Holidays settings */
    .holidays-form__grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        align-items: end;
    }
    .holidays-form__action { display: flex; align-items: flex-end; }
    .holidays-help { margin: 12px 0 0; font-size: 12px; color: var(--rp-muted); line-height: 1.5; }
    .holidays-year { margin: 16px 0 8px; font-size: 14px; font-weight: 600; color: #475569; }
    .holidays-year:first-of-type { margin-top: 0; }
    .btn-link-danger {
        background: none;
        border: none;
        color: #dc2626;
        cursor: pointer;
        font-size: 13px;
        padding: 0;
        text-decoration: underline;
    }

    /* Hints below hero / tabs (report pages) */
    .page-header ~ .hint,
    .sub-tabs ~ .hint,
    .subtabs ~ .hint {
        margin: 0 0 16px;
        padding: 10px 12px;
        background: #f8fafc;
        border-left: 3px solid #cbd5e1;
        font-size: 12px;
        color: #475569;
        line-height: 1.55;
        border-radius: 0 6px 6px 0;
        max-width: none;
    }
    .page-header ~ .hint a,
    .sub-tabs ~ .hint a,
    .subtabs ~ .hint a {
        color: #4f46e5;
    }

    /* Drilldown & working days (shared report utilities) */
    .working-days-display {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: var(--rp-text);
    }
    .drilldown-trigger {
        border: none;
        background: none;
        padding: 0;
        font: inherit;
        color: #4f46e5;
        cursor: pointer;
        text-decoration: underline;
        text-align: left;
    }
    .drilldown-trigger:hover { color: #4338ca; }
    .drilldown-row td { background: #f8fafc; }
    .drilldown-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .drilldown-table th,
    .drilldown-table td { border: 1px solid var(--rp-border); padding: 6px; }
    .drilldown-loading { color: var(--rp-muted); font-size: 12px; padding: 6px 0; }
    .customer-suggestions li:hover,
    .customer-suggestions li.is-active { background: #eef2ff; }
    .customer-suggestions li.muted-suggest { cursor: default; color: #94a3b8; font-size: 12px; }

    /* Schema diagram */
    .diagram-box {
        background: #0f172a;
        color: #e2e8f0;
        border-radius: var(--rp-radius);
        padding: 12px;
        overflow-x: auto;
        font-family: Consolas, "Courier New", monospace;
        font-size: 12px;
        line-height: 1.6;
    }
        .panel-spaced { margin-bottom: 16px; }
    .search-hits table { font-size: 13px; }
    .search-hits td { word-break: break-word; }

    /* Governorates settings form */
    .gov-editor-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 12px;
        align-items: start;
    }
    .gov-editor-field label {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: var(--rp-muted);
        margin-bottom: 4px;
    }
    .gov-editor-field input,
    .gov-editor-field select { width: 100%; }
    .gov-editor-members { min-height: 168px; }
    .gov-editor-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: flex-end;
    }
    .gov-editor-actions .btn-apply { min-width: 170px; }
</style>
