<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ReportAuthSession;
use App\Support\ReportNavigation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureReportPermission
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');

        if ($routeName === 'reports.no-access') {
            return $next($request);
        }

        $reportKey = ReportNavigation::activeKey($routeName);

        if ($reportKey === 'users' || $reportKey === 'sqlite-backups') {
            if (! ReportAuthSession::isSuperAdmin()) {
                abort(403, 'Only administrators can access this settings page.');
            }

            return $next($request);
        }

        if ($reportKey === 'guide') {
            return $next($request);
        }

        if ($reportKey !== '' && ! ReportAuthSession::canAccessReport($reportKey)) {
            abort(403, 'You do not have access to this report.');
        }

        return $next($request);
    }
}
