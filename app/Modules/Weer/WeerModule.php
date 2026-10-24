<?php

namespace App\Modules\Weer;

use App\Modules\Module;
use App\Modules\Weer\Console\FillWeatherDays;
use App\Modules\Weer\Filament\WeatherPage;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Weather (PLAN.md section 2.3): the farm's forecast, its rain record and its weather against any year
 * since 1940. Free, on for every farm. The screens are Weather Compare's (DawiePieterse/weather-app),
 * served from public/modules/weer/ for the farm's own position only; every Open-Meteo call goes through
 * this module's proxy, which adds Plaashek's API key when there is one and caches the answers.
 */
class WeerModule extends Module
{
    public static function code(): string
    {
        return 'weer';
    }

    public static function nameKey(): string
    {
        return 'plaashek.modules.weer';
    }

    public static function free(): bool
    {
        return true;
    }

    public static function filamentPages(): array
    {
        return [WeatherPage::class];
    }

    public function register(): void
    {
        $this->app->singleton(OpenMeteoProxy::class);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('views'), 'weer');
        $this->loadTranslationsFrom($this->modulePath('lang'), 'weer');

        if ($this->app->runningInConsole()) {
            $this->commands([FillWeatherDays::class]);
        }

        // Yesterday's weather lands in weather_days before anyone is up, so modules that stamp weather
        // (Kudde's events) never wait on a live call.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('weer:days')->dailyAt('05:10')->withoutOverlapping();
        });
    }
}
