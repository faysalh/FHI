<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\HrSqliteService;
use App\Support\ReportNavigation;
use App\Support\ReportPdfBranding;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class HrLeaveTest extends TestCase
{
    private string $hrPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hrPath = storage_path('app/test-hr-'.uniqid('', true).'.sqlite');
        $deliveriesPath = storage_path('app/test-hr-deliveries-'.uniqid('', true).'.sqlite');
        $facePath = storage_path('app/test-hr-face-'.uniqid('', true).'.sqlite');

        config([
            'database.connections.hr_sqlite.database' => $this->hrPath,
            'database.connections.deliveries_sqlite.database' => $deliveriesPath,
            'database.connections.face_id_sqlite.database' => $facePath,
        ]);
        DB::purge('hr_sqlite');
        DB::purge('deliveries_sqlite');
        DB::purge('face_id_sqlite');
    }

    protected function tearDown(): void
    {
        DB::purge('hr_sqlite');
        DB::purge('deliveries_sqlite');
        DB::purge('face_id_sqlite');
        foreach (glob(storage_path('app/test-hr-*')) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_existing_report_keys_still_resolve_and_leave_stays_separate(): void
    {
        $this->assertSame('hr-employees', ReportNavigation::activeKey('reports.hr.employees.index'));
        $this->assertSame('hr-leave', ReportNavigation::activeKey('reports.hr.leave.export.pdf'));
        $this->assertSame('face-id', ReportNavigation::activeKey('reports.face-id.index'));
        $this->assertSame('deliveries', ReportNavigation::activeKey('reports.deliveries.index'));
        $this->assertSame('tasks', ReportNavigation::activeKey('reports.tasks.index'));
        $this->assertSame('pda', ReportNavigation::activeKey('reports.pda.index'));

        $assignable = ReportNavigation::assignablePermissionKeys();
        $this->assertContains('hr-employees', $assignable);
        $this->assertContains('hr-leave', $assignable);
        $this->assertContains('hr-leave-approve', $assignable);
        $this->assertContains('face-id-employees', $assignable);
        $this->assertContains('deliveries', $assignable);
        $this->assertContains('tasks', $assignable);
        $this->assertContains('pda', $assignable);

        $legacyFaceId = $this->navLabels(['face-id']);
        $this->assertContains('Face ID', $legacyFaceId);
        $this->assertContains('Market study', $legacyFaceId);
        $this->assertNotContains('Leave', $legacyFaceId);
        $this->assertNotContains('Employees', $legacyFaceId);

        $approver = $this->navLabels(['hr-leave-approve']);
        $this->assertContains('Leave', $approver);
        $this->assertNotContains('Employees', $approver);

        $pda = $this->navLabels(['pda']);
        $this->assertContains('PDA', $pda);
        $this->assertNotContains('Employees', $pda);
        $this->assertNotContains('Leave', $pda);
    }

    public function test_employee_file_leave_approval_and_pdf(): void
    {
        $this->assertSame(1, HrSqliteService::countLeaveDays('2026-11-18', '2026-11-18'));
        $this->assertSame(2, HrSqliteService::countLeaveDays('2026-11-18', '2026-11-20'));
        $this->assertSame(3, HrSqliteService::countLeaveDays('2026-11-17', '2026-11-20'));
        $this->assertSame(120, HrSqliteService::hourlyMinutes('09:00', '11:00'));

        $clerk = $this->sessionFor(['hr-employees', 'hr-leave'], 'clerk');

        $clerk->get('/reports/hr/employees')
            ->assertOk()
            ->assertSee('Employees')
            ->assertSee('Leave')
            ->assertSee('Emergency contact');

        $clerk->post('/reports/hr/employees', [
            'source_ref' => 'other',
            'full_name' => 'Sara Ali',
            'phone' => '07500000000',
            'emergency_name' => 'Omar Ali',
            'emergency_relationship' => 'Brother',
            'emergency_phone' => '07501111111',
            'is_active' => '1',
        ])->assertRedirect(route('reports.hr.employees.index'));

        $hr = app(HrSqliteService::class);
        $employees = $hr->listEmployees();
        $this->assertCount(1, $employees);
        $employeeId = (int) $employees[0]->id;
        $this->assertSame('Sara Ali', $employees[0]->full_name);
        $this->assertSame('07501111111', $employees[0]->emergency_phone);

        $clerk->post('/reports/hr/leave', [
            'employee_id' => $employeeId,
            'leave_type' => 'annual',
            'start_date' => '2026-11-18',
            'end_date' => '2026-11-20',
            'reason' => 'Family travel',
        ])->assertRedirect(route('reports.hr.leave.index'));

        $leaveId = (int) $hr->listLeave()[0]->id;

        $clerk->post('/reports/hr/leave/'.$leaveId.'/decide', [
            'status' => 'approved',
            'decision_note' => 'ok',
        ])->assertForbidden();
        $this->assertSame('pending', $hr->findLeave($leaveId)->status);

        $manager = $this->sessionFor(['hr-leave-approve'], 'manager');
        $manager->post('/reports/hr/leave/'.$leaveId.'/decide', [
            'status' => 'approved',
            'decision_note' => 'Approved for travel',
        ])->assertRedirect(route('reports.hr.leave.index'));

        $saved = $hr->findLeave($leaveId);
        $this->assertSame('approved', $saved->status);
        $this->assertSame('manager', $saved->decided_by_name);

        $manager->get('/reports/hr/leave/'.$leaveId.'/print')
            ->assertOk()
            ->assertSee('Sara Ali')
            ->assertSee('Approved')
            ->assertSee('07501111111')
            ->assertSee('2 days');

        $presented = $hr->presentLeave($saved, []);
        $html = view('reports.hr.leave-pdf', [
            'requestRow' => $presented,
            ...ReportPdfBranding::viewData(),
        ])->render();
        $this->assertStringContainsString('Sara Ali', $html);
        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString('2', $html);

        $manager->get('/reports/hr/leave/'.$leaveId.'/pdf')
            ->assertOk();

        $manager->get('/reports/hr/leave/export/csv')
            ->assertOk();
    }

    public function test_hourly_leave_is_one_date_and_a_time_range(): void
    {
        $clerk = $this->sessionFor(['hr-employees', 'hr-leave'], 'clerk');
        $clerk->post('/reports/hr/employees', [
            'source_ref' => 'other',
            'full_name' => 'Nora',
            'is_active' => '1',
        ])->assertRedirect();

        $employeeId = (int) app(HrSqliteService::class)->listEmployees()[0]->id;
        $clerk->post('/reports/hr/leave', [
            'employee_id' => $employeeId,
            'leave_type' => 'hourly',
            'start_date' => '2026-11-20',
            'start_time' => '09:00',
            'end_time' => '11:00',
            'reason' => 'Appointment',
        ])->assertRedirect(route('reports.hr.leave.index'));

        $saved = app(HrSqliteService::class)->findLeave((int) app(HrSqliteService::class)->listLeave()[0]->id);
        $presented = app(HrSqliteService::class)->presentLeave($saved, []);
        $this->assertSame('2026-11-20', $presented->start_date);
        $this->assertSame('2026-11-20', $presented->end_date);
        $this->assertSame('2 hours', $presented->duration_label);
    }

    public function test_a_configured_holiday_is_left_out_of_the_day_count(): void
    {
        config([
            'reporting.non_working_holidays' => [
                '2026' => ['2026-11-18'],
            ],
            'reporting.non_working_holidays_extra' => '',
        ]);

        $this->assertSame(2, HrSqliteService::countLeaveDays('2026-11-17', '2026-11-20'));
    }

    public function test_the_same_linked_person_cannot_be_added_twice(): void
    {
        $hr = app(HrSqliteService::class);
        $hr->createEmployee([
            'source_type' => HrSqliteService::SOURCE_DRIVER,
            'source_id' => 9,
            'full_name' => 'Driver Nine',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $hr->createEmployee([
            'source_type' => HrSqliteService::SOURCE_DRIVER,
            'source_id' => 9,
            'full_name' => 'Driver Nine',
        ]);
    }

    public function test_sales_user_cannot_open_the_employee_file(): void
    {
        $this->app['env'] = 'local';

        $this->sessionFor(['sales'], 'sales')->get('/reports/hr/employees')->assertForbidden();
        $this->sessionFor(['hr-leave-approve'], 'manager')->get('/reports/hr/leave')->assertOk();
        $this->sessionFor(['hr-leave-approve'], 'manager')->get('/reports/hr/employees')->assertForbidden();
    }

    /**
     * @param  list<string>  $keys
     */
    private function sessionFor(array $keys, string $username): self
    {
        return $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 999999,
            'reports_username' => $username,
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => $keys,
        ]);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function navLabels(array $keys): array
    {
        $labels = [];
        foreach (ReportNavigation::sectionsForUser($keys, false) as $section) {
            foreach ($section['items'] as $item) {
                $labels[] = (string) $item['label'];
            }
        }

        return $labels;
    }
}
