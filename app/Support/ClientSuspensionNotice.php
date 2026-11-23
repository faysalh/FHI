<?php

declare(strict_types=1);

namespace App\Support;

final class ClientSuspensionNotice
{
    public const ARABIC = 'يوجب الدفع قبل الاكمال';

    public const KURDISH = 'دەبی پسوولەی کەون بدرێت بەر لە بەردەوام بوون';

    public static function plain(): string
    {
        return self::ARABIC.' — '.self::KURDISH;
    }
}
