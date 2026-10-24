<?php

namespace App\Modules\Kudde;

use App\Modules\Kudde\Console\StampWeather;
use App\Modules\Kudde\Support\Herd;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Kudde (PLAN.md section 2.2), the first paid module: cattle recording for small farms, the animals and the
 * handful of things that happen to them. The behaviour is the farm-PC Kudde's (DawiePieterse/Kudde),
 * rebuilt here: identity and events stored, everything else derived in one place (Support\HerdState).
 */
class KuddeModule extends Module
{
    public static function code(): string
    {
        return 'kudde';
    }

    public static function nameKey(): string
    {
        return 'plaashek.modules.kudde';
    }

    public function register(): void
    {
        $this->app->singleton(Herd::class);
    }

    protected function bootModule(): void
    {
        $this->loadTranslationsFrom($this->modulePath('lang'), 'kudde');

        if ($this->app->runningInConsole()) {
            $this->commands([StampWeather::class]);
        }

        // After weer:days (05:10) has stored yesterday, the events captured before their day was in
        // weather_days get their weather.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('kudde:weather')->dailyAt('05:25')->withoutOverlapping();
        });
    }
}
