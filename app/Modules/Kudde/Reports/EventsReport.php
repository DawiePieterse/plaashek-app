<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\AnimalEvent;
use App\Reports\ReportFilters;
use App\Support\CampNames;
use Filament\Forms\Components\Select;

/** Everything recorded in the period, oldest first, with who recorded it and the day's weather. */
class EventsReport extends KuddeReport
{
    protected function name(): string
    {
        return 'events';
    }

    public function filters(): array
    {
        return [
            Select::make('kind')->label(__('kudde::ui.columns.kind'))->placeholder(__('kudde::ui.reports.all'))
                ->options(array_combine(AnimalEvent::KINDS, array_map(fn ($k) => __('kudde::ui.kind.'.$k), AnimalEvent::KINDS))),
            self::campFilter(),
        ];
    }

    public function columns(): array
    {
        return self::headings(['date', 'tag', 'species', 'name', 'kind', 'weight', 'camp', 'product', 'dose', 'withdrawal_days',
            'note', 'rain', 'by']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $camp = $filters->get('camp');
        foreach (self::events($filters, $filters->get('kind'))->lazy() as $e) {
            if ($camp !== null && CampNames::fold($e->location) !== CampNames::fold($camp)) {
                continue;
            }
            yield [
                'date' => $e->event_date->toDateString(), 'tag' => $e->animal?->tag, 'species' => $e->animal?->species?->name(),
                'name' => $e->animal?->name, 'kind' => __('kudde::ui.kind.'.$e->kind), 'weight' => $e->value, 'camp' => $e->location,
                'product' => $e->product, 'dose' => $e->dose, 'withdrawal_days' => $e->withdrawal_days, 'note' => $e->note,
                'rain' => $e->weather_rain, 'by' => self::who($e),
            ];
        }
    }
}
