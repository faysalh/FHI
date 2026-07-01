<?php

declare(strict_types=1);

namespace App\Support;

final class DeliveriesReportAccess
{
    private const SESSION_KEY = 'reports_deliveries_access';

    public function __construct(
        public readonly bool $canFilterDate = true,
        public readonly bool $canFilterCity = true,
        public readonly bool $canFilterStorage = true,
        public readonly bool $canFilterSalesman = true,
        public readonly bool $canFilterStatus = true,
        public readonly bool $canEditStatus = true,
        public readonly ?string $defaultStorage = null,
    ) {}

    public static function full(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return self::full();
        }

        $storage = isset($data['default_storage']) ? trim((string) $data['default_storage']) : '';

        return new self(
            canFilterDate: (bool) ($data['can_filter_date'] ?? true),
            canFilterCity: (bool) ($data['can_filter_city'] ?? true),
            canFilterStorage: (bool) ($data['can_filter_storage'] ?? true),
            canFilterSalesman: (bool) ($data['can_filter_salesman'] ?? true),
            canFilterStatus: (bool) ($data['can_filter_status'] ?? true),
            canEditStatus: (bool) ($data['can_edit_status'] ?? true),
            defaultStorage: $storage !== '' ? $storage : null,
        );
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'can_filter_date' => $this->canFilterDate,
            'can_filter_city' => $this->canFilterCity,
            'can_filter_storage' => $this->canFilterStorage,
            'can_filter_salesman' => $this->canFilterSalesman,
            'can_filter_status' => $this->canFilterStatus,
            'can_edit_status' => $this->canEditStatus,
            'default_storage' => $this->defaultStorage,
        ];
    }

    public static function fromSession(): self
    {
        if (ReportAuthSession::isSuperAdmin()) {
            return self::full();
        }

        $raw = session()->get(self::SESSION_KEY);
        if (! is_array($raw)) {
            return self::full();
        }

        return self::fromArray($raw);
    }

    public static function putSession(self $access): void
    {
        session()->put(self::SESSION_KEY, $access->toArray());
    }

    public static function forgetSession(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Enforce deliveries filter permissions on request input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function applyToInput(array $input, string $today): array
    {
        if (! $this->canFilterDate) {
            $input['date_from'] = $today;
            $input['date_to'] = $today;
        }

        if (! $this->canFilterCity) {
            $input['cities'] = [];
        }

        if (! $this->canFilterStorage) {
            $input['storage'] = $this->defaultStorage ?? '';
        }

        if (! $this->canFilterSalesman) {
            $input['salesman_ids'] = [];
        }

        if (! $this->canFilterStatus) {
            $input['delivery_status'] = '';
        }

        return $input;
    }

    /**
     * Query keys preserved across deliveries tab switches and POST redirects.
     *
     * @return list<string>
     */
    public static function filterKeys(): array
    {
        return [
            'date_from',
            'date_to',
            'per_page',
            'storage',
            'delivery_status',
            'team_id',
            'team_date',
            'invoice_search',
            'salesman_ids',
            'cities',
            'include_amount',
            'include_weight',
        ];
    }

    /**
     * Normalize filter values for redirects (respecting locked permissions).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function snapshotForRedirect(array $input, string $today): array
    {
        $snapshot = [];
        foreach (self::filterKeys() as $key) {
            if (array_key_exists($key, $input)) {
                $snapshot[$key] = $input[$key];
            }
        }

        if (! isset($snapshot['date_from'])) {
            $snapshot['date_from'] = $today;
        }
        if (! isset($snapshot['date_to'])) {
            $snapshot['date_to'] = $today;
        }

        return $this->applyToInput($snapshot, $today);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applySessionToValidated(array $validated): array
    {
        $today = now()->toDateString();

        return self::fromSession()->applyToInput($validated, $today);
    }
}
