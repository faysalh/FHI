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

        $response = $next($request);

        if ($request->isSecure()) {
            $this->forgetInsecureCookie($response, (string) config('session.cookie'), true);
            $this->forgetInsecureCookie($response, 'XSRF-TOKEN', false);
        }

        return $response;
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

    private function forgetInsecureCookie(Response $response, string $name, bool $httpOnly): void
    {
        $response->headers->setCookie(cookie(
            $name,
            '',
            -525600,
            (string) config('session.path', '/'),
            config('session.domain'),
            false,
            $httpOnly,
            false,
            (string) config('session.same_site', 'lax')
        ));
    }
}
