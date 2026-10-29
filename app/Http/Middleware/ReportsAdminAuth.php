<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReportsAdminAuth
{
    public function __construct(
        private readonly ReportsUsersSqliteService $users
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        if (ReportAuthSession::isAuthenticated()) {
            $this->refreshSessionPermissions();

            return $next($request);
        }

        $request->session()->put('reports_admin_intended_url', $request->fullUrl());

        return redirect()->route('login');
    }

    /**
     * Keep the signed-in user's report keys in sync with SQLite so permission
     * changes take effect without requiring a logout/login cycle.
     */
    private function refreshSessionPermissions(): void
    {
        $userId = ReportAuthSession::userId();
        if ($userId === null || $userId <= 0) {
            return;
        }

        try {
            $user = $this->users->findUserById($userId);
            if ($user === null) {
                return;
            }

            $isSuperAdmin = (int) ($user->is_super_admin ?? 0) === 1;
            $allowedKeys = $isSuperAdmin
                ? []
                : ReportAuthSession::normalizeReportPermissionKeys(
                    $this->users->permissionKeysForUserId($userId)
                );

            $deliveriesAccess = null;
            if (! $isSuperAdmin && in_array('deliveries', $allowedKeys, true)) {
                $deliveriesAccess = $this->users->deliveriesAccessForUserId($userId);
            }

            $storageAccess = null;
            if (! $isSuperAdmin && in_array('storage', $allowedKeys, true)) {
                $storageAccess = $this->users->storageAccessForUserId($userId);
            }

            ReportAuthSession::login(
                $userId,
                (string) ($user->username ?? ReportAuthSession::username() ?? ''),
                $isSuperAdmin,
                $allowedKeys,
                $deliveriesAccess,
                $storageAccess
            );
        } catch (Throwable) {
            // Keep the existing session if the users database is temporarily unavailable.
        }
    }
}
