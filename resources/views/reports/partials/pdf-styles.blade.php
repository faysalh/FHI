{{-- Unified DomPDF theme aligned with reporting UI (indigo primary, slate text, teal accents). --}}
@include('reports.partials.pdf-arabic-fonts')

@page {
    margin: 16mm 14mm 18mm 14mm;
}

body {
    font-family: 'ReportNotoNaskhArabic', DejaVu Sans, Arial, sans-serif;
    font-size: 10px;
    line-height: 1.45;
    color: #0f172a;
    margin: 0;
    direction: ltr;
    unicode-bidi: normal;
}

/* ── Branding header ── */
@include('reports.partials.pdf-branding-styles')

/* ── Report title block ── */
.pdf-report-head {
    margin: 0 0 12px 0;
    padding: 0 0 10px 0;
    border-bottom: 2px solid #4f46e5;
}
.pdf-report-head--center { text-align: center; }
.pdf-title {
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px 0;
    line-height: 1.25;
}
.pdf-title--sm { font-size: 13px; margin-top: 10px; }
.pdf-meta {
    font-size: 9px;
    color: #64748b;
    line-height: 1.55;
    word-wrap: break-word;
}
.pdf-meta-row { margin: 2px 0; }
.pdf-meta-table { border-collapse: collapse; width: 100%; font-size: 9px; color: #64748b; margin-top: 2px; }
.pdf-meta-table td { border: none; padding: 0; vertical-align: top; text-align: left; }
.pdf-meta-table .pdf-meta-label { white-space: nowrap; padding-right: 6px !important; width: 1%; color: #475569; font-weight: 600; }

/* Legacy aliases (older templates) */
h1 { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 6px 0; line-height: 1.25; }
h2 { font-size: 13px; font-weight: 700; color: #0f172a; margin: 10px 0 6px 0; }
.meta { font-size: 9px; color: #64748b; line-height: 1.55; margin-bottom: 12px; word-wrap: break-word; }
.title { font-size: 16px; font-weight: 700; text-align: center; margin: 6px 0 8px 0; color: #0f172a; }

/* ── KPI / summary bars ── */
.pdf-summary-bar,
.totals-summary {
    background: #0f172a;
    color: #f8fafc;
    padding: 8px 12px;
    border-radius: 6px;
    margin: 0 0 12px 0;
    font-size: 9px;
    line-height: 1.5;
}
.pdf-summary-bar strong,
.totals-summary strong { color: #e2e8f0; }

/* ── Data tables ── */
table,
table.pdf-table,
table.lines {
    width: 100%;
    border-collapse: collapse;
    direction: ltr;
    table-layout: fixed;
    font-family: 'ReportNotoNaskhArabic', DejaVu Sans, Arial, sans-serif;
}
table.lines { table-layout: auto; max-width: 100%; }

th, td {
    border: 1px solid #e2e8f0;
    padding: 4px 6px;
    text-align: left;
    word-wrap: break-word;
    vertical-align: top;
}
th {
    background: #eef2ff;
    color: #3730a3;
    font-size: 9px;
    font-weight: 700;
    border-color: #c7d2fe;
}
th.month-en { font-family: DejaVu Sans, Arial, sans-serif; font-weight: 700; }

td.num,
.num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-family: DejaVu Sans, Arial, sans-serif;
}
td.center,
.center { text-align: center; }
th.idx-col, td.idx-col,
th.date-col, td.date-col { white-space: nowrap; width: 1%; }

/* Row emphasis */
tr.category-subtotal td {
    background: #f8fafc;
    font-weight: 700;
    border-top: 1px solid #cbd5e1;
    color: #334155;
}
tfoot td,
tr.grand-total td,
.grand-total td {
    background: #f1f5f9;
    font-weight: 700;
    border-top: 2px solid #4f46e5;
    color: #0f172a;
}
tr.city-summary td {
    background: #f8fafc;
    font-weight: 700;
    border-top: 1px solid #e2e8f0;
}
td.summary-label {
    background: #eef2ff;
    color: #3730a3;
    font-weight: 700;
}
tr.city-summary td.visit-yes,
tr.city-summary td.visit-no,
tr.city-summary td.num {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-weight: 700;
}

/* Status & semantic colors */
td.visit-yes, td.ok {
    background: #ecfdf5;
    color: #065f46;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-weight: 600;
}
td.visit-no {
    background: #fffbeb;
    color: #92400e;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-weight: 600;
}
td.no {
    background: #fef2f2;
    color: #991b1b;
    font-family: DejaVu Sans, Arial, sans-serif;
    font-weight: 600;
}
.pos { color: #059669; font-weight: 700; }
.neg { color: #dc2626; font-weight: 700; }
.neu { color: #64748b; font-weight: 700; }
.sep-left { border-left: 3px solid #4f46e5 !important; }
.growth-row td { font-size: 9px; font-weight: 700; color: #475569; background: #f8fafc; }

/* Forecast highlights (storage items) */
tr.forecast-below-5 td { background-color: #fecaca; }
tr.forecast-below-10 td { background-color: #ffedd5; }

/* Chart / notes */
.chart-box { margin-top: 8px; overflow: visible; }
.note, .pdf-note {
    font-size: 8px;
    color: #64748b;
    margin-top: 10px;
    line-height: 1.45;
    max-width: 100%;
}

/* Footer */
.pdf-footer {
    margin-top: 14px;
    padding-top: 8px;
    border-top: 1px solid #e2e8f0;
    font-size: 8px;
    color: #94a3b8;
    text-align: center;
}
