<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

/**
 * Every chart on a module's screen: drawn with the page (not loaded after it), and a fixed height whatever the
 * card's width, because Chart.js keeps its aspect ratio by default and squashed a chart in a half-width card to
 * a thin strip on an iPad. A chart gives its own options in chartOptions().
 */
abstract class FarmChart extends ChartWidget
{
    protected static bool $isLazy = false;

    /** @return array<string, mixed> */
    protected function chartOptions(): array
    {
        return [];
    }

    protected function getOptions(): array
    {
        return array_replace_recursive(['maintainAspectRatio' => false, 'responsive' => true], $this->chartOptions());
    }
}
