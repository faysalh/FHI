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
        Config::set('reporting.timezone', 'Asia/Baghdad');
        Config::set('app.timezone', 'Asia/Baghdad');
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

    public function test_multi_descriptor_enrollment_averages_and_saves(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $employeeId = $svc->createEmployee('Multi Sample', 'MS1');

        $sets = [
            $this->sampleDescriptor(0.11),
            $this->sampleDescriptor(0.12),
            $this->sampleDescriptor(0.13),
        ];
        $expected = FaceIdSqliteService::averageDescriptors($sets);

        $this->withFaceIdSession()
            ->postJson("/reports/face-id/employees/{$employeeId}/face", [
                'descriptors' => $sets,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $stored = json_decode((string) $svc->findEmployee($employeeId)->face_descriptor, true);
        $this->assertIsArray($stored);
        $this->assertCount(FaceIdSqliteService::DESCRIPTOR_LENGTH, $stored);

        for ($i = 0; $i < FaceIdSqliteService::DESCRIPTOR_LENGTH; $i++) {
            $this->assertEqualsWithDelta($expected[$i], $stored[$i], 0.0001);
        }
    }

    public function test_face_enrollment_validation_returns_structured_json(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $employeeId = $svc->createEmployee('Invalid', null);

        $this->withFaceIdSession()
            ->postJson("/reports/face-id/employees/{$employeeId}/face", [
                'descriptor' => [1, 2, 3],
            ])
            ->assertStatus(422)
            ->assertJson([
                'ok' => false,
            ])
            ->assertJsonStructure(['errors']);
    }

    public function test_kiosk_returns_not_found_for_invalid_token(): void
    {
        $this->get('/attendance/invalid-token-should-not-work')
            ->assertNotFound();

        $this->postJson('/attendance/invalid-token-should-not-work/punch', [
            'descriptor' => $this->sampleDescriptor(0.2),
            'latitude' => 36.1911,
            'longitude' => 44.0091,
        ])->assertNotFound();
    }

    public function test_kiosk_punch_logs_clock_in_and_clock_out(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Sara', 'E002');
        $descriptor = $this->sampleDescriptor(0.3);
        $svc->saveFaceDescriptor($employeeId, $descriptor);

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:30:00', 'Asia/Baghdad'));

        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson([
                'recognized' => true,
                'employee_id' => $employeeId,
                'event_type' => 'clock_in',
                'latitude' => 36.1911,
                'longitude' => 44.0091,
            ]);

        Carbon::setTestNow(Carbon::parse('2026-09-16 14:00:00', 'Asia/Baghdad'));

        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson([
                'recognized' => true,
                'employee_id' => $employeeId,
                'event_type' => 'clock_out',
            ]);

        $logs = $svc->listAttendance('2026-09-16', '2026-09-16');
        $this->assertCount(2, $logs);
        $this->assertSame('clock_out', $logs[0]->event_type);
        $this->assertSame('clock_in', $logs[1]->event_type);
        $this->assertEqualsWithDelta(36.1911, (float) $logs[1]->latitude, 0.0001);
        $this->assertEqualsWithDelta(44.0091, (float) $logs[1]->longitude, 0.0001);

        Carbon::setTestNow();
    }

    public function test_morning_recognition_is_always_check_in(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Morning', 'M1');
        $descriptor = $this->sampleDescriptor(0.31);
        $svc->saveFaceDescriptor($employeeId, $descriptor);

        Carbon::setTestNow(Carbon::parse('2026-09-16 05:00:00', 'Asia/Baghdad'));
        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson(['event_type' => 'clock_in']);

        Carbon::setTestNow(Carbon::parse('2026-09-16 10:59:00', 'Asia/Baghdad'));
        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson(['event_type' => 'clock_in']);

        Carbon::setTestNow(Carbon::parse('2026-09-16 11:00:00', 'Asia/Baghdad'));
        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson(['event_type' => 'clock_out']);

        Carbon::setTestNow(Carbon::parse('2026-09-16 04:59:00', 'Asia/Baghdad'));
        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($descriptor))
            ->assertOk()
            ->assertJson(['event_type' => 'clock_out']);

        Carbon::setTestNow();
    }

    public function test_face_id_tab_permissions_are_enforced(): void
    {
        $this->app['env'] = 'local';

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'logs-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['face-id-logs'],
        ])->get('/reports/face-id?tab=employees')
            ->assertRedirect();

        $this->withSession([
            'reports_admin_authenticated' => true,
            'reports_user_id' => 2,
            'reports_username' => 'logs-only',
            'reports_is_super_admin' => false,
            'reports_allowed_keys' => ['face-id-logs'],
        ])->get('/reports/face-id?tab=logs')
            ->assertOk();
    }

    public function test_kiosk_page_renders_for_valid_token(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();

        $this->get("/attendance/{$token}")
            ->assertOk()
            ->assertSee('Attendance kiosk', false);
    }

    public function test_unknown_descriptor_is_not_logged(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Omar', null);
        $svc->saveFaceDescriptor($employeeId, $this->sampleDescriptor(0.4));

        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'Asia/Baghdad'));

        $this->postJson("/attendance/{$token}/punch", $this->samplePunchPayload($this->sampleDescriptor(0.9)))
            ->assertOk()
            ->assertJson(['recognized' => false]);

        $logs = $svc->listAttendance('2026-09-16', '2026-09-16');
        $this->assertCount(0, $logs);

        Carbon::setTestNow();
    }

    public function test_face_id_key_appears_in_permission_matrix(): void
    {
        $keys = array_column(ReportNavigation::permissionMatrix(), 'key');
        $this->assertNotContains('face-id', $keys);
        $this->assertContains('face-id-employees', $keys);
        $this->assertContains('face-id-logs', $keys);
        $this->assertContains('face-id-kiosk', $keys);
    }

    public function test_attendance_csv_export(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $employeeId = $svc->createEmployee('Test User', 'T1');
        $descriptor = $this->sampleDescriptor(0.5);
        $svc->saveFaceDescriptor($employeeId, $descriptor);
        $svc->processPunch($descriptor, [
            'latitude' => 36.2,
            'longitude' => 44.0,
            'accuracy' => 12.5,
        ]);

        $today = now()->toDateString();

        $this->withFaceIdSession()
            ->get('/reports/face-id/export/csv?date_from='.$today.'&date_to='.$today)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_kiosk_punch_requires_location(): void
    {
        $svc = app(FaceIdSqliteService::class);
        $token = $svc->getKioskToken();
        $employeeId = $svc->createEmployee('Loc Test', 'LOC1');
        $descriptor = $this->sampleDescriptor(0.35);
        $svc->saveFaceDescriptor($employeeId, $descriptor);

        $this->postJson("/attendance/{$token}/punch", [
            'descriptor' => $descriptor,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    /**
     * @return array{descriptor: list<float>, latitude: float, longitude: float, location_accuracy: float}
     */
    private function samplePunchPayload(array $descriptor): array
    {
        return [
            'descriptor' => $descriptor,
            'latitude' => 36.1911,
            'longitude' => 44.0091,
            'location_accuracy' => 15.0,
        ];
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
            'reports_allowed_keys' => ['face-id-employees', 'face-id-logs', 'face-id-kiosk'],
        ]);
    }
}
