<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\FaceIdSqliteService;
use App\Support\ReportNavigation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FaceIdAttendanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('database.connections.face_id_sqlite.database', ':memory:');
        DB::purge('face_id_sqlite');
    }

    public function test_admin_page_requires_face_id_permission(): void
    {
        $this->app['env'] = 'local';

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'sales',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['sales'],
        ])->get('/reports/face-id')
            ->assertForbidden();
    }

    public function test_admin_page_renders_for_authorized_user(): void
    {
        $this->withFaceIdSession()
            ->get('/reports/face-id')
            ->assertOk()
            ->assertSee('Face ID', false)
            ->assertSee('Employees', false);
    }

    public function test_employee_crud_and_face_enrollment(): void
    {
        $svc = app(FaceIdSqliteService::class);

        $this->withFaceIdSession()
            ->post('/reports/face-id/employees', [
                'name' => 'Ahmed Ali',
                'employee_code' => 'E001',
                'tab' => 'employees',
            ])
            ->assertRedirect();

        $employees = $svc->listEmployees();
        $this->assertCount(1, $employees);
        $employeeId = (int) $employees[0]->id;

        $descriptor = $this->sampleDescriptor(0.1);

        $this->withFaceIdSession()
            ->postJson("/reports/face-id/employees/{$employeeId}/face", [
                'descriptor' => $descriptor,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $updated = $svc->findEmployee($employeeId);
        $this->assertNotNull($updated);
        $this->assertNotEmpty($updated->face_descriptor);
    }

    public function test_kiosk_returns_not_found_for_invalid_token(): void
    {
        $this->get('/attendance/invalid-token-should-not-work')
            ->assertNotFound();

        $this->postJson('/attendance/invalid-token-should-not-work/punch', [
            'descriptor' => $this->sampleDescriptor(0.2),
        ])->assertNotFound();
    }

    public function test_kiosk_punch_logs_clock_in_and_clock_out(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Sara', 'E002');
        $descriptor = $this->sampleDescriptor(0.3);
        $svc->saveFaceDescriptor($employeeId, $descriptor);

        $this->postJson("/attendance/{$token}/punch", [
            'descriptor' => $descriptor,
        ])
            ->assertOk()
            ->assertJson([
                'recognized' => true,
                'employee_id' => $employeeId,
                'event_type' => 'clock_in',
            ]);

        Carbon::setTestNow(now()->addSeconds(61));

        $this->postJson("/attendance/{$token}/punch", [
            'descriptor' => $descriptor,
        ])
            ->assertOk()
            ->assertJson([
                'recognized' => true,
                'employee_id' => $employeeId,
                'event_type' => 'clock_out',
            ]);

        $logs = $svc->listAttendance(now()->toDateString(), now()->toDateString());
        $this->assertCount(2, $logs);
        $this->assertSame('clock_in', $logs[1]->event_type);
        $this->assertSame('clock_out', $logs[0]->event_type);

        Carbon::setTestNow();
    }

    public function test_unknown_descriptor_is_not_logged(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Omar', null);
        $svc->saveFaceDescriptor($employeeId, $this->sampleDescriptor(0.4));

        $this->postJson("/attendance/{$token}/punch", [
            'descriptor' => $this->sampleDescriptor(0.9),
        ])
            ->assertOk()
            ->assertJson(['recognized' => false]);

        $logs = $svc->listAttendance(now()->toDateString(), now()->toDateString());
        $this->assertCount(0, $logs);
    }

    public function test_kiosk_page_renders_for_valid_token(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();

        $this->get("/attendance/{$token}")
            ->assertOk()
            ->assertSee('Attendance kiosk', false);
    }

    public function test_face_id_key_appears_in_permission_matrix(): void
    {
        $keys = array_column(ReportNavigation::permissionMatrix(), 'key');
        $this->assertContains('face-id', $keys);
    }

    public function test_attendance_csv_export(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $employeeId = $svc->createEmployee('Test User', 'T1');
        $descriptor = $this->sampleDescriptor(0.5);
        $svc->saveFaceDescriptor($employeeId, $descriptor);
        $svc->processPunch($descriptor);

        $today = now()->toDateString();

        $this->withFaceIdSession()
            ->get('/reports/face-id/export/csv?date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    /**
     * @return list<float>
     */
    private function sampleDescriptor(float $seed): array
    {
        $values = [];
        for ($i = 0; $i < FaceIdSqliteService::DESCRIPTOR_LENGTH; $i++) {
            $values[] = sin($seed + ($i * 0.17));
        }

        return $values;
    }

    private function withFaceIdSession(): static
    {
        return $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 1,
            'reports_username' => 'face-admin',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['face-id'],
        ]);
    }
}
