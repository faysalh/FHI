<?php

declare(strict_types=1);

namespace App\Support;

final class ReportGuide
{
    /**
     * @return list<array{
     *     label: string,
     *     topics: list<array{
     *         key: string,
     *         title: string,
     *         summary: string,
     *         bullets: list<string>,
     *         route: string,
     *         route_params?: array<string, mixed>,
     *         open_label?: string
     *     }>
     * }>
     */
    public static function sectionsDefinition(): array
    {
        return [
            [
                'label' => 'Sales & analytics',
                'topics' => [
                    self::topic(
                        'dashboard-lab',
                        'Dashboard',
                        'Governorate snapshot of invoices, sales pace, category breakdowns, and salesman charts for the current month.',
                        [
                            'Pick a saved governorate and optional salesman filter.',
                            'Today vs month-to-date KPIs use working days (Fridays and configured holidays excluded).',
                            'Category tables and charts refresh when filters change.',
                        ],
                        'reports.dashboard-lab.index'
                    ),
                    self::topic(
                        'sales',
                        'Sales',
                        'Posted sales (type S) by client for a date range, with optional category breakdown and exports.',
                        [
                            'Filter by date range, governorate preset, storage, salesmen, clients, and category options. Toggle Quantity, Amount, and Weight columns off if you do not need them.',
                            'Amounts use discount-aware line totals; expand a client row to see line items.',
                            'Export PDF or CSV with the same filters as on screen.',
                        ],
                        'reports.sales.index'
                    ),
                    self::topic(
                        'sales-item-average',
                        'Item average',
                        'Average sales per item across clients, grouped by category with drill-down.',
                        [
                            'Set date range and optional city or category filters.',
                            'Click a category to load items; use exports for the current filters.',
                        ],
                        'reports.sales-item-average.index'
                    ),
                    self::topic(
                        'sales-by-salesman',
                        'By salesman',
                        'Sales totals grouped by salesman for a period.',
                        [
                            'Choose date range and optional filters, then Apply.',
                            'Export PDF/CSV matches the table you see.',
                        ],
                        'reports.sales-by-salesman.index'
                    ),
                    self::topic(
                        'comparison',
                        'Comparison',
                        'Compare two date ranges side by side (quantities, amounts, weights).',
                        [
                            'Enter period A and period B, then Apply.',
                            'Use exports to share the comparison outside the app.',
                        ],
                        'reports.comparison.index'
                    ),
                    self::topic(
                        'cities',
                        'Cities',
                        'Sales aggregated by city, with governorate presets and optional chart view.',
                        [
                            'Main tab: table of cities with totals for your filters.',
                            'Governorate presets come from Settings → Governorates.',
                            'Chart and export actions use the same date and filter context.',
                        ],
                        'reports.cities.index'
                    ),
                    self::topic(
                        'visits',
                        'Visits',
                        'Client visit tracking with optional monthly sales columns.',
                        [
                            'Filter by salesman, dates, and visit status.',
                            'Enable monthly sales to add sales sum columns (posted sales rules).',
                            'PDF export is capped; use CSV for very large result sets.',
                        ],
                        'reports.visits.index'
                    ),
                ],
            ],
            [
                'label' => 'Inventory',
                'topics' => [
                    self::topic(
                        'storage-items',
                        'Items & forecast',
                        'Stock levels, sales averages, pricing tiers, and forecast hints per item.',
                        [
                            'Filter by storage, category, and sales period. Working days exclude Fridays automatically.',
                            'Evaluation tab runs a separate stock evaluation view with its own export.',
                            'Pricing may use PDA stored procedure when configured in the environment.',
                        ],
                        'reports.storage-items.index'
                    ),
                    self::topic(
                        'storage',
                        'Stock snapshot',
                        'Current quantity on hand by storage location and category.',
                        [
                            'Pick storage and category filters, then Apply.',
                            'Export PDF/CSV for the filtered snapshot.',
                        ],
                        'reports.storage.index'
                    ),
                ],
            ],
            [
                'label' => 'Operations',
                'topics' => [
                    self::topic(
                        'deliveries',
                        'Deliveries',
                        'Delivery status per invoice, driver/companion teams, and batch PDF assignment.',
                        [
                            'Report tab: mark delivered / not delivered and assign a daily team per invoice.',
                            'Setup drivers & companions: maintain people and car details (local SQLite).',
                            'Setup daily teams: pair driver + companion per date; delete removes assignments.',
                            'Batch assignment: upload a PDF — all matched invoices move to the selected team, even if previously assigned elsewhere.',
                        ],
                        'reports.deliveries.index'
                    ),
                    self::topic(
                        'invoices',
                        'Invoices',
                        'Search invoices, view lines, print, and export single-invoice PDFs.',
                        [
                            'Filter by date, client, invoice number, and more.',
                            'Select rows for bulk actions where available; open details inline.',
                            'Print and PDF use branding from Settings → Branding.',
                        ],
                        'reports.invoices.index'
                    ),
                    self::topic(
                        'tasks',
                        'Tasks',
                        'Client task notes with browser reminders on invoice days (while this tab is open).',
                        [
                            'Create a task: pick client, enter notes, set repeat interval in minutes.',
                            'Enable browser notifications on the page; polls every minute for due tasks.',
                            'Complete or delete tasks from the row actions; re-open from Completed.',
                        ],
                        'reports.tasks.index'
                    ),
                    self::topic(
                        'damages',
                        'Damages',
                        'Record damaged goods locally with pricing from sales or storage tiers.',
                        [
                            'Damages list: filter by month (default current month), client, item, salesman.',
                            'Packaging tab: pieces per carton drives amount calculation.',
                            'Add entry: search client/item on main DB; amount uses carton price ÷ pieces × damaged count.',
                        ],
                        'reports.damages.index'
                    ),
                    self::topic(
                        'report-assembly',
                        'Assembly order',
                        'Control sort order of categories and items in reports that honor assembly settings.',
                        [
                            'Reorder categories, save, then pick a category to reorder its items.',
                            'Use move-up / move-down icons, then save each list.',
                        ],
                        'reports.report-assembly.index'
                    ),
                ],
            ],
            [
                'label' => 'Settings & tools',
                'topics' => [
                    self::topic(
                        'invoice-branding',
                        'Branding',
                        'Company name, contact details, and logo on report pages and all PDF exports.',
                        [
                            'Upload a logo and set display name, then save.',
                            'The logo appears in the report header bar and at the top of every exported PDF.',
                            'Removals take effect on the next export or print.',
                        ],
                        'reports.invoice-branding.index'
                    ),
                    self::topic(
                        'governorates',
                        'Governorates',
                        'Named city groups used by Dashboard and Cities reports.',
                        [
                            'Create or edit a governorate and attach cities from the main database.',
                            'Saved IDs appear in Sales and Dashboard governorate dropdowns.',
                        ],
                        'reports.governorates.index'
                    ),
                    self::topic(
                        'holidays',
                        'Holidays',
                        'Non-working dates (Eid, etc.) excluded from dashboard working-day math.',
                        [
                            'Add a date and label; remove with the delete icon.',
                            'Fridays are always excluded automatically.',
                        ],
                        'reports.holidays.index'
                    ),
                    self::topic(
                        'schema',
                        'Schema',
                        'Read-only browser for SQL Server tables, columns, samples, and FK explanations.',
                        [
                            'Search tables/columns or pick a table to browse.',
                            'Constraint tab explains a single foreign key by name.',
                        ],
                        'reports.schema.index'
                    ),
                    self::topic(
                        'identifier',
                        'Glossary',
                        'Business terms, column meanings, and sample rows for this reporting app.',
                        [
                            'Use Jump to term to scroll to a definition.',
                            'Complements this how-to guide with data dictionary detail.',
                        ],
                        'reports.identifier.index'
                    ),
                    self::topic(
                        'users',
                        'Users',
                        'Manage who can sign in and which reports they see (administrators only).',
                        [
                            'Create users with passwords and tick report access checkboxes.',
                            'Administrators have full access; others see only allowed nav items.',
                        ],
                        'reports.users.index'
                    ),
                    self::topic(
                        'sqlite-backups',
                        'SQLite backups',
                        'Back up and restore local app databases before server moves or reinstalls (administrators only).',
                        [
                            'Backup all databases as a ZIP, or back up one file at a time.',
                            'Upload a previous .sqlite file or restore from stored backups on this server.',
                            'SQL Server report data is not included — only local app settings.',
                        ],
                        'reports.sqlite-backups.index'
                    ),
                ],
            ],
        ];
    }

    public static function anchor(string $reportKey): string
    {
        return 'guide-'.$reportKey;
    }

    public static function urlFor(string $reportKey): string
    {
        return route('reports.guide.index').'#'.self::anchor($reportKey);
    }

    /**
     * @return list<array{
     *     label: string,
     *     topics: list<array{
     *         key: string,
     *         title: string,
     *         summary: string,
     *         bullets: list<string>,
     *         route: string,
     *         route_params?: array<string, mixed>,
     *         open_label?: string,
     *         can_open: bool
     *     }>
     * }>
     */
    public static function sectionsForSession(): array
    {
        return self::sectionsForUser(
            ReportAuthSession::allowedReportKeys(),
            ReportAuthSession::isSuperAdmin()
        );
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return list<array{
     *     label: string,
     *     topics: list<array{
     *         key: string,
     *         title: string,
     *         summary: string,
     *         bullets: list<string>,
     *         route: string,
     *         route_params?: array<string, mixed>,
     *         open_label?: string,
     *         can_open: bool
     *     }>
     * }>
     */
    public static function sectionsForUser(array $allowedKeys, bool $isSuperAdmin): array
    {
        $allowed = array_flip($allowedKeys);
        $out = [];

        foreach (self::sectionsDefinition() as $section) {
            $topics = [];
            foreach ($section['topics'] as $topic) {
                $key = $topic['key'];
                if (($key === 'users' || $key === 'sqlite-backups') && ! $isSuperAdmin) {
                    continue;
                }
                if (! $isSuperAdmin) {
                    if (! isset($allowed[$key])) {
                        continue;
                    }
                }

                $topic['can_open'] = self::userCanOpenTopic($key, $allowedKeys, $isSuperAdmin);
                $topics[] = $topic;
            }

            if ($topics !== []) {
                $out[] = [
                    'label' => $section['label'],
                    'topics' => $topics,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    public static function userCanOpenTopic(string $key, array $allowedKeys, bool $isSuperAdmin): bool
    {
        if ($isSuperAdmin) {
            return true;
        }

        $allowed = array_flip($allowedKeys);

        return isset($allowed[$key]);
    }

    public static function hasTopic(string $reportKey): bool
    {
        foreach (self::sectionsDefinition() as $section) {
            foreach ($section['topics'] as $topic) {
                if ($topic['key'] === $reportKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $bullets
     * @param  array<string, mixed>  $routeParams
     * @return array{
     *     key: string,
     *     title: string,
     *     summary: string,
     *     bullets: list<string>,
     *     route: string,
     *     route_params?: array<string, mixed>,
     *     open_label?: string
     * }
     */
    private static function topic(
        string $key,
        string $title,
        string $summary,
        array $bullets,
        string $route,
        array $routeParams = [],
        ?string $openLabel = null,
    ): array {
        $topic = [
            'key' => $key,
            'title' => $title,
            'summary' => $summary,
            'bullets' => $bullets,
            'route' => $route,
            'open_label' => $openLabel ?? 'Open '.$title,
        ];

        if ($routeParams !== []) {
            $topic['route_params'] = $routeParams;
        }

        return $topic;
    }
}
