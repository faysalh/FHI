<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ItWorkLogSqliteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ItWorkLogSqliteServiceTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = storage_path('app/test-it-work-log-'.uniqid('', true).'.sqlite');
        config(['database.connections.it_work_log_sqlite.database' => $this->dbPath]);

        DB::purge('it_work_log_sqlite');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::purge('it_work_log_sqlite');
        if ($this->dbPath !== '' && File::exists($this->dbPath)) {
            File::delete($this->dbPath);
        }

        parent::tearDown();
    }

    public function test_start_finish_and_list_are_scoped_to_user(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00'));

        $service = new ItWorkLogSqliteService;
        $id = $service->startEntry(7, 'Fix VPN', 'Office router');

        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $service->listEntries(7, 'in_progress'));
        $this->assertCount(0, $service->listEntries(8, 'all'));

        Carbon::setTestNow(Carbon::parse('2026-10-13 10:30:00'));
        $this->assertTrue($service->finishEntry($id, 7));
        $this->assertFalse($service->finishEntry($id, 7));

        $finished = $service->listEntries(7, 'finished');
        $this->assertCount(1, $finished);
        $this->assertSame('Fix VPN', $finished[0]->title);
        $this->assertNotNull($finished[0]->finished_at);
        $this->assertSame('1h 30m', ItWorkLogSqliteService::formatDuration(
            (string) $finished[0]->started_at,
            (string) $finished[0]->finished_at
        ));
    }

    public function test_update_and_delete_require_matching_user(): void
    {
        $service = new ItWorkLogSqliteService;
        $id = $service->startEntry(3, 'Backup', null);

        $this->assertFalse($service->updateEntry($id, 99, 'Nope', null));
        $this->assertTrue($service->updateEntry($id, 3, 'Nightly backup', 'Server A'));
        $this->assertFalse($service->deleteEntry($id, 99));
        $this->assertTrue($service->deleteEntry($id, 3));
        $this->assertCount(0, $service->listEntries(3));
    }
}
