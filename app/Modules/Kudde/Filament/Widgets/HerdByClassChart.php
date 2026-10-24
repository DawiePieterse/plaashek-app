<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Kudde\Support\HerdState;

/** The live herd as a farmer counts it: cows, heifers, bulls, steers and calves. */
class HerdByClassChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('kudde::ui.charts.by_class');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $state = HerdState::load();
        $counts = array_fill_keys($state->classLabels(), 0);
        foreach ($state->alive() as $a) {
            $counts[$state->classLabel($a)]++;
        }
        $counts = array_filter($counts);
        $palette = ['#2f6b3a', '#86c08f', '#5b9bd5', '#e3a33b', '#c9e3cd', '#a9c8ea', '#f2d29b', '#c0504d', '#8064a2', '#4bacc6'];

        return [
            'labels' => array_keys($counts),
            'datasets' => [[
                'data' => array_values($counts),
                'backgroundColor' => array_map(fn (int $i) => $palette[$i % count($palette)], array_keys(array_values($counts))),
            ]],
        ];
    }

    protected function chartOptions(): array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]], 'plugins' => ['legend' => ['position' => 'right']]];
    }
}
