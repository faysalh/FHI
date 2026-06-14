<?php

declare(strict_types=1);

namespace App\Support;

final class ReceiptBookletRanges
{
    public const BOOKLET_SIZE = 50;

    /**
     * Split an inclusive number range into booklets of up to {@see BOOKLET_SIZE} receipts each.
     *
     * @return list<array{start: int, end: int}>
     */
    public static function split(int $firstNumber, int $lastNumber, int $bookletSize = self::BOOKLET_SIZE): array
    {
        if ($bookletSize < 1) {
            throw new \InvalidArgumentException('Booklet size must be at least 1.');
        }

        if ($firstNumber > $lastNumber) {
            throw new \InvalidArgumentException('The first number must be less than or equal to the last number.');
        }

        $booklets = [];
        $current = $firstNumber;

        while ($current <= $lastNumber) {
            $end = min($current + $bookletSize - 1, $lastNumber);
            $booklets[] = [
                'start' => $current,
                'end' => $end,
            ];
            $current = $end + 1;
        }

        return $booklets;
    }
}
