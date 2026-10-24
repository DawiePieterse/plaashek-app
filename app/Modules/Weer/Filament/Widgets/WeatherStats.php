<?php

namespace App\Modules\Weer\Filament\Widgets;

use App\Modules\Weer\Models\WeatherDay;
use App\Reports\DateRanges;
use App\Support\Farm;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The weather that has been: rain over the last week, month and season, the frost nights, the extremes and
 * the water the crops used (ET0). From weather_days, the farm's own record, so it shows with no signal.
 */
class WeatherStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3];
    }

    protected function getStats(): array
    {
        $today = today();
        $season = DateRanges::seasonStart();
        $monthAgo = $today->copy()->subDays(30);
        $days = WeatherDay::query()->where('date', '>=', ($season->lt($monthAgo) ? $season : $monthAgo)->toDateString())
            ->where('date', '<', $today->toDateString())->orderBy('date')->get();
        $since = fn (int $n) => $days->filter(fn (WeatherDay $d) => $d->date->gte($today->copy()->subDays($n)));
        $last30 = $since(30);
        $frost = app(Farm::class)->frostThreshold();
        $locale = app()->getLocale();
        $mm = fn ($v) => $v === null ? '–' : Number::format((float) $v, precision: 1, locale: $locale).' mm';
        $deg = fn ($v) => $v === null ? '–' : Number::format((float) $v, precision: 1, locale: $locale).' °C';
        $seasonDays = $days->filter(fn (WeatherDay $d) => $d->date->gte($season));

        return [
            Stat::make(__('weer::ui.stats.rain_7'), $since(7)->isEmpty() ? '–' : $mm($since(7)->sum('rain')))
                ->chart($since(14)->pluck('rain')->map(fn ($v) => (float) $v)->all())->color('info'),
            Stat::make(__('weer::ui.stats.rain_30'), $last30->isEmpty() ? '–' : $mm($last30->sum('rain')))->color('info'),
            Stat::make(__('weer::ui.stats.rain_season'), $seasonDays->isEmpty() ? '–' : $mm($seasonDays->sum('rain')))
                ->description(__('weer::ui.stats.since', ['date' => $season->translatedFormat('j M')]).
                    ($seasonDays->count() < $season->diffInDays($today) - 1 ? ' · '.__('weer::ui.stats.incomplete') : ''))
                ->color('info'),
            Stat::make(__('weer::ui.stats.frost_nights'), $last30->isEmpty() ? '–' : (string) $last30->filter(fn ($d) => $d->temp_min !== null && $d->temp_min <= $frost)->count())
                ->description(__('weer::ui.stats.frost_hint', ['t' => Number::format($frost, precision: 1, locale: $locale)])),
            Stat::make(__('weer::ui.stats.extremes'), $last30->isEmpty() ? '–' : $deg($last30->max('temp_max')).' / '.$deg($last30->min('temp_min')))
                ->description(__('weer::ui.stats.extremes_hint')),
            Stat::make(__('weer::ui.stats.et0_7'), $since(7)->isEmpty() ? '–' : $mm($since(7)->sum('et0')))
                ->description(__('weer::ui.stats.et0_hint')),
        ];
    }
}
