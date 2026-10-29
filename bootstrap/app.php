<?php

use App\Http\Middleware\EnsureReportPermission;
use App\Http\Middleware\PreferHttps;
use App\Http\Middleware\ReportsAdminAuth;
use App\Support\ReportAuthSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            PreferHttps::class,
        ]);
        $middleware->preventRequestForgery(except: [
            'login',
        ]);
        $middleware->alias([
            'reports.admin' => ReportsAdminAuth::class,
            'reports.permission' => EnsureReportPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            if (ReportAuthSession::isAuthenticated()) {
                return redirect()->route(ReportAuthSession::defaultLandingRouteName());
            }

            if ($request->is('login')) {
                return redirect()->route('login')->withErrors([
                    'username' => 'The sign-in page expired. Enter your username and password again.',
                ]);
            }

            return $response;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('reports:sqlite-auto-backup')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('reports:pda-auto-sync')
            ->everyMinute()
            ->withoutOverlapping(15)
            ->runInBackground();
    })
    ->create();
