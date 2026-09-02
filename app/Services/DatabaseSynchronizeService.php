<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

class DatabaseSynchronizeService
{
    /**
     * @return list<string>
     */
    public function workflowSteps(): array
    {
        $steps = config('reporting.database_sync_steps');

        if (! is_array($steps) || $steps === []) {
            return ['dbo.SP_Pda_Sync'];
        }

        $normalized = [];
        foreach ($steps as $step) {
            $step = trim((string) $step);
            if ($step !== '' && $this->isSafeProcedureName($step)) {
                $normalized[] = $step;
            }
        }

        return $normalized !== [] ? $normalized : ['dbo.SP_Pda_Sync'];
    }

    public function procedureLabel(): string
    {
        return implode(' → ', $this->workflowSteps());
    }

    public function connectionName(): string
    {
        return (string) config('reporting.database_sync_connection', 'sqlsrv_write');
    }

    public function isAvailable(): bool
    {
        return DB::connection($this->connectionName())->getDriverName() === 'sqlsrv';
    }

    public function countPendingPdaInvoices(): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        try {
            $row = DB::connection($this->connectionName())->selectOne(
                'SELECT COUNT(*) AS pending_count
                 FROM dbo.tbl_pda_store_title
                 WHERE ISNULL(fld_is_posted, 0) = 0'
            );

            return (int) ($row->pending_count ?? 0);
        } catch (Throwable $e) {
            Log::warning('database_sync.pending_pda_count_failed', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    public function countPendingPdaCustomers(): int
    {
        if (! $this->isAvailable()) {
            return 0;
        }

        try {
            $row = DB::connection($this->connectionName())->selectOne(
                'SELECT COUNT(*) AS pending_count
                 FROM dbo.tbl_pda_customers
                 WHERE ISNULL(fld_is_posted, 0) = 0'
            );

            return (int) ($row->pending_count ?? 0);
        } catch (Throwable $e) {
            Log::warning('database_sync.pending_pda_customers_failed', ['message' => $e->getMessage()]);

            return 0;
        }
    }

    public function countPendingPdaWork(): int
    {
        return $this->countPendingPdaInvoices() + $this->countPendingPdaCustomers();
    }

    public function hasPendingPdaWork(): bool
    {
        return $this->countPendingPdaWork() > 0;
    }

    /**
     * @return list<stdClass>
     */
    public function getAgentOptions(): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        try {
            return DB::connection($this->connectionName())->select(
                'SELECT CAST(fld_agent_id AS NVARCHAR(36)) AS agent_id,
                        LTRIM(RTRIM(CAST(fld_agent_name AS NVARCHAR(200)))) AS agent_name
                 FROM dbo.tbl_agents
                 ORDER BY fld_agent_name'
            );
        } catch (Throwable $e) {
            Log::warning('database_sync.agents_load_failed', ['message' => $e->getMessage()]);

            return [];
        }
    }

    public function resolveAgentId(?string $requestedAgentId = null): string
    {
        $requestedAgentId = trim((string) $requestedAgentId);
        if ($requestedAgentId === '' || strcasecmp($requestedAgentId, 'all') === 0) {
            return '00000000-0000-0000-0000-000000000000';
        }

        if ($requestedAgentId !== '') {
            if (! $this->isUuid($requestedAgentId)) {
                throw new RuntimeException('Choose a valid sync agent.');
            }

            return strtoupper($requestedAgentId);
        }

        $configured = trim((string) config('reporting.database_sync_agent_id', ''));
        if ($configured !== '') {
            if (! $this->isUuid($configured)) {
                throw new RuntimeException('Configured sync agent id is invalid.');
            }

            return strtoupper($configured);
        }

        return '00000000-0000-0000-0000-000000000000';
    }

    /**
     * Import PDA client invoices / customers into the main system (SP_Pda_Sync).
     */
    public function run(?string $username = null, ?string $agentId = null): void
    {
        $connectionName = $this->connectionName();
        $connection = DB::connection($connectionName);

        if ($connection->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Database synchronize is only available when SQL Server is configured.');
        }

        $agentId = $this->resolveAgentId($agentId);
        $steps = $this->workflowSteps();

        $previousLimit = ini_get('max_execution_time');
        if ($previousLimit !== false && (int) $previousLimit > 0 && (int) $previousLimit < 600) {
            set_time_limit(600);
        }

        Log::info('database_sync.started', [
            'steps' => $steps,
            'agent_id' => $agentId,
            'connection' => $connectionName,
            'username' => $username,
        ]);

        try {
            foreach ($steps as $procedure) {
                $this->executeProcedureWithRetry($connection, $procedure, $agentId);
            }
        } catch (Throwable $e) {
            Log::error('database_sync.failed', [
                'steps' => $steps,
                'agent_id' => $agentId,
                'connection' => $connectionName,
                'username' => $username,
                'message' => $e->getMessage(),
                'class' => $e::class,
            ]);

            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Database synchronize failed.';
            if ($this->isDeadlock($e)) {
                $message = 'SQL Server deadlock while syncing. Close AsanMax / AsanAccounting sync on all PCs, wait a minute, then try again. Details: '.$message;
            }

            throw new RuntimeException($message, 0, $e);
        } finally {
            if ($previousLimit !== false && $previousLimit !== '') {
                set_time_limit((int) $previousLimit);
            }
        }

        Log::info('database_sync.completed', [
            'steps' => $steps,
            'agent_id' => $agentId,
            'connection' => $connectionName,
            'username' => $username,
        ]);
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     */
    private function executeProcedureWithRetry($connection, string $procedure, string $agentId): void
    {
        $maxAttempts = max(1, (int) config('reporting.database_sync_deadlock_retries', 3));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->executeProcedure($connection, $procedure, $agentId);

                return;
            } catch (Throwable $e) {
                if (! $this->isDeadlock($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                Log::warning('database_sync.deadlock_retry', [
                    'procedure' => $procedure,
                    'agent_id' => $agentId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'message' => $e->getMessage(),
                ]);

                usleep($attempt * 2_000_000);
            }
        }
    }

    /**
     * @param  \Illuminate\Database\Connection  $connection
     */
    private function executeProcedure($connection, string $procedure, string $agentId): void
    {
        if (! $this->isSafeProcedureName($procedure)) {
            throw new RuntimeException('Invalid synchronize procedure name in configuration.');
        }

        $shortName = str_contains($procedure, '.') ? substr($procedure, strrpos($procedure, '.') + 1) : $procedure;

        if ($shortName === 'SP_Pda_Sync') {
            $connection->statement(
                'EXEC '.$procedure.' @AgentID = ?',
                [$agentId]
            );

            return;
        }

        if (in_array($shortName, ['SP_Sync_Process', 'SP_Sync_Process_POST'], true)) {
            $connection->statement(
                'EXEC '.$procedure.' @AgentId = ?',
                [$agentId]
            );

            return;
        }

        $connection->statement('EXEC '.$procedure);
    }

    private function isSafeProcedureName(string $procedure): bool
    {
        return (bool) preg_match('/^[a-zA-Z_][\w]*(\.[a-zA-Z_][\w]*)?$/', $procedure);
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function isDeadlock(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, '1205')
            || str_contains($message, '40001');
    }
}
