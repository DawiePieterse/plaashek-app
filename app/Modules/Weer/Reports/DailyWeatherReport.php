<?php

namespace App\Modules\Weer\Reports;

use App\Modules\Weer\Models\WeatherDay;
use App\Reports\Report;
use App\Reports\ReportFilters;

/** Day by day at the farm's position: the record an insurer, a spray log or an audit asks for. */
class DailyWeatherReport extends Report
{
    public function key(): string
    {
        return 'weer.daily';
    }

    public function label(): string
    {
        return __('weer::ui.reports.daily');
    }

    public function columns(): array
    {
        return ['date' => __('weer::ui.reports.date'), 'temp_max' => __('weer::ui.reports.max'), 'temp_min' => __('weer::ui.reports.min'),
            'rain' => __('weer::ui.reports.rain'), 'et0' => 'ET0 mm', 'wind_max' => __('weer::ui.reports.wind')];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $days = WeatherDay::query()
            ->when($filters->from, fn ($q, $from) => $q->where('date', '>=', $from->toDateString()))
            ->when($filters->to, fn ($q, $to) => $q->where('date', '<=', $to->toDateString()))
            ->orderBy('date')->get();

        foreach ($days as $d) {
            yield ['date' => $d->date->toDateString(), 'temp_max' => $d->temp_max, 'temp_min' => $d->temp_min,
                'rain' => $d->rain, 'et0' => $d->et0, 'wind_max' => $d->wind_max];
        }
    }
}
