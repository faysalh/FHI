<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ReportAuthSession
{
    private const AUTH_FLAG = 'reports_admin_authenticated';

    private const USER_ID = 'reports_user_id';

    private const USERNAME = 'reports_username';

    private const SUPER_ADMIN = 'reports_is_super_admin';

    private const ALLOWED_KEYS = 'reports_allowed_keys';

    public static function isAuthenticated(): bool
    {
        if (! (bool) session()->get(self::AUTH_FLAG, false)) {
            return false;
        }

        $userId = self::userId();

        return $userId !== null && $userId > 0;
    }

    public static function userId(): ?int
    {
        $id = session()->get(self::USER_ID);

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function username(): ?string
    {
        $name = session()->get(self::USERNAME);

        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function isSuperAdmin(): bool
    {
        return (bool) session()->get(self::SUPER_ADMIN, false);
    }

    /**
     * @return list<string>
     */
    public static function allowedReportKeys(): array
    {
        $raw = session()->get(self::ALLOWED_KEYS, []);
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key) {
            if (is_string($key) && $key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    public static function canDeleteManufacturing(): bool
    {
        return self::canAccessReport('manufacturing-delete');
    }

    public static function canAccessReport(string $reportKey): bool
    {
        if ($reportKey === '') {
            return true;
        }

        if ($reportKey === 'guide') {
            return self::isAuthenticated();
        }

        if (self::isSuperAdmin()) {
            return true;
        }

        $allowed = self::allowedReportKeys();
        if (in_array($reportKey, $allowed, true)) {
            return true;
        }

        // Legacy permission key from before dashboard-lab became the sole dashboard.
        return $reportKey === 'dashboard-lab' && in_array('dashboard', $allowed, true);
    }

    /**
     * @param  list<string>  $allowedReportKeys
     */
    public static function login(
        int $userId,
        string $username,
        bool $isSuperAdmin,
        array $allowedReportKeys,
        ?DeliveriesReportAccess $deliveriesAccess = null,
        ?StorageReportAccess $storageAccess = null,
    ): void {
        session()->put(self::AUTH_FLAG, true);
        session()->put(self::USER_ID, $userId);
        session()->put(self::USERNAME, $username);
        session()->put(self::SUPER_ADMIN, $isSuperAdmin);
        session()->put(self::ALLOWED_KEYS, array_values(array_unique($allowedReportKeys)));
        if ($isSuperAdmin) {
            DeliveriesReportAccess::forgetSession();
            StorageReportAccess::forgetSession();
        } else {
            if ($deliveriesAccess !== null) {
                DeliveriesReportAccess::putSession($deliveriesAccess);
            } else {
                DeliveriesReportAccess::forgetSession();
            }
            if ($storageAccess !== null) {
                StorageReportAccess::putSession($storageAccess);
            } else {
                StorageReportAccess::forgetSession();
            }
        }
    }

    public static function deliveriesAccess(): DeliveriesReportAccess
    {
        return DeliveriesReportAccess::fromSession();
    }

    public static function storageAccess(): StorageReportAccess
    {
        return StorageReportAccess::fromSession();
    }

    public static function logout(Request $request): void
    {
        $request->session()->forget([
            self::AUTH_FLAG,
            self::USER_ID,
            self::USERNAME,
            self::SUPER_ADMIN,
            self::ALLOWED_KEYS,
            'reports_admin_intended_url',
        ]);
        DeliveriesReportAccess::forgetSession();
        StorageReportAccess::forgetSession();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public static function defaultLandingRouteName(): string
    {
        if (self::isSuperAdmin()) {
            return 'reports.dashboard-lab.index';
        }

        $sections = ReportNavigation::sectionsForUser(self::allowedReportKeys(), false);
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                return (string) $item['route'];
            }
        }

        return 'reports.no-access';
    }
}
