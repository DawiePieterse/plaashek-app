<?php

namespace App\Modules\Weer\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Weer\Models\WeatherDay;

/** The last 30 days: rain each day as bars, the day's highest and lowest temperature as lines. */
class RecentWeatherChart extends FarmChart
{
    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('weer::ui.charts.last_30');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = WeatherDay::query()->where('date', '>=', today()->subDays(30)->toDateString())
            ->where('date', '<', today()->toDateString())->orderBy('date')->get();

        return [
            'labels' => $days->map(fn (WeatherDay $d) => $d->date->translatedFormat('j M'))->all(),
            'datasets' => [
                ['label' => __('weer::ui.charts.rain'), 'data' => $days->pluck('rain')->all(), 'backgroundColor' => '#5b9bd5', 'yAxisID' => 'y'],
                ['type' => 'line', 'label' => __('weer::ui.charts.max'), 'data' => $days->pluck('temp_max')->all(),
                    'borderColor' => '#c0504d', 'backgroundColor' => '#c0504d', 'yAxisID' => 'y1', 'tension' => 0.3],
                ['type' => 'line', 'label' => __('weer::ui.charts.min'), 'data' => $days->pluck('temp_min')->all(),
                    'borderColor' => '#2f6b3a', 'backgroundColor' => '#2f6b3a', 'yAxisID' => 'y1', 'tension' => 0.3],
            ],
        ];
    }

    protected function chartOptions(): array
    {
        return ['scales' => [
            'y' => ['beginAtZero' => true, 'title' => ['display' => true, 'text' => 'mm']],
            'y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => '°C']],
        ]];
    }
}
