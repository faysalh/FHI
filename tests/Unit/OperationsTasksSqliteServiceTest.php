<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OperationsTasksSqliteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OperationsTasksSqliteServiceTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = storage_path('app/test-operations-tasks-'.uniqid('', true).'.sqlite');
        config(['database.connections.operations_tasks_sqlite.database' => $this->dbPath]);

        DB::purge('operations_tasks_sqlite');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('operations_tasks_sqlite');
        if ($this->dbPath !== '' && File::exists($this->dbPath)) {
            File::delete($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_find_due_tasks_matches_account_id_case_insensitively(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00'));

        $service = new OperationsTasksSqliteService;
        $service->createTask('abc-123', 'Client A', 'Call client', 60);

        $due = $service->findDueTasks(['ABC-123' => 'Client A From SQL']);

        $this->assertCount(1, $due);
        $this->assertSame('Client A From SQL', $due[0]->client_name);
    }

    public function test_mark_tasks_notified_only_after_ack(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00'));

        $service = new OperationsTasksSqliteService;
        $taskId = $service->createTask('client-1', 'Client', 'Notes', 60);

        $due = $service->findDueTasks(['client-1' => 'Client']);
        $this->assertCount(1, $due);

        $dueAgain = $service->findDueTasks(['client-1' => 'Client']);
        $this->assertCount(1, $dueAgain);

        $service->markTasksNotified([$taskId]);

        Carbon::setTestNow(Carbon::parse('2026-06-14 10:30:00'));
        $this->assertCount(0, $service->findDueTasks(['client-1' => 'Client']));

        Carbon::setTestNow(Carbon::parse('2026-06-14 11:01:00'));
        $this->assertCount(1, $service->findDueTasks(['client-1' => 'Client']));
    }

    public function test_find_due_tasks_skips_clients_without_invoice_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 10:00:00'));

        $service = new OperationsTasksSqliteService;
        $service->createTask('client-1', 'Client', 'Notes', 60);

        $this->assertSame([], $service->findDueTasks([]));
        $this->assertSame([], $service->findDueTasks(['other-client' => 'Other']));
    }
}
