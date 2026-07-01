<?php

declare(strict_types=1);

namespace App\Support;

final class SalesItemPriceTiers
{
    /**
     * Display columns 1–5 align with account price group DB values 0–4 (+1).
     * Same labels as Sales by salesman and Storage items sale prices.
     *
     * @var array<int, string>
     */
    public const LABELS = [
        1 => 'وكيل',
        2 => 'وكيل 2',
        3 => 'ماركيت',
        4 => 'جملة',
        5 => 'كي',
    ];

    /**
     * @return list<array{tier: int, label: string}>
     */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::LABELS as $tier => $label) {
            $out[] = ['tier' => $tier, 'label' => $label];
        }

        return $out;
    }

    public static function label(int $tier): string
    {
        return self::LABELS[$tier] ?? 'Price '.$tier;
    }

    /**
     * @param  list<int>  $selected  Normalized tier indexes 1–5; empty means all tiers.
     * @return list<array{tier: int, label: string}>
     */
    public static function activeDefinitions(array $selected): array
    {
        if ($selected === []) {
            return self::definitions();
        }

        $set = array_fill_keys($selected, true);
        $out = [];
        foreach (self::definitions() as $def) {
            if (isset($set[$def['tier']])) {
                $out[] = $def;
            }
        }

        return $out;
    }
}
