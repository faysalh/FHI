<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Splits one chosen date range into the calendar months it touches.
 * The first and last months stop at the chosen dates. Months in between are full.
 */
final class ComparisonMonthSpan
{
    public const MAX_MONTHS = 12;

    /**
     * @return list<array{key: string, label: string, from: string, to: string, span: string}>
     */
    public static function between(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        if ($end->lt($start)) {
            throw new InvalidArgumentException('The end date must be on or after the start date.');
        }

        $cursor = $start->copy()->startOfMonth();
        $lastMonth = $end->copy()->startOfMonth();
        $slices = [];
        while ($cursor->lte($lastMonth)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $sliceStart = $monthStart->lt($start) ? $start->copy() : $monthStart;
            $sliceEnd = $monthEnd->gt($end) ? $end->copy() : $monthEnd;
            $slices[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'from' => $sliceStart->toDateString(),
                'to' => $sliceEnd->toDateString(),
                'span' => $sliceStart->format('j M').' – '.$sliceEnd->format('j M'),
            ];
            $cursor->addMonth();
        }

        return $slices;
    }

    /**
     * @param  list<array{key: string, label: string, from: string, to: string, span: string}>  $slices
     */
    public static function summary(array $slices): string
    {
        $parts = [];
        foreach ($slices as $slice) {
            $parts[] = $slice['label'].' ('.$slice['from'].' to '.$slice['to'].')';
        }
        if ($parts === []) {
            return '';
        }
        if (count($parts) === 1) {
            return 'Showing '.$parts[0].'.';
        }

        return 'Comparing '.implode(', ', $parts).'.';
    }

    /**
     * @param  list<array{key: string, label: string}>  $slices
     */
    public static function changeLabel(array $slices): ?string
    {
        if (count($slices) < 2) {
            return null;
        }
        $previous = $slices[count($slices) - 2]['label'];
        $latest = $slices[count($slices) - 1]['label'];

        return $latest.' − '.$previous;
    }
}
