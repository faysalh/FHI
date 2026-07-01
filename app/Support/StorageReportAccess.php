<?php

declare(strict_types=1);

namespace App\Support;

final class StorageReportAccess
{
    private const SESSION_KEY = 'reports_storage_access';

    /**
     * @param  list<string>  $allowedStorages  Empty = no storage restriction (all storages).
     */
    public function __construct(
        public readonly bool $canFilterStorage = true,
        public readonly array $allowedStorages = [],
    ) {}

    public static function full(): self
    {
        return new self;
    }

    public function isRestricted(): bool
    {
        return $this->allowedStorages !== [];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return self::full();
        }

        $raw = $data['allowed_storages'] ?? [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $allowed = [];
        if (is_array($raw)) {
            foreach ($raw as $value) {
                $name = trim((string) $value);
                if ($name !== '') {
                    $allowed[] = $name;
                }
            }
        }
        $allowed = array_values(array_unique($allowed));

        return new self(
            canFilterStorage: (bool) ($data['can_filter_storage'] ?? true),
            allowedStorages: $allowed,
        );
    }

    /**
     * @return array<string, bool|list<string>>
     */
    public function toArray(): array
    {
        return [
            'can_filter_storage' => $this->canFilterStorage,
            'allowed_storages' => $this->allowedStorages,
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
     * @param  list<string>  $options
     * @return list<string>
     */
    public function filterStorages(array $options): array
    {
        if (! $this->isRestricted()) {
            return $options;
        }

        $allowed = array_fill_keys($this->allowedStorages, true);
        $filtered = [];
        foreach ($options as $option) {
            $name = trim((string) $option);
            if ($name !== '' && isset($allowed[$name])) {
                $filtered[] = $name;
            }
        }

        return $filtered;
    }

    /**
     * Enforce storage report permissions on request input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function applyToInput(array $input): array
    {
        $requested = [];
        if (is_array($input['storages'] ?? null)) {
            foreach ($input['storages'] as $value) {
                $name = trim((string) $value);
                if ($name !== '') {
                    $requested[] = $name;
                }
            }
        }
        $requested = array_values(array_unique($requested));

        if (! $this->isRestricted()) {
            if (! $this->canFilterStorage) {
                $input['storages'] = [];
            }

            return $input;
        }

        if (! $this->canFilterStorage) {
            $input['storages'] = $this->allowedStorages;

            return $input;
        }

        if ($requested === []) {
            $input['storages'] = $this->allowedStorages;

            return $input;
        }

        $input['storages'] = array_values(array_intersect($requested, $this->allowedStorages));
        if ($input['storages'] === []) {
            $input['storages'] = $this->allowedStorages;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function applySessionToValidated(array $validated): array
    {
        return self::fromSession()->applyToInput($validated);
    }
}
