<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportUserStoreRequest;
use App\Http\Requests\ReportUserUpdateRequest;
use App\Repositories\DeliveriesReportRepository;
use App\Repositories\StorageReportRepository;
use App\Services\ReportsUsersSqliteService;
use App\Support\DeliveriesReportAccess;
use App\Support\ReportAuthSession;
use App\Support\ReportNavigation;
use App\Support\StorageReportAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReportUsersController extends Controller
{
    public function __construct(
        private readonly ReportsUsersSqliteService $users,
        private readonly DeliveriesReportRepository $deliveriesRepository,
        private readonly StorageReportRepository $storageRepository,
    ) {}

    public function index(): View
    {
        $this->users->ensureReady();

        return view('reports.users.index', [
            'users' => $this->users->listUsers(),
            'permissionMatrix' => ReportNavigation::permissionMatrix(),
            'currentUserId' => ReportAuthSession::userId(),
            'deliveriesStorageOptions' => $this->deliveriesStorageOptions(),
            'storageStorageOptions' => $this->storageStorageOptions(),
        ]);
    }

    public function store(ReportUserStoreRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $reportKeys = is_array($input['report_keys'] ?? null) ? $input['report_keys'] : [];
        $deliveriesAccess = $this->deliveriesAccessFromValidated($input, $reportKeys);
        $storageAccess = $this->storageAccessFromValidated($input, $reportKeys);

        try {
            $this->users->createUser(
                (string) $input['username'],
                (string) $input['password'],
                (bool) ($input['is_super_admin'] ?? false),
                $reportKeys,
                $deliveriesAccess,
                $storageAccess
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
        $reportKeys = is_array($input['report_keys'] ?? null) ? $input['report_keys'] : [];
        $isSuperAdmin = (bool) ($input['is_super_admin'] ?? false);
        $deliveriesAccess = $this->deliveriesAccessFromValidated($input, $reportKeys);
        $storageAccess = $this->storageAccessFromValidated($input, $reportKeys);

        try {
            $this->users->updateUser(
                $user,
                $isSuperAdmin,
                $reportKeys,
                $password,
                $deliveriesAccess,
                $storageAccess
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('reports.user_update_failed', ['user_id' => $user, 'message' => $e->getMessage()]);

            return back()->withErrors(['user' => 'Could not update user.']);
        }

        if ($user === ReportAuthSession::userId()) {
            $this->refreshCurrentUserSession($user, $isSuperAdmin, $reportKeys);
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

    /**
     * @return list<string>
     */
    private function deliveriesStorageOptions(): array
    {
        try {
            return $this->deliveriesRepository->getStorageOptions();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function storageStorageOptions(): array
    {
        try {
            return $this->storageRepository->getStorageOptions();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $reportKeys
     */
    private function deliveriesAccessFromValidated(array $input, array $reportKeys): ?DeliveriesReportAccess
    {
        if (! in_array('deliveries', $reportKeys, true)) {
            return null;
        }

        return ReportsUsersSqliteService::deliveriesAccessFromInput($input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $reportKeys
     */
    private function storageAccessFromValidated(array $input, array $reportKeys): ?StorageReportAccess
    {
        if (! in_array('storage', $reportKeys, true)) {
            return null;
        }

        return ReportsUsersSqliteService::storageAccessFromInput($input);
    }

    /**
     * @param  list<string>  $reportKeys
     */
    private function refreshCurrentUserSession(int $userId, bool $isSuperAdmin, array $reportKeys): void
    {
        $user = $this->users->findUserById($userId);
        if ($user === null) {
            return;
        }

        $deliveriesAccess = null;
        if (! $isSuperAdmin && in_array('deliveries', $reportKeys, true)) {
            $deliveriesAccess = $this->users->deliveriesAccessForUserId($userId);
        }

        $storageAccess = null;
        if (! $isSuperAdmin && in_array('storage', $reportKeys, true)) {
            $storageAccess = $this->users->storageAccessForUserId($userId);
        }

        ReportAuthSession::login(
            $userId,
            (string) ($user->username ?? ''),
            $isSuperAdmin,
            $isSuperAdmin ? [] : $reportKeys,
            $deliveriesAccess,
            $storageAccess
        );
    }
}
