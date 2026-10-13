<?php

declare(strict_types=1);

/*
 * (c) Tim Bernhard
 */

namespace App\Util;

final class StringCleaner
{
    public const string TRIM_CHARACTERS = " \t\n\r\0\x0B\xc2\xa0";

    public static function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return trim($value, self::TRIM_CHARACTERS);
    }

    public static function clean(string $value): string
    {
        return trim($value, self::TRIM_CHARACTERS);
    }
}
