{{-- Shared compact collapsible filter panel (lab-aligned) --}}
<style>
    .filters-panel {
        border: 1px solid var(--rp-border);
        border-radius: var(--rp-radius);
        margin-bottom: 16px;
        background: #f8fafc;
        box-shadow: none;
    }
    .filters-panel summary {
        cursor: pointer;
        padding: 10px 14px;
        font-weight: 600;
        font-size: 13px;
        color: #334155;
        list-style: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        letter-spacing: 0.01em;
    }
    .filters-panel summary::-webkit-details-marker { display: none; }
    .filters-panel summary::after {
        content: '▼';
        font-size: 10px;
        color: #94a3b8;
        transition: transform 0.15s ease;
    }
    .filters-panel[open] summary::after { content: '▲'; }
    .filters-body { padding: 0 14px 14px; }
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px 12px;
        align-items: start;
    }
    @media (max-width: 1100px) {
        .filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) {
        .filters-grid { grid-template-columns: 1fr; }
    }
    .filters-grid .span-2 { grid-column: span 2; }
    @media (max-width: 600px) {
        .filters-grid .span-2 { grid-column: span 1; }
    }
    .filters-grid .span-full { grid-column: 1 / -1; }
    .filter-section {
        grid-column: 1 / -1;
        margin-top: 4px;
        padding-top: 8px;
        border-top: 1px dashed var(--rp-border);
    }
    .filter-section:first-child {
        margin-top: 0;
        padding-top: 0;
        border-top: none;
    }
    .filter-section-title {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: var(--rp-muted);
        margin-bottom: 8px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }
    .filter-section-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px 12px;
        align-items: start;
    }
    @media (max-width: 1100px) {
        .filter-section-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 600px) {
        .filter-section-grid { grid-template-columns: 1fr; }
    }
    .filter-section-grid .span-2 { grid-column: span 2; }
    @media (max-width: 600px) {
        .filter-section-grid .span-2 { grid-column: span 1; }
    }
    .filters-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: flex-end;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid var(--rp-border);
    }
    .filters-panel .filters-grid > div > label:not(.chk-label) {
        font-size: 12px;
        color: var(--rp-muted);
        display: block;
        margin-bottom: 4px;
        font-weight: 600;
    }
    .filters-panel label.chk-label,
    .filters-panel .exclude-categories-box label,
    .filters-panel .customer-search-wrap label,
    .filters-panel .multi-picker-search-wrap label {
        text-transform: none;
        font-weight: normal;
        font-size: 12px;
        color: #334155;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 0;
    }
    .filters-panel .customer-search-wrap label,
    .filters-panel .multi-picker-search-wrap label {
        display: block;
    }
    .filters-panel input,
    .filters-panel select,
    .filters-panel button {
        padding: 7px 10px;
        font-size: 13px;
    }
    .filters-panel button[type="submit"]:not(.btn-icon),
    .filters-panel button.primary:not(.btn-icon),
    .filters-panel .btn-apply:not(.btn-icon) {
        background: #6366f1;
        color: #fff;
        border: none;
        cursor: pointer;
        border-radius: 6px;
        font-weight: 600;
    }
    .filters-panel button[type="submit"]:not(.btn-icon):hover,
    .filters-panel .btn-apply:not(.btn-icon):hover {
        background: #4f46e5;
    }
    .filters-actions .muted { font-size: 12px; align-self: center; }
    .filters-reset-link {
        display: inline-flex;
        align-items: center;
        padding: 7px 12px;
        border-radius: 6px;
        background: #fff;
        color: #475569;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        border: 1px solid var(--rp-border);
        line-height: 1.2;
    }
    .filters-reset-link:hover {
        background: #f1f5f9;
        color: #334155;
        border-color: #cbd5e1;
    }
    .field-help,
    .filter-checkbox .field-help {
        margin: 6px 0 0;
        font-size: 12px;
        color: var(--rp-muted);
        line-height: 1.45;
        font-weight: normal;
    }
    .filter-checkbox label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
    }
    .report-empty {
        margin: 16px 0;
        padding: 14px 16px;
        background: #f8fafc;
        border: 1px dashed var(--rp-border);
        border-radius: 8px;
        font-size: 13px;
        color: var(--rp-muted);
        line-height: 1.5;
    }
    .report-meta {
        margin-top: 10px;
        color: var(--rp-muted);
        font-size: 13px;
    }
    .forecast-legend {
        display: inline-block;
        padding: 0 4px;
        border-radius: 3px;
        font-size: 12px;
    }
    .forecast-legend--critical { background: #fecaca; }
    .forecast-legend--warning { background: #ffedd5; }
    .filters-panel .filters-breakdown {
        grid-column: 1 / -1;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .filters-panel .filters-breakdown.filters-breakdown--inline {
        grid-column: span 2;
        flex-direction: row;
        align-items: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }
    .filters-panel .filter-inline-label {
        font-size: 12px;
        font-weight: 600;
        color: var(--rp-muted);
        white-space: nowrap;
        padding-bottom: 7px;
    }
    .filters-panel .filters-breakdown .chk-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px 16px;
        align-items: center;
    }
    .filters-panel select.select-compact-multi {
        min-height: 0;
        max-height: 4.75rem;
        overflow-y: auto;
    }
    .filters-panel .filters-customer-row .customer-search-wrap input {
        width: 100%;
        max-width: 28rem;
    }
    .filters-panel .filters-customer-row > label .muted {
        font-weight: normal;
        font-size: 11px;
    }
    .filters-panel .exclude-categories-box {
        border: 1px solid var(--rp-border);
        border-radius: 6px;
        padding: 6px 8px;
        max-height: 88px;
        overflow: auto;
        background: #fff;
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
    }
    .filters-panel .exclude-categories-box label {
        font-weight: normal;
        margin-bottom: 0;
        text-transform: none;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        cursor: pointer;
    }
    .filters-panel .exclude-categories-box input { width: auto; margin: 0; padding: 0; }
    .filters-panel .export-row a,
    .filters-actions a.export-link,
    .filters-actions button.export-link {
        display: inline-block;
        padding: 7px 12px;
        border-radius: 6px;
        background: #0d9488;
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
    }
    .filters-panel .export-row a:hover,
    .filters-actions a.export-link:hover {
        background: #0f766e;
        color: #fff;
    }
    .filters-panel .multi-picker,
    .filters-panel .customer-picker {
        position: relative;
        max-width: 100%;
    }
    .filters-panel .multi-picker-chips,
    .filters-panel .customer-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        min-height: 0;
        margin-bottom: 4px;
    }
    .filters-panel .multi-picker-chips:empty,
    .filters-panel .customer-chips:empty {
        display: none;
        margin: 0;
    }
    .filters-panel .multi-picker-chip,
    .filters-panel .customer-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 6px;
        border-radius: 999px;
        font-size: 11px;
        max-width: 100%;
    }
    .filters-panel .multi-picker-chip {
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        color: #3730a3;
    }
    .filters-panel .customer-chip {
        background: #ecfdf5;
        border: 1px solid #6ee7b7;
        color: #065f46;
    }
    .filters-panel .multi-picker-chip span,
    .filters-panel .customer-chip span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 160px;
    }
    .filters-panel .multi-picker-chip button,
    .filters-panel .customer-chip button {
        border: none;
        background: transparent;
        cursor: pointer;
        font-size: 14px;
        line-height: 1;
        padding: 0;
    }
    .filters-panel .multi-picker-search,
    .filters-panel .customer-search-wrap input[type="text"] {
        width: 100%;
        box-sizing: border-box;
        padding: 7px 10px;
        font-size: 13px;
    }
    .filters-panel .multi-picker-suggestions,
    .filters-panel .customer-suggestions {
        display: none;
        position: absolute;
        left: 0;
        right: 0;
        top: 100%;
        z-index: 30;
        margin: 2px 0 0 0;
        padding: 0;
        list-style: none;
        max-height: 180px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid var(--rp-border);
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.1);
    }
    .filters-panel .multi-picker-suggestions.is-open,
    .filters-panel .customer-suggestions.is-open { display: block; }
    .filters-panel .multi-picker-suggestions li,
    .filters-panel .customer-suggestions li {
        padding: 6px 8px;
        cursor: pointer;
        font-size: 13px;
        border-bottom: 1px solid #f1f5f9;
    }
    .filters-panel select[multiple] {
        min-height: 72px;
        max-height: 100px;
        font-size: 12px;
    }
</style>
