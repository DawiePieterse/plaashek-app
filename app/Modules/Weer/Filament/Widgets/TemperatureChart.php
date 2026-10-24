<?php

namespace App\Modules\Weer\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Weer\Models\WeatherDay;

/** Each month's average highest and lowest temperature over the last 12 months. */
class TemperatureChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('weer::ui.charts.temperature');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = today()->startOfMonth()->subMonths(11);
        $months = WeatherDay::query()->where('date', '>=', $start->toDateString())->get()
            ->groupBy(fn (WeatherDay $d) => $d->date->format('Y-m'));

        $labels = $max = $min = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $start->copy()->addMonths($i);
            $days = $months->get($m->format('Y-m'));
            $labels[] = $m->translatedFormat('M');
            $max[] = $days ? round((float) $days->avg('temp_max'), 1) : null;
            $min[] = $days ? round((float) $days->avg('temp_min'), 1) : null;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => __('weer::ui.charts.avg_max'), 'data' => $max, 'borderColor' => '#c0504d', 'backgroundColor' => '#c0504d', 'spanGaps' => true, 'tension' => 0.3],
                ['label' => __('weer::ui.charts.avg_min'), 'data' => $min, 'borderColor' => '#2f6b3a', 'backgroundColor' => '#2f6b3a', 'spanGaps' => true, 'tension' => 0.3],
            ],
        ];
    }
}
