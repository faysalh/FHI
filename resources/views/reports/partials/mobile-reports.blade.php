{{-- Global mobile UX: safe areas, iOS form zoom, table scroll, optional card rows --}}
<style>
    :root {
        --rp-safe-top: env(safe-area-inset-top, 0px);
        --rp-safe-right: env(safe-area-inset-right, 0px);
        --rp-safe-bottom: env(safe-area-inset-bottom, 0px);
        --rp-safe-left: env(safe-area-inset-left, 0px);
    }
    body.report-app {
        padding-left: var(--rp-safe-left);
        padding-right: var(--rp-safe-right);
    }
    .report-topbar {
        padding-top: max(0px, var(--rp-safe-top));
    }
    .report-topbar__inner,
    .report-nav {
        padding-left: max(16px, var(--rp-safe-left));
        padding-right: max(16px, var(--rp-safe-right));
    }
    .report-main {
        padding-bottom: max(16px, var(--rp-safe-bottom));
    }
    .report-app--login {
        padding-top: var(--rp-safe-top);
        padding-bottom: var(--rp-safe-bottom);
        padding-left: max(16px, var(--rp-safe-left));
        padding-right: max(16px, var(--rp-safe-right));
    }
    .sr-only {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
    @media (max-width: 720px) {
        .report-app input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
        .report-app select,
        .report-app textarea {
            font-size: 16px;
        }
        .report-app .btn,
        .report-app .btn-icon {
            font-size: 16px;
        }
    }
    @media (max-width: 720px) {
        .drilldown-row > td,
        .details-card {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .drilldown-table,
        .mini-table {
            min-width: 280px;
        }
    }
    @media (max-width: 540px) {
        .table-scroll--cards {
            overflow-x: visible;
            border: none;
            background: transparent;
        }
        .table-scroll--cards > table {
            display: block;
            width: 100%;
            min-width: 0 !important;
        }
        .table-scroll--cards > table thead {
            display: none;
        }
        .table-scroll--cards > table tbody {
            display: block;
        }
        .table-scroll--cards > table tbody tr {
            display: block;
            margin-bottom: 10px;
            padding: 10px 12px;
            border: 1px solid var(--rp-border);
            border-radius: var(--rp-radius);
            background: #fff;
            box-shadow: var(--rp-shadow);
        }
        .table-scroll--cards > table tbody tr:hover td {
            background: transparent;
        }
        .table-scroll--cards > table tbody td {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            border: none;
            padding: 5px 0;
            text-align: right;
        }
        .table-scroll--cards > table tbody td::before {
            content: attr(data-label);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: var(--rp-muted);
            text-align: left;
            flex: 0 0 42%;
        }
        .table-scroll--cards > table tbody td.num {
            justify-content: space-between;
        }
        .table-scroll--cards > table tbody td[colspan]::before {
            display: none;
        }
        .table-scroll--cards > table tbody td[colspan] {
            display: block;
            text-align: left;
        }
    }
</style>
<script>
(function () {
    var CARD_BLOCK_SELECTOR = 'input, select, textarea, button';

    function isNestedInTable(table) {
        var node = table.parentElement;
        while (node) {
            if (node.tagName === 'TABLE') {
                return true;
            }
            node = node.parentElement;
        }
        return false;
    }

    function tableEligibleForCards(table) {
        if (!table.querySelector('thead')) {
            return false;
        }
        if (table.matches('[data-no-mobile-cards], .category-totals-summary, .promo-schedule-table, .branding, .mini-table, .drilldown-table')) {
            return false;
        }
        return !table.querySelector(CARD_BLOCK_SELECTOR);
    }

    function applyMobileCardLabels(table) {
        var headers = [];
        table.querySelectorAll('thead th').forEach(function (th, index) {
            headers[index] = (th.textContent || '').trim();
        });
        if (headers.length === 0) {
            return;
        }
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.querySelectorAll('td').forEach(function (cell, index) {
                if (cell.hasAttribute('colspan') || cell.hasAttribute('data-label')) {
                    return;
                }
                var label = headers[index] || headers[headers.length - 1] || '';
                if (label !== '') {
                    cell.setAttribute('data-label', label);
                }
            });
        });
    }

    function wrapReportTables(root) {
        if (!root) {
            return;
        }
        root.querySelectorAll('table').forEach(function (table) {
            if (
                table.closest('.table-scroll')
                || isNestedInTable(table)
                || table.matches('[data-no-table-scroll], .category-totals-summary, .promo-schedule-table, .branding')
            ) {
                return;
            }
            var host = document.createElement('div');
            host.className = 'table-scroll';
            table.parentNode.insertBefore(host, table);
            host.appendChild(table);
            if (tableEligibleForCards(table)) {
                applyMobileCardLabels(table);
                host.classList.add('table-scroll--cards');
            }
        });
    }

    function refreshReportTables() {
        wrapReportTables(document.querySelector('.report-container'));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshReportTables);
    } else {
        refreshReportTables();
    }

    window.ReportingMobileTables = {
        refresh: refreshReportTables,
    };

    var container = document.querySelector('.report-container');
    if (container && typeof MutationObserver !== 'undefined') {
        var timer = null;
        var observer = new MutationObserver(function () {
            clearTimeout(timer);
            timer = setTimeout(refreshReportTables, 80);
        });
        observer.observe(container, { childList: true, subtree: true });
    }
})();
</script>
