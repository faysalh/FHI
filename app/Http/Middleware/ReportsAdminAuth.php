<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ReportAuthSession;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportsAdminAuth
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (app()->environment('testing')) {
            return $next($request);
        }

        if (ReportAuthSession::isAuthenticated()) {
            return $next($request);
        }

        $request->session()->put('reports_admin_intended_url', $request->fullUrl());

        return redirect()->route('login');
    }
}
