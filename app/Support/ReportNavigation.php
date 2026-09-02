<?php

declare(strict_types=1);

namespace App\Support;

final class ReportNavigation
{
    /**
     * @return list<string>
     */
    public static function allReportKeys(): array
    {
        $keys = [];
        foreach (self::sectionsDefinition() as $section) {
            foreach ($section['items'] as $item) {
                if (! empty($item['super_admin_only'])) {
                    continue;
                }
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }

    /**
     * @return list<array{label: string, items: list<array{key: string, route: string, label: string, title?: string, super_admin_only?: bool}>}>
     */
    public static function sectionsDefinition(): array
    {
        return [
            [
                'label' => 'Sales & analytics',
                'items' => [
                    ['key' => 'dashboard-lab', 'route' => 'reports.dashboard-lab.index', 'label' => 'Dashboard', 'title' => 'Governorate sales and invoice snapshot'],
                    ['key' => 'sales', 'route' => 'reports.sales.index', 'label' => 'Sales', 'title' => 'Sales by client and period'],
                    ['key' => 'sales-item-average', 'route' => 'reports.sales-item-average.index', 'label' => 'Item average', 'title' => 'Sales by item average'],
                    ['key' => 'sales-by-item', 'route' => 'reports.sales-by-item.index', 'label' => 'Sales by item', 'title' => 'Category sales split by client price group'],
                    ['key' => 'sales-by-salesman', 'route' => 'reports.sales-by-salesman.index', 'label' => 'By salesman', 'title' => 'Sales by salesman'],
                    ['key' => 'comparison', 'route' => 'reports.comparison.index', 'label' => 'Comparison', 'title' => 'Compare two periods'],
                    ['key' => 'cities', 'route' => 'reports.cities.index', 'label' => 'Cities', 'title' => 'Sales by city'],
                    ['key' => 'visits', 'route' => 'reports.visits.index', 'label' => 'Visits', 'title' => 'Client visit status'],
                ],
            ],
            [
                'label' => 'Inventory',
                'items' => [
                    ['key' => 'storage-items', 'route' => 'reports.storage-items.index', 'label' => 'Items & forecast', 'title' => 'Inventory, sales averages, and forecast'],
                    ['key' => 'storage-quantity', 'route' => 'reports.storage-quantity.index', 'label' => 'Storage quantity', 'title' => 'Item balance from stored procedures (Normal / Adv)'],
                    ['key' => 'storage', 'route' => 'reports.storage.index', 'label' => 'Stock snapshot', 'title' => 'Current stock by storage and category'],
                ],
            ],
            [
                'label' => 'Finance',
                'items' => [
                    ['key' => 'accounting', 'route' => 'reports.accounting.index', 'label' => 'Accounting', 'title' => 'Daily cash, transfers, and receipt booklets'],
                    ['key' => 'promotions', 'route' => 'reports.promotions.index', 'label' => 'Promotions', 'title' => 'Promoter schedules and client visit assignments'],
                ],
            ],
            [
                'label' => 'Manufacturing Storage',
                'items' => [
                    ['key' => 'manufacturing', 'route' => 'reports.manufacturing.index', 'label' => 'Manufacturing Storage', 'title' => 'Manufacturing items, purchases, exports, and stock'],
                    [
                        'key' => 'manufacturing-delete',
                        'route' => 'reports.manufacturing.index',
                        'label' => 'Delete manufacturing records',
                        'title' => 'Allow deleting items, purchases, and exports (supervisor)',
                        'permission_only' => true,
                    ],
                ],
            ],
            [
                'label' => 'Operations',
                'items' => [
                    ['key' => 'deliveries', 'route' => 'reports.deliveries.index', 'label' => 'Deliveries', 'title' => 'Delivery status and teams'],
                    ['key' => 'invoices', 'route' => 'reports.invoices.index', 'label' => 'Invoices', 'title' => 'Invoice search and details'],
                    ['key' => 'tasks', 'route' => 'reports.tasks.index', 'label' => 'Tasks', 'title' => 'Task notes and invoice-day reminders'],
                    ['key' => 'damages', 'route' => 'reports.damages.index', 'label' => 'Damages', 'title' => 'Damaged goods entries'],
                    ['key' => 'face-id', 'route' => 'reports.face-id.index', 'label' => 'Face ID', 'title' => 'Employee face enrollment and attendance'],
                    ['key' => 'report-assembly', 'route' => 'reports.report-assembly.index', 'label' => 'Assembly order', 'title' => 'Category and item sort priority'],
                ],
            ],
            [
                'label' => 'Settings & tools',
                'items' => [
                    ['key' => 'invoice-branding', 'route' => 'reports.invoice-branding.index', 'label' => 'Branding', 'title' => 'Company name and logo for exports'],
                    ['key' => 'governorates', 'route' => 'reports.governorates.index', 'label' => 'Governorates', 'title' => 'Saved governorate city mappings for Cities and Dashboard'],
                    ['key' => 'holidays', 'route' => 'reports.holidays.index', 'label' => 'Holidays', 'title' => 'Eid and non-working days for dashboard projections'],
                    ['key' => 'schema', 'route' => 'reports.schema.index', 'label' => 'Schema', 'title' => 'Database schema browser'],
                    ['key' => 'guide', 'route' => 'reports.guide.index', 'label' => 'How-to guide', 'title' => 'How each report works'],
                    ['key' => 'identifier', 'route' => 'reports.identifier.index', 'label' => 'Glossary', 'title' => 'Field and term definitions'],
                    ['key' => 'users', 'route' => 'reports.users.index', 'label' => 'Users', 'title' => 'App users and report access', 'super_admin_only' => true],
                    ['key' => 'sqlite-backups', 'route' => 'reports.sqlite-backups.index', 'label' => 'SQLite backups', 'title' => 'Back up and restore local app databases', 'super_admin_only' => true],
                    ['key' => 'database-sync', 'route' => 'reports.database-sync.index', 'label' => 'PDA sync', 'title' => 'Import PDA client invoices into the main system', 'super_admin_only' => true],
                ],
            ],
        ];
    }

    /**
     * Navigation visible to the signed-in user.
     *
     * @return list<array{label: string, items: list<array{key: string, route: string, label: string, title?: string}>}>
     */
    public static function sectionsForSession(): array
    {
        return self::sectionsForUser(
            \App\Support\ReportAuthSession::allowedReportKeys(),
            \App\Support\ReportAuthSession::isSuperAdmin()
        );
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return list<array{label: string, items: list<array{key: string, route: string, label: string, title?: string}>}>
     */
    public static function sectionsForUser(array $allowedKeys, bool $isSuperAdmin): array
    {
        $allowed = array_flip($allowedKeys);
        $out = [];

        foreach (self::sectionsDefinition() as $section) {
            $items = [];
            foreach ($section['items'] as $item) {
                if (! empty($item['super_admin_only']) && ! $isSuperAdmin) {
                    continue;
                }
                if (! empty($item['permission_only'])) {
                    continue;
                }
                if (! $isSuperAdmin) {
                    $key = $item['key'];
                    $isGuide = $key === 'guide';
                    if (! $isGuide && ! isset($allowed[$key])) {
                        continue;
                    }
                }
                $items[] = [
                    'key' => $item['key'],
                    'route' => $item['route'],
                    'label' => $item['label'],
                    'title' => $item['title'] ?? null,
                ];
            }
            if ($items !== []) {
                $out[] = [
                    'label' => $section['label'],
                    'items' => $items,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array{label: string, items: list<array{key: string, route: string, label: string, title?: string}>}>
     */
    public static function sections(): array
    {
        return self::sectionsForSession();
    }

    /**
     * @return list<array{label: string, section_label: string, label: string, title?: string}>
     */
    public static function permissionMatrix(): array
    {
        $matrix = [];
        foreach (self::sectionsDefinition() as $section) {
            foreach ($section['items'] as $item) {
                if (! empty($item['super_admin_only']) || $item['key'] === 'guide') {
                    continue;
                }
                $matrix[] = [
                    'key' => $item['key'],
                    'section_label' => $section['label'],
                    'label' => $item['label'],
                    'title' => $item['title'] ?? null,
                ];
            }
        }

        return $matrix;
    }

    public static function activeKey(?string $routeName = null): string
    {
        $routeName ??= request()->route()?->getName() ?? '';

        return match (true) {
            str_starts_with($routeName, 'reports.dashboard-lab') => 'dashboard-lab',
            str_starts_with($routeName, 'reports.sales-item-average') => 'sales-item-average',
            str_starts_with($routeName, 'reports.sales-by-item') => 'sales-by-item',
            str_starts_with($routeName, 'reports.sales-by-salesman') => 'sales-by-salesman',
            str_starts_with($routeName, 'reports.sales') => 'sales',
            str_starts_with($routeName, 'reports.storage-quantity') => 'storage-quantity',
            str_starts_with($routeName, 'reports.storage-items') => 'storage-items',
            str_starts_with($routeName, 'reports.storage') => 'storage',
            str_starts_with($routeName, 'reports.deliveries') => 'deliveries',
            str_starts_with($routeName, 'reports.invoices') => 'invoices',
            str_starts_with($routeName, 'reports.tasks') => 'tasks',
            str_starts_with($routeName, 'reports.holidays') => 'holidays',
            str_starts_with($routeName, 'reports.governorates') => 'governorates',
            str_starts_with($routeName, 'reports.invoice-branding') => 'invoice-branding',
            str_starts_with($routeName, 'reports.report-assembly') => 'report-assembly',
            str_starts_with($routeName, 'reports.comparison') => 'comparison',
            str_starts_with($routeName, 'reports.cities') => 'cities',
            str_starts_with($routeName, 'reports.visits') => 'visits',
            str_starts_with($routeName, 'reports.damages') => 'damages',
            str_starts_with($routeName, 'reports.accounting') => 'accounting',
            str_starts_with($routeName, 'reports.promotions') => 'promotions',
            str_starts_with($routeName, 'reports.manufacturing') => 'manufacturing',
            str_starts_with($routeName, 'reports.face-id') => 'face-id',
            str_starts_with($routeName, 'reports.schema') => 'schema',
            str_starts_with($routeName, 'reports.guide') => 'guide',
            str_starts_with($routeName, 'reports.identifier') => 'identifier',
            str_starts_with($routeName, 'reports.users') => 'users',
            str_starts_with($routeName, 'reports.sqlite-backups') => 'sqlite-backups',
            str_starts_with($routeName, 'reports.database-sync') => 'database-sync',
            default => '',
        };
    }
}
