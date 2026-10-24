<?php

namespace App\Modules\Weer\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Weer\Models\WeatherDay;
use App\Reports\DateRanges;

/** Rain month by month this season beside last season's, as far as the farm's record goes. */
class SeasonRainChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('weer::ui.charts.season');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = DateRanges::seasonStart();
        $previous = $start->copy()->subYear();
        $rain = WeatherDay::query()->where('date', '>=', $previous->toDateString())->get()
            ->groupBy(fn (WeatherDay $d) => $d->date->format('Y-m'))
            ->map(fn ($days) => round((float) $days->sum('rain'), 1));

        $labels = $now = $before = [];
        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $labels[] = $month->translatedFormat('M');
            $now[] = $month->gt(today()) ? null : ($rain[$month->format('Y-m')] ?? null);
            $before[] = $rain[$month->copy()->subYear()->format('Y-m')] ?? null;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => __('weer::ui.charts.this_season'), 'data' => $now, 'backgroundColor' => '#5b9bd5'],
                ['label' => __('weer::ui.charts.last_season'), 'data' => $before, 'backgroundColor' => '#c9d9ea'],
            ],
        ];
    }
}
