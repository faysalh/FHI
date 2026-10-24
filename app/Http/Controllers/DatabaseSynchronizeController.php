<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DatabaseSynchronizeRunRequest;
use App\Http\Requests\PdaAutoSyncSettingsRequest;
use App\Services\DatabaseSynchronizeService;
use App\Services\PdaAutoSyncService;
use App\Support\ReportAuthSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DatabaseSynchronizeController extends Controller
{
    public function __construct(
        private readonly DatabaseSynchronizeService $synchronize,
        private readonly PdaAutoSyncService $autoSync,
    ) {}

    public function index(): View
    {
        $agents = $this->synchronize->getAgentOptions();

        return view('reports.database-sync.index', [
            'procedureLabel' => $this->synchronize->procedureLabel(),
            'isAvailable' => $this->synchronize->isAvailable(),
            'agents' => $agents,
            'defaultAgentId' => 'all',
            'pendingPdaInvoices' => $this->synchronize->countPendingPdaInvoices(),
            'pendingPdaCustomers' => $this->synchronize->countPendingPdaCustomers(),
            'autoSync' => $this->autoSync->settings(),
        ]);
    }

    public function run(DatabaseSynchronizeRunRequest $request): RedirectResponse
    {
        try {
            $this->synchronize->run(
                ReportAuthSession::username(),
                $request->validated('agent_id')
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('database_sync.controller_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return back()->with('error', 'PDA sync failed. Check logs and try again.');
        }

        return redirect()
            ->route('reports.database-sync.index')
            ->with('status', 'PDA sync completed successfully.');
    }

    public function updateAutoSync(PdaAutoSyncSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->autoSync->saveSettings(
                (bool) ($validated['enabled'] ?? false),
                (int) ($validated['interval_seconds'] ?? 60),
                (string) ($validated['agent_id'] ?? 'all'),
                true,
            );
        } catch (RuntimeException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('reports.database-sync.index')
            ->with('status', 'Automatic PDA sync settings saved.');
    }

    public function runAutoSyncNow(): RedirectResponse
    {
        try {
            $ran = $this->autoSync->runNow();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('pda_auto_sync.run_now_failed', [
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return back()->with('error', 'Automatic PDA sync failed. Check logs and try again.');
        }

        if (! $ran) {
            return redirect()
                ->route('reports.database-sync.index')
                ->with('status', 'No pending PDA work — sync was skipped.');
        }

        return redirect()
            ->route('reports.database-sync.index')
            ->with('status', 'Scheduled PDA sync completed successfully.');
    }
}
