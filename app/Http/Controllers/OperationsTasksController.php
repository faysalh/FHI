<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OperationsTasksSqliteService;
use App\Support\ReportAuthSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsTasksController extends Controller
{
    public function __construct(
        private readonly OperationsTasksSqliteService $tasks
    ) {}

    public function index(Request $request): View
    {
        $this->tasks->ensureReady();
        $clientFilter = trim((string) $request->query('client', ''));
        $statusFilter = (string) $request->query('status', 'all');
        if (! in_array($statusFilter, ['all', 'active', 'completed'], true)) {
            $statusFilter = 'all';
        }
        $sort = (string) $request->query('sort', 'updated');
        if (! in_array($sort, ['updated', 'client', 'created', 'recurrence'], true)) {
            $sort = 'updated';
        }

        $tasks = $this->tasks->listTasks($clientFilter, $statusFilter, $sort);
        $activeTasks = [];
        $completedTasks = [];
        foreach ($tasks as $task) {
            if ((int) ($task->is_active ?? 0) === 1) {
                $activeTasks[] = $task;
            } else {
                $completedTasks[] = $task;
            }
        }

        $invoiceClientsToday = $this->clientsWithInvoiceToday();

        return view('reports.tasks.index', [
            'tasks' => $tasks,
            'activeTasks' => $activeTasks,
            'completedTasks' => $completedTasks,
            'clients' => $this->clientOptions(),
            'invoiceClientsTodayCount' => count($invoiceClientsToday),
            'tasksEligibleTodayCount' => $this->countTasksEligibleToday($activeTasks, $invoiceClientsToday),
            'taskFilters' => [
                'client' => $clientFilter,
                'status' => $statusFilter,
                'sort' => $sort,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_account_id' => ['required', 'string', 'max:64'],
            'client_name' => ['required', 'string', 'max:500'],
            'notes' => ['required', 'string', 'max:3000'],
            'recurrence_minutes' => ['required', 'integer', 'min:'.OperationsTasksSqliteService::MIN_RECURRENCE_MINUTES, 'max:'.OperationsTasksSqliteService::MAX_RECURRENCE_MINUTES],
        ]);

        $this->tasks->createTask(
            trim((string) $data['client_account_id']),
            trim((string) $data['client_name']),
            trim((string) $data['notes']),
            (int) $data['recurrence_minutes']
        );

        return redirect()->route('reports.tasks.index')->with('status', 'Task created.');
    }

    public function update(Request $request, int $task): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['required', 'string', 'max:3000'],
            'recurrence_minutes' => ['required', 'integer', 'min:'.OperationsTasksSqliteService::MIN_RECURRENCE_MINUTES, 'max:'.OperationsTasksSqliteService::MAX_RECURRENCE_MINUTES],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->tasks->updateTask(
            $task,
            trim((string) $data['notes']),
            (int) $data['recurrence_minutes'],
            (bool) ($data['is_active'] ?? false)
        );

        return redirect()->route('reports.tasks.index')->with('status', 'Task updated.');
    }

    public function destroy(int $task): RedirectResponse
    {
        $this->tasks->deleteTask($task);

        return redirect()->route('reports.tasks.index')->with('status', 'Task deleted.');
    }

    public function complete(int $task): RedirectResponse
    {
        $this->tasks->completeTask($task);

        return redirect()->route('reports.tasks.index')->with('status', 'Task marked complete.');
    }

    public function dueNow(): JsonResponse
    {
        if (! ReportAuthSession::canAccessReport('tasks')) {
            abort(403, 'You do not have access to task notifications.');
        }

        $this->tasks->ensureReady();
        $invoiceClientsToday = $this->clientsWithInvoiceToday();
        $due = $this->tasks->findDueTasks($invoiceClientsToday);

        return response()->json([
            'items' => array_map(static function (object $task): array {
                return [
                    'id' => (int) ($task->id ?? 0),
                    'client_name' => (string) ($task->client_name ?? ''),
                    'notes' => (string) ($task->notes ?? ''),
                    'recurrence_minutes' => (int) ($task->recurrence_minutes ?? 60),
                ];
            }, $due),
            'invoice_clients_today' => count($invoiceClientsToday),
            'server_now' => now()->toIso8601String(),
        ]);
    }

    public function ackDueNotifications(Request $request): JsonResponse
    {
        if (! ReportAuthSession::canAccessReport('tasks')) {
            abort(403, 'You do not have access to task notifications.');
        }

        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1', 'max:50'],
            'task_ids.*' => ['integer', 'min:1'],
        ]);

        $this->tasks->markTasksNotified(array_map('intval', $data['task_ids']));

        return response()->json(['ok' => true]);
    }

    /**
     * @param  list<object>  $activeTasks
     * @param  array<string, string>  $clientsWithInvoiceToday
     */
    private function countTasksEligibleToday(array $activeTasks, array $clientsWithInvoiceToday): int
    {
        $index = $this->tasks->normalizeClientIndex($clientsWithInvoiceToday);
        if ($index === []) {
            return 0;
        }

        $count = 0;
        foreach ($activeTasks as $task) {
            $accountId = $this->tasks->normalizeAccountId((string) ($task->client_account_id ?? ''));
            if ($accountId !== '' && isset($index[$accountId])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function clientOptions(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $rows = DB::select(
            "SELECT
                CAST(a.fld_account_id AS NVARCHAR(64)) AS client_id,
                COALESCE(a.fld_account_name, N'') AS client_name
             FROM dbo.tbl_accounting_accounts AS a
             WHERE a.fld_account_id IS NOT NULL
               AND LTRIM(RTRIM(CAST(COALESCE(a.fld_account_name, N'') AS NVARCHAR(500)))) <> N''
             ORDER BY a.fld_account_name ASC, a.fld_account_id ASC"
        );

        return array_values(array_map(
            static fn (object $row): array => [
                'id' => trim((string) ($row->client_id ?? '')),
                'name' => trim((string) ($row->client_name ?? '')),
            ],
            $rows
        ));
    }

    /**
     * @return array<string, string> account_id => client_name
     */
    private function clientsWithInvoiceToday(): array
    {
        if (DB::getDriverName() !== 'sqlsrv') {
            return [];
        }

        $rows = DB::select(
            "SELECT
                CAST(t.fld_account_id_ref AS NVARCHAR(64)) AS client_id,
                MAX(COALESCE(a.fld_account_name, N'')) AS client_name
             FROM dbo.tbl_store_document_titles AS t
             LEFT JOIN dbo.tbl_accounting_accounts AS a
                ON a.fld_account_id = t.fld_account_id_ref
             WHERE CAST(t.fld_store_document_title_date AS date) = CAST(GETDATE() AS date)
               AND ISNULL(t.fld_is_cancelled, 0) = 0
               AND COALESCE(t.fld_type_alias, N'') = N'S'
               AND t.fld_account_id_ref IS NOT NULL
             GROUP BY CAST(t.fld_account_id_ref AS NVARCHAR(64))"
        );

        $map = [];
        foreach ($rows as $row) {
            $id = $this->tasks->normalizeAccountId((string) ($row->client_id ?? ''));
            if ($id === '') {
                continue;
            }
            $name = trim((string) ($row->client_name ?? ''));
            $map[$id] = $name !== '' ? $name : $id;
        }

        return $map;
    }
}

