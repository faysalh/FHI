<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PdaAutoSyncService
{
    private const SETTINGS_RELATIVE = 'app/pda-auto-sync.json';

    private const LOOP_BUDGET_SECONDS = 55;

    public function __construct(
        private readonly DatabaseSynchronizeService $synchronize,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     interval_seconds: int,
     *     agent_id: string,
     *     skip_when_empty: bool,
     *     last_run_at: ?string,
     *     last_error: ?string,
     *     last_pending_count: int
     * }
     */
    public function settings(): array
    {
        $defaults = [
            'enabled' => false,
            'interval_seconds' => 60,
            'agent_id' => 'all',
            'skip_when_empty' => true,
            'last_run_at' => null,
            'last_error' => null,
            'last_pending_count' => 0,
        ];

        $path = $this->settingsPath();
        if (! File::exists($path)) {
            return $defaults;
        }

        $raw = json_decode((string) File::get($path), true);
        if (! is_array($raw)) {
            return $defaults;
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'interval_seconds' => $this->normalizeInterval((int) ($raw['interval_seconds'] ?? 60)),
            'agent_id' => $this->normalizeAgentId((string) ($raw['agent_id'] ?? 'all')),
            'skip_when_empty' => (bool) ($raw['skip_when_empty'] ?? true),
            'last_run_at' => isset($raw['last_run_at']) ? (string) $raw['last_run_at'] : null,
            'last_error' => isset($raw['last_error']) ? (string) $raw['last_error'] : null,
            'last_pending_count' => (int) ($raw['last_pending_count'] ?? 0),
        ];
    }

    /**
     * @return array{enabled: bool, interval_seconds: int, agent_id: string, skip_when_empty: bool}
     */
    public function saveSettings(bool $enabled, int $intervalSeconds, string $agentId, bool $skipWhenEmpty = true): array
    {
        $intervalSeconds = $this->normalizeInterval($intervalSeconds);
        $agentId = $this->normalizeAgentId($agentId);

        if ($enabled && $intervalSeconds < 10) {
            throw new RuntimeException('Interval must be at least 10 seconds.');
        }

        $current = $this->settings();
        $this->writeSettings([
            'enabled' => $enabled,
            'interval_seconds' => $intervalSeconds,
            'agent_id' => $agentId,
            'skip_when_empty' => $skipWhenEmpty,
            'last_run_at' => $current['last_run_at'],
            'last_error' => null,
            'last_pending_count' => $current['last_pending_count'],
        ]);

        return [
            'enabled' => $enabled,
            'interval_seconds' => $intervalSeconds,
            'agent_id' => $agentId,
            'skip_when_empty' => $skipWhenEmpty,
        ];
    }

    public function runIfDue(?Carbon $now = null): bool
    {
        $settings = $this->settings();
        if (! $settings['enabled']) {
            return false;
        }

        if (! $this->synchronize->isAvailable()) {
            return false;
        }

        $now ??= now();
        $deadline = $now->copy()->addSeconds(self::LOOP_BUDGET_SECONDS);
        $ran = false;

        while ($now->lt($deadline)) {
            $settings = $this->settings();
            if (! $settings['enabled']) {
                break;
            }

            $pendingCount = $this->synchronize->countPendingPdaWork();
            if ($settings['skip_when_empty'] && $pendingCount === 0) {
                break;
            }

            if (! $this->isDue($settings, $now)) {
                break;
            }

            if ($this->runSync($settings['agent_id'], $pendingCount, $now)) {
                $ran = true;
            }

            $settings = $this->settings();
            $interval = max(1, (int) $settings['interval_seconds']);

            if ($interval >= 60) {
                break;
            }

            $remaining = max(0, $deadline->getTimestamp() - time());
            $sleepSeconds = min($interval, $remaining);
            if ($sleepSeconds <= 0) {
                break;
            }

            sleep($sleepSeconds);
        }

        return $ran;
    }

    public function runNow(): bool
    {
        if (! $this->synchronize->isAvailable()) {
            throw new RuntimeException('SQL Server is not configured.');
        }

        $settings = $this->settings();
        $pendingCount = $this->synchronize->countPendingPdaWork();
        if ($settings['skip_when_empty'] && $pendingCount === 0) {
            return false;
        }

        return $this->runSync($settings['agent_id'], $pendingCount, now());
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     interval_seconds: int,
     *     agent_id: string,
     *     skip_when_empty: bool,
     *     last_run_at: ?string,
     *     last_error: ?string,
     *     last_pending_count: int
     * }  $settings
     */
    public function isDue(array $settings, ?Carbon $now = null): bool
    {
        if (! $settings['enabled']) {
            return false;
        }

        $now ??= now();
        $lastRunAt = trim((string) ($settings['last_run_at'] ?? ''));
        if ($lastRunAt === '') {
            return true;
        }

        try {
            $lastRun = Carbon::parse($lastRunAt);
        } catch (Throwable) {
            return true;
        }

        return $now->greaterThanOrEqualTo($lastRun->copy()->addSeconds(max(1, (int) $settings['interval_seconds'])));
    }

    private function runSync(string $agentId, int $pendingCount, Carbon $ranAt): bool
    {
        $settings = $this->settings();

        try {
            $this->synchronize->run('scheduler', $agentId);
            $this->writeSettings([
                'enabled' => $settings['enabled'],
                'interval_seconds' => $settings['interval_seconds'],
                'agent_id' => $settings['agent_id'],
                'skip_when_empty' => $settings['skip_when_empty'],
                'last_run_at' => $ranAt->toDateTimeString(),
                'last_error' => null,
                'last_pending_count' => $pendingCount,
            ]);

            Log::info('pda_auto_sync.completed', [
                'agent_id' => $agentId,
                'pending_count' => $pendingCount,
            ]);

            return true;
        } catch (Throwable $e) {
            $this->writeSettings([
                'enabled' => $settings['enabled'],
                'interval_seconds' => $settings['interval_seconds'],
                'agent_id' => $settings['agent_id'],
                'skip_when_empty' => $settings['skip_when_empty'],
                'last_run_at' => $settings['last_run_at'],
                'last_error' => $e->getMessage(),
                'last_pending_count' => $pendingCount,
            ]);

            Log::error('pda_auto_sync.failed', [
                'agent_id' => $agentId,
                'pending_count' => $pendingCount,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function normalizeInterval(int $intervalSeconds): int
    {
        return max(10, min(86400, $intervalSeconds));
    }

    private function normalizeAgentId(string $agentId): string
    {
        $agentId = trim($agentId);
        if ($agentId === '' || strcasecmp($agentId, 'all') === 0) {
            return 'all';
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $agentId)) {
            throw new RuntimeException('Choose a valid sync agent.');
        }

        return strtoupper($agentId);
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     interval_seconds: int,
     *     agent_id: string,
     *     skip_when_empty: bool,
     *     last_run_at: ?string,
     *     last_error: ?string,
     *     last_pending_count: int
     * }  $payload
     */
    private function writeSettings(array $payload): void
    {
        $path = $this->settingsPath();
        $directory = dirname($path);
        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function settingsPath(): string
    {
        return storage_path(self::SETTINGS_RELATIVE);
    }
}
