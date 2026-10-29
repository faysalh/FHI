<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreferHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirectToHttps($request)) {
            $target = 'https://'.$request->getHost().$request->getRequestUri();

            return redirect()->away($target);
        }

        // Keep the sign-in cookie on HTTPS. Expiring the old HTTP cookie in this
        // same response uses the same name and path, so the browser drops the
        // session that was just created and the login page appears to do nothing.
        if ($request->isSecure()) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }

    private function shouldRedirectToHttps(Request $request): bool
    {
        if ($request->isSecure() || app()->environment('testing')) {
            return false;
        }

        if ($request->is('ReportingApp-RootCA.cer')) {
            return false;
        }

        $host = strtolower($request->getHost());

        return ! in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
