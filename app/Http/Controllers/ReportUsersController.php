<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportUserStoreRequest;
use App\Http\Requests\ReportUserUpdateRequest;
use App\Services\ReportsUsersSqliteService;
use App\Support\ReportAuthSession;
use App\Support\ReportNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReportUsersController extends Controller
{
    public function __construct(
        private readonly ReportsUsersSqliteService $users
    ) {}

    public function index(): View
    {
        $this->users->ensureReady();

        return view('reports.users.index', [
            'users' => $this->users->listUsers(),
            'permissionMatrix' => ReportNavigation::permissionMatrix(),
            'currentUserId' => ReportAuthSession::userId(),
        ]);
    }

    public function store(ReportUserStoreRequest $request): RedirectResponse
    {
        $input = $request->validated();

        try {
            $this->users->createUser(
                (string) $input['username'],
                (string) $input['password'],
                (bool) ($input['is_super_admin'] ?? false),
                is_array($input['report_keys'] ?? null) ? $input['report_keys'] : []
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['username' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('reports.user_create_failed', ['message' => $e->getMessage()]);

            return back()->withInput()->withErrors(['username' => 'Could not create user.']);
        }

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'User created.');
    }

    public function update(ReportUserUpdateRequest $request, int $user): RedirectResponse
    {
        $input = $request->validated();
        $password = trim((string) ($input['password'] ?? ''));
        $password = $password !== '' ? $password : null;

        try {
            $this->users->updateUser(
                $user,
                (bool) ($input['is_super_admin'] ?? false),
                is_array($input['report_keys'] ?? null) ? $input['report_keys'] : [],
                $password
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('reports.user_update_failed', ['user_id' => $user, 'message' => $e->getMessage()]);

            return back()->withErrors(['user' => 'Could not update user.']);
        }

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'User updated.');
    }

    public function destroy(int $user): RedirectResponse
    {
        if ($user === ReportAuthSession::userId()) {
            return back()->withErrors(['user' => 'You cannot delete your own account while signed in.']);
        }

        try {
            $this->users->deleteUser($user);
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('reports.user_delete_failed', ['user_id' => $user, 'message' => $e->getMessage()]);

            return back()->withErrors(['user' => 'Could not delete user.']);
        }

        return redirect()
            ->route('reports.users.index')
            ->with('status', 'User deleted.');
    }
}
