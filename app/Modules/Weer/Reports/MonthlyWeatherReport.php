<?php

namespace App\Modules\Weer\Reports;

use App\Modules\Weer\Models\WeatherDay;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\Farm;

/** Month by month: rain, average highs and lows, frost nights and ET0, for the season's paperwork. */
class MonthlyWeatherReport extends Report
{
    public function key(): string
    {
        return 'weer.monthly';
    }

    public function label(): string
    {
        return __('weer::ui.reports.monthly');
    }

    public function columns(): array
    {
        return ['month' => __('weer::ui.reports.month'), 'days' => __('weer::ui.reports.days'), 'rain' => __('weer::ui.reports.rain'),
            'avg_max' => __('weer::ui.reports.avg_max'), 'avg_min' => __('weer::ui.reports.avg_min'),
            'frost' => __('weer::ui.reports.frost'), 'et0' => 'ET0 mm'];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $frost = app(Farm::class)->frostThreshold();
        $months = WeatherDay::query()
            ->when($filters->from, fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($filters->to, fn ($q, $to) => $q->where('date', '<=', $to->toDateString()))
            ->orderBy('date')->get()->groupBy(fn (WeatherDay $d) => $d->date->format('Y-m'));

        foreach ($months as $month => $days) {
            yield [
                'month' => $days->first()->date->translatedFormat('F Y'), 'days' => $days->count(),
                'rain' => round((float) $days->sum('rain'), 1),
                'avg_max' => round((float) $days->avg('temp_max'), 1), 'avg_min' => round((float) $days->avg('temp_min'), 1),
                'frost' => $days->filter(fn ($d) => $d->temp_min !== null && $d->temp_min <= $frost)->count(),
                'et0' => round((float) $days->sum('et0'), 1),
            ];
        }
    }
}
