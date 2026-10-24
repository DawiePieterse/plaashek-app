<?php

namespace App\Modules\Weer;

use App\Modules\Module;
use App\Modules\ModuleTile;
use App\Modules\Weer\Console\FillWeatherDays;
use App\Modules\Weer\Filament\WeatherPage;
use App\Modules\Weer\Filament\WeatherSettings;
use App\Modules\Weer\Models\WeatherDay;
use App\Modules\Weer\Reports\DailyWeatherReport;
use App\Modules\Weer\Reports\MonthlyWeatherReport;
use App\Reports\Report;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Number;

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
        return [WeatherPage::class, WeatherSettings::class];
    }

    /** Rain over the last seven days the farm has on record: the first thing anyone asks about the weather. */
    public static function homeTile(): ?ModuleTile
    {
        $days = WeatherDay::query()->where('date', '>=', today()->subDays(7))->where('date', '<', today())->get();
        $rain = $days->sum(fn (WeatherDay $d) => (float) $d->rain);

        return new ModuleTile(
            label: __('plaashek.modules.weer'),
            icon: '🌦️',
            value: $days->isEmpty() ? '–' : Number::format($rain, precision: 1, locale: app()->getLocale()).' mm',
            caption: __('weer::ui.tile_rain_7d'),
            url: WeatherPage::getUrl(),
            sort: 10,
        );
    }

    /** @return list<Report> */
    public static function reports(): array
    {
        return [new DailyWeatherReport, new MonthlyWeatherReport];
    }

    public static function recordedDays(): int
    {
        return WeatherDay::query()->count();
    }

    public static function topbarView(): ?string
    {
        return 'weer::topbar';
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
