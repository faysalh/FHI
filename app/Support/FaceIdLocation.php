<?php

declare(strict_types=1);

namespace App\Support;

final class FaceIdLocation
{
    public static function format(?float $latitude, ?float $longitude, ?float $accuracyMeters = null): string
    {
        if ($latitude === null || $longitude === null) {
            return '';
        }

        $text = sprintf('%.6f, %.6f', $latitude, $longitude);
        if ($accuracyMeters !== null && $accuracyMeters >= 0) {
            $text .= sprintf(' (±%.0fm)', $accuracyMeters);
        }

        return $text;
    }

    public static function mapsUrl(?float $latitude, ?float $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return 'https://www.google.com/maps?q='.rawurlencode(sprintf('%F,%F', $latitude, $longitude));
    }
}
