<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\NonWorkingHolidayStoreRequest;
use App\Services\NonWorkingHolidaysSqliteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NonWorkingHolidaysSettingsController extends Controller
{
    public function __construct(
        private readonly NonWorkingHolidaysSqliteService $holidays
    ) {}

    public function index(): View
    {
        $storageError = null;
        $rows = [];
        $byYear = [];

        try {
            $rows = $this->holidays->listAll();
            foreach ($rows as $row) {
                $date = (string) ($row->holiday_date ?? '');
                $year = strlen($date) >= 4 ? substr($date, 0, 4) : 'Unknown';
                $byYear[$year][] = $row;
            }
            ksort($byYear);
        } catch (Throwable $e) {
            Log::warning('holidays.settings_load_failed', ['message' => $e->getMessage()]);
            $storageError = 'Could not load holidays ('.$e->getMessage().'). Check DELIVERIES_SQLITE_DATABASE path.';
        }

        return view('reports.holidays.index', [
            'rows' => $rows,
            'byYear' => $byYear,
            'storageError' => $storageError,
        ]);
    }

    public function store(NonWorkingHolidayStoreRequest $request): RedirectResponse
    {
        $input = $request->validated();

        try {
            $this->holidays->addHoliday(
                (string) $input['holiday_date'],
                (string) ($input['label'] ?? '')
            );
        } catch (RuntimeException $e) {
            return redirect()
                ->route('reports.holidays.index')
                ->withInput()
                ->withErrors(['holiday_date' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('holidays.store_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.holidays.index')
                ->withInput()
                ->withErrors(['holiday_date' => 'Could not save holiday. Check logs.']);
        }

        return redirect()
            ->route('reports.holidays.index')
            ->with('status', 'Holiday added. Dashboard business-day calculations will exclude this date (Fridays are always excluded).');
    }

    public function destroy(int $holiday): RedirectResponse
    {
        try {
            $this->holidays->deleteHoliday($holiday);
        } catch (Throwable $e) {
            Log::error('holidays.delete_failed', ['message' => $e->getMessage()]);

            return redirect()
                ->route('reports.holidays.index')
                ->with('error', 'Could not remove holiday.');
        }

        return redirect()
            ->route('reports.holidays.index')
            ->with('status', 'Holiday removed.');
    }
}
