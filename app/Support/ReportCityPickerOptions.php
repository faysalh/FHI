<?php

declare(strict_types=1);

namespace App\Support;

final class ReportCityPickerOptions
{
    /**
     * @param  list<string>  $cityNames
     * @return list<array{id: string, name: string}>
     */
    public static function fromCityNames(array $cityNames): array
    {
        $out = [];
        foreach ($cityNames as $city) {
            if (! is_string($city)) {
                continue;
            }
            $city = trim($city);
            if ($city === '') {
                continue;
            }
            $out[] = ['id' => $city, 'name' => $city];
        }

        return $out;
    }
}
