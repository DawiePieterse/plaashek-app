<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Filament\Widgets\FarmChart;
use App\Modules\Kudde\Support\HerdState;

/** Where the live herd is, camp by camp, in the farm's own order of camps. */
class HerdByCampChart extends FarmChart
{
    public function getHeading(): string
    {
        return __('kudde::ui.charts.by_camp');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $groups = HerdState::load()->campGroups();

        return [
            'labels' => array_map(fn ($g) => $g[0] ?? __('kudde::ui.no_camp_yet'), $groups),
            'datasets' => [['label' => __('kudde::ui.charts.head'), 'data' => array_map(fn ($g) => count($g[1]), $groups), 'backgroundColor' => '#5b9bd5']],
        ];
    }

    protected function chartOptions(): array
    {
        return ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
