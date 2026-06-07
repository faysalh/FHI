<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SalesReportRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardGovernorateService
{
    public function __construct(
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly SalesReportRepository $sales,
    ) {}

    /**
     * @param  int|null  $governorateId  When set, resolve that saved governorate only (lab UI).
     * @return array{
     *     cities: list<string>,
     *     label: string,
     *     governorate_id: int|null,
     *     error: string|null
     * }
     */
    public function resolve(?int $governorateId = null): array
    {
        $configuredId = (int) config('reporting.dashboard.saved_governorate_id', 0);
        $nameNeedle = trim((string) config('reporting.dashboard.governorate_name', 'Erbil'));

        try {
            $this->governorates->listGovernorates();
        } catch (Throwable $e) {
            Log::warning('dashboard.governorate_storage_unavailable', ['message' => $e->getMessage()]);

            return [
                'cities' => [],
                'label' => $nameNeedle !== '' ? $nameNeedle : 'Governorate',
                'governorate_id' => null,
                'error' => 'Saved governorates could not be loaded. Configure governorates under Settings → Governorates.',
            ];
        }

        $selected = null;
        if ($governorateId !== null && $governorateId > 0) {
            $selected = $this->governorates->getGovernorateById($governorateId);
            if ($selected === null) {
                return [
                    'cities' => [],
                    'label' => 'Governorate',
                    'governorate_id' => null,
                    'error' => 'Selected governorate was not found. Choose another from the list.',
                ];
            }
        } elseif ($configuredId > 0) {
            $selected = $this->governorates->getGovernorateById($configuredId);
        }

        if ($selected === null && $nameNeedle !== '') {
            $selected = $this->findGovernorateByName($nameNeedle);
        }

        if ($selected === null) {
            return [
                'cities' => [],
                'label' => $nameNeedle !== '' ? $nameNeedle : 'Governorate',
                'governorate_id' => null,
                'error' => 'No saved governorate matched "'.$nameNeedle.'". Save governorates under Settings → Governorates, or set REPORTING_DASHBOARD_GOVERNORATE_ID in .env.',
            ];
        }

        return $this->buildResolved($selected, $nameNeedle);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function listForSelect(): array
    {
        try {
            $rows = $this->governorates->listGovernorates();
        } catch (Throwable $e) {
            Log::warning('dashboard.governorate_list_unavailable', ['message' => $e->getMessage()]);

            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($row->name ?? ''));
            $city = trim((string) ($row->governorate_city ?? ''));
            $label = $name !== '' ? $name : ($city !== '' ? $city : 'Governorate #'.$id);
            $options[] = ['id' => $id, 'label' => $label];
        }

        usort($options, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $options;
    }

    /**
     * @param  array{id:int,name?:string,governorate_city?:string,members?:list<string>}  $selected
     * @return array{
     *     cities: list<string>,
     *     label: string,
     *     governorate_id: int|null,
     *     error: string|null
     * }
     */
    private function buildResolved(array $selected, string $fallbackLabel): array
    {
        $members = is_array($selected['members'] ?? null) ? $selected['members'] : [];
        $governorateCity = trim((string) ($selected['governorate_city'] ?? ''));
        if ($governorateCity !== '' && ! in_array($governorateCity, $members, true)) {
            $members[] = $governorateCity;
        }

        $cities = $this->sales->normalizeCities($members);
        $label = trim((string) ($selected['name'] ?? ''));
        if ($label === '') {
            $label = $fallbackLabel !== '' ? $fallbackLabel : 'Governorate';
        }

        if ($cities === []) {
            return [
                'cities' => [],
                'label' => $label,
                'governorate_id' => (int) ($selected['id'] ?? 0) ?: null,
                'error' => 'Governorate "'.$label.'" has no member cities. Edit it under Settings → Governorates.',
            ];
        }

        return [
            'cities' => $cities,
            'label' => $label,
            'governorate_id' => (int) ($selected['id'] ?? 0) ?: null,
            'error' => null,
        ];
    }

    /**
     * @return array{id:int,name:string,governorate_city:string,members:list<string>}|null
     */
    private function findGovernorateByName(string $needle): ?array
    {
        $needleLower = mb_strtolower($needle);

        foreach ($this->governorates->listGovernorates() as $row) {
            $name = mb_strtolower(trim((string) ($row->name ?? '')));
            $city = mb_strtolower(trim((string) ($row->governorate_city ?? '')));
            if ($name === $needleLower || $city === $needleLower
                || str_contains($name, $needleLower) || str_contains($city, $needleLower)) {
                $id = (int) ($row->id ?? 0);
                if ($id > 0) {
                    return $this->governorates->getGovernorateById($id);
                }
            }
        }

        return null;
    }
}
