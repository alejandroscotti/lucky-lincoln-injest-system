<?php

namespace App\Support;

final class LocationSource
{
    public static function isLocationId(?string $value): bool
    {
        return is_string($value) && preg_match('/^LOC-\d+$/', $value) === 1;
    }
}
