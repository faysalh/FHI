<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GovernorateStoreRequest;
use App\Repositories\CitiesReportRepository;
use App\Repositories\VisitsReportRepository;
use App\Services\CitiesGovernorateSqliteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class GovernoratesSettingsController extends Controller
{
    public function __construct(
        private readonly CitiesGovernorateSqliteService $governorates,
        private readonly CitiesReportRepository $citiesRepository,
        private readonly VisitsReportRepository $visitsRepository
    ) {}

    public function index(): View|RedirectResponse
    {
        $editId = (int) request()->query('edit', request()->query('edit_governorate_id', 0));

        $savedGovernorates = [];
        $editingGovernorate = null;
        $storageError = null;

        try {
            $savedGovernorates = $this->governorates->listGovernorates();
            if ($editId > 0) {
                $editingGovernorate = $this->governorates->getGovernorateById($editId);
            }
        } catch (Throwable $e) {
            Log::warning('governorates.settings_load_failed', ['message' => $e->getMessage()]);
            $storageError = 'Saved governorates could not be loaded ('.$e->getMessage().'). Check the deliveries SQLite path (DELIVERIES_SQLITE_DATABASE).';
        }

        $cityNames = array_values(array_filter(array_map(
            static fn (array $c): string => trim((string) ($c['name'] ?? '')),
            $this->cityOptionsForPicker()
        ), static fn (string $name): bool => $name !== ''));

        return view('reports.governorates.index', [
            'savedGovernorates' => $savedGovernorates,
            'editingGovernorate' => $editingGovernorate,
            'storageError' => $storageError,
            'cityNames' => $cityNames,
            'editId' => $editId > 0 ? $editId : null,
        ]);
    }

    public function store(GovernorateStoreRequest $request): RedirectResponse
    {
        $input = $request->validated();
        $members = $this->citiesRepository->normalizeCities(
            is_array($input['governorate_members'] ?? null) ? $input['governorate_members'] : []
        );

        try {
            $govId = $this->governorates->saveGovernorate(
                isset($input['governorate_id']) ? (int) $input['governorate_id'] : null,
                trim((string) $input['governorate_name']),
                trim((string) $input['governorate_city']),
                $members
            );
        } catch (Throwable $e) {
            Log::error('governorates.store_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.governorates.index', array_filter([
                    'edit' => isset($input['governorate_id']) ? (int) $input['governorate_id'] : null,
                ]))
                ->withInput()
                ->with('error', 'Could not save governorate: '.$e->getMessage());
        }

        return redirect()
            ->route('reports.governorates.index', ['edit' => $govId])
            ->with('status', 'Governorate saved.');
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function cityOptionsForPicker(): array
    {
        $cities = $this->visitsRepository->getCityOptions();
        $out = [];
        foreach ($cities as $c) {
            if ($c !== '') {
                $out[] = ['id' => $c, 'name' => $c];
            }
        }

        return $out;
    }
}
