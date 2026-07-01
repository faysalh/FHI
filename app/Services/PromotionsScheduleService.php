<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PromotionsWeekdays;
use Carbon\Carbon;

class PromotionsScheduleService
{
    public function __construct(
        private readonly PromotionsSqliteService $promotions
    ) {}

    /**
     * @return array{
     *     promoter: object,
     *     week_start: string,
     *     week_end: string,
     *     columns: list<array{weekday: int, date: string, label: string}>,
     *     cells: array<int, list<string>>,
     *     max_rows: int
     * }
     */
    public function buildSheetForPromoter(int $promoterId, string $weekStart): array
    {
        $promoter = $this->promotions->getPromoter($promoterId);
        if ($promoter === null) {
            throw new \InvalidArgumentException('Promoter not found.');
        }

        $start = Carbon::parse(PromotionsWeekdays::normalizeWeekStart($weekStart))->startOfDay();
        $columns = PromotionsWeekdays::columnsForWeek($start->toDateString());
        $cells = [];
        foreach ($columns as $column) {
            $cells[(int) $column['weekday']] = [];
        }

        $assignments = $this->promotions->listAssignmentsForPromoter($promoterId);
        foreach ($assignments as $assignment) {
            $days = $this->promotions->effectiveVisitDays($promoter, $assignment);
            $name = trim((string) ($assignment->client_name ?? ''));
            if ($name === '') {
                continue;
            }
            foreach ($days as $weekday) {
                if (! array_key_exists($weekday, $cells)) {
                    continue;
                }
                $cells[$weekday][] = $name;
            }
        }

        foreach ($cells as $weekday => $names) {
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $cells[$weekday] = array_values($names);
        }

        $maxRows = 0;
        foreach ($cells as $names) {
            $maxRows = max($maxRows, count($names));
        }

        return [
            'promoter' => $promoter,
            'week_start' => $start->toDateString(),
            'week_end' => PromotionsWeekdays::weekEndDate($start->toDateString()),
            'columns' => $columns,
            'cells' => $cells,
            'max_rows' => $maxRows,
        ];
    }

    /**
     * @return list<array{
     *     promoter: object,
     *     week_start: string,
     *     week_end: string,
     *     columns: list<array{weekday: int, date: string, label: string}>,
     *     cells: array<int, list<string>>,
     *     max_rows: int
     * }>
     */
    public function buildSheetsForAllPromoters(string $weekStart): array
    {
        $sheets = [];
        foreach ($this->promotions->listPromoters() as $promoter) {
            $promoterId = (int) ($promoter->id ?? 0);
            if ($promoterId < 1) {
                continue;
            }
            if ($this->promotions->listAssignmentsForPromoter($promoterId) === []) {
                continue;
            }
            $sheets[] = $this->buildSheetForPromoter($promoterId, $weekStart);
        }

        return $sheets;
    }
}
