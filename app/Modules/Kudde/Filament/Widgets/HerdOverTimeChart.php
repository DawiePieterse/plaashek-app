<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Kudde\Support\HerdHistory;

/** The head count at each month's end over the last year, with what came in and went out each month. */
class HerdOverTimeChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('kudde::ui.charts.over_time');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = (new HerdHistory)->months(12);
        $labels = array_map(fn ($m) => $m['month']->translatedFormat('M'), $months);

        return [
            'labels' => $labels,
            'datasets' => [
                ['type' => 'line', 'label' => __('kudde::ui.charts.head'), 'data' => array_column($months, 'head'),
                    'borderColor' => '#2f6b3a', 'backgroundColor' => '#2f6b3a', 'yAxisID' => 'y1', 'tension' => 0.25],
                ['label' => __('kudde::ui.charts.born'), 'data' => array_column($months, 'born'), 'backgroundColor' => '#86c08f'],
                ['label' => __('kudde::ui.charts.entered'), 'data' => array_column($months, 'entered'), 'backgroundColor' => '#5b9bd5'],
                ['label' => __('kudde::ui.charts.sold'), 'data' => array_column($months, 'sold'), 'backgroundColor' => '#e3a33b'],
                ['label' => __('kudde::ui.charts.died'), 'data' => array_column($months, 'died'), 'backgroundColor' => '#c0504d'],
            ],
        ];
    }

    protected function chartOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0], 'title' => ['display' => true, 'text' => __('kudde::ui.charts.in_out')]],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'ticks' => ['precision' => 0],
                    'title' => ['display' => true, 'text' => __('kudde::ui.charts.head')]],
            ],
        ];
    }
}
