<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly ReportsUsersSqliteService $users
    ) {}

    public function showLoginForm(Request $request): View|RedirectResponse
    {
        if (ReportAuthSession::isAuthenticated()) {
            return redirect()->route(ReportAuthSession::defaultLandingRouteName());
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->users->ensureReady();
            $user = $this->users->authenticate(
                (string) $credentials['username'],
                (string) $credentials['password']
            );
        } catch (Throwable $e) {
            Log::error('reports.login_failed', ['message' => $e->getMessage()]);

            return back()
                ->withInput($request->except('password'))
                ->withErrors(['username' => 'Could not sign in. Check server logs.']);
        }

        if ($user === null) {
            return back()
                ->withInput($request->except('password'))
                ->withErrors(['username' => 'Invalid username or password.']);
        }

        $userId = (int) ($user->id ?? 0);
        $isSuperAdmin = (int) ($user->is_super_admin ?? 0) === 1;
        $allowedKeys = $isSuperAdmin ? [] : $this->users->permissionKeysForUserId($userId);
        $deliveriesAccess = null;
        if (! $isSuperAdmin && in_array('deliveries', $allowedKeys, true)) {
            $deliveriesAccess = $this->users->deliveriesAccessForUserId($userId);
        }
        $storageAccess = null;
        if (! $isSuperAdmin && in_array('storage', $allowedKeys, true)) {
            $storageAccess = $this->users->storageAccessForUserId($userId);
        }

        $request->session()->regenerate();
        ReportAuthSession::login(
            $userId,
            (string) ($user->username ?? ''),
            $isSuperAdmin,
            $allowedKeys,
            $deliveriesAccess,
            $storageAccess
        );

        $intended = (string) $request->session()->pull('reports_admin_intended_url', '');
        if ($intended !== '' && ! $this->isLoginUrl($intended)) {
            return redirect()->to($intended);
        }

        return redirect()->route(ReportAuthSession::defaultLandingRouteName());
    }

    public function logout(Request $request): RedirectResponse
    {
        ReportAuthSession::logout($request);

        return redirect()->route('login');
    }

    public function noAccess(): View
    {
        return view('reports.no-access');
    }

    private function isLoginUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return str_ends_with(rtrim($path, '/'), '/login');
    }
}
