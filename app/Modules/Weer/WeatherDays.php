<?php

namespace App\Modules\Weer;

use App\Modules\Weer\Models\WeatherDay;
use Illuminate\Support\Carbon;

/**
 * What other modules read the farm's daily weather through (PLAN.md section 4.1: a module reads another's
 * data only through its service classes). Never a live call: `weer:days` fills the table nightly, so a day
 * not yet filled is simply null and a save that stamps weather never waits on Open-Meteo.
 */
class WeatherDays
{
    public static function on(Carbon|string $date): ?WeatherDay
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        return WeatherDay::query()->find($day);
    }
}
