<?php

namespace App\Modules\Kudde\Console;

use App\Modules\Kudde\Support\Herd;
use Illuminate\Console\Command;

/**
 * Stamps the weather on the last fortnight's events that were saved before their day reached weather_days.
 * An event is never held back for its weather (old Kudde's rule), so most of today's events get theirs here,
 * tomorrow morning.
 */
class StampWeather extends Command
{
    protected $signature = 'kudde:weather';

    protected $description = "Fill in the weather on recent events from the farm's daily weather";

    public function handle(Herd $herd): int
    {
        $this->info($herd->stampMissingWeather().' events stamped');

        return self::SUCCESS;
    }
}
