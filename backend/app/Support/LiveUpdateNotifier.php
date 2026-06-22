<?php

namespace App\Support;

use App\Events\RevenueDataChanged;
use Illuminate\Support\Facades\Cache;

/** Coalesce rapid import broadcasts so the UI is not hammered with push events. */
class LiveUpdateNotifier
{
    private const THROTTLE_SECONDS = 1;

    public static function importCompleted(?string $locationId = null): void
    {
        $key = 'live_update:import';

        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, true, self::THROTTLE_SECONDS);
        RevenueDataChanged::dispatch('import', $locationId);
    }
}
