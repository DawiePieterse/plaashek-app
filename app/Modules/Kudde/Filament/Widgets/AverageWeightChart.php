<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Kudde\Support\HerdHistory;

/** The average weight of the animals weighed each month: is the herd gaining through the season or losing? */
class AverageWeightChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('kudde::ui.charts.weights');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $months = HerdHistory::averageWeights(12);

        return [
            'labels' => array_map(fn ($m) => $m['month']->translatedFormat('M'), $months),
            'datasets' => [[
                'label' => __('kudde::ui.charts.avg_kg'), 'data' => array_column($months, 'kg'), 'spanGaps' => true,
                'borderColor' => '#e3a33b', 'backgroundColor' => '#e3a33b', 'tension' => 0.25,
            ]],
        ];
    }
}
