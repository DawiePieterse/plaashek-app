<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Support\HerdState;
use App\Reports\ReportFilters;
use App\Support\CampNames;

/**
 * Every weighing in the period with the animal's weighing before it (even from before the period), the gain
 * and the gain per day: what tells a farmer which animals are doing well and which are not.
 */
class WeighingsReport extends KuddeReport
{
    protected function name(): string
    {
        return 'weighings';
    }

    public function filters(): array
    {
        return [self::campFilter()];
    }

    public function columns(): array
    {
        return self::headings(['date', 'tag', 'name', 'weight', 'previous_weight', 'previous_date', 'gain', 'gain_per_day', 'camp']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $all = AnimalEvent::query()->where('kind', AnimalEvent::WEIGHT)
            ->when($filters->to, fn ($q, $to) => $q->where('event_date', '<=', $to->toDateString()))
            ->orderBy('event_date')->orderBy('id')->with('animal')->get()->groupBy('animal_id');
        $locations = HerdState::currentLocations();
        $camp = $filters->get('camp');

        $rows = [];
        foreach ($all as $weighings) {
            $previous = null;
            foreach ($weighings as $w) {
                $inRange = $filters->from === null || $w->event_date->gte($filters->from);
                $where = $locations[$w->animal_id] ?? null;
                if ($inRange && ($camp === null || CampNames::fold((string) $where) === CampNames::fold($camp))) {
                    $rows[] = self::row($w, $previous, $where);
                }
                $previous = $w;
            }
        }

        // Grouped by animal above; the report reads by date, then tag.
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['tag']] <=> [$b['date'], $b['tag']]);

        return $rows;
    }

    /** @return array{date: string, tag: string|null, name: string|null, weight: float, previous_weight: float|null, previous_date: string|null, gain: float|null, gain_per_day: float|null, camp: string|null} */
    private static function row(AnimalEvent $w, ?AnimalEvent $previous, ?string $where): array
    {
        $animal = $w->animal;
        $days = $previous !== null ? (int) $previous->event_date->diffInDays($w->event_date) : 0;
        $gain = $previous !== null ? round((float) $w->value - (float) $previous->value, 1) : null;

        return [
            'date' => $w->event_date->toDateString(), 'tag' => $animal?->tag, 'name' => $animal?->name,
            'weight' => (float) $w->value, 'previous_weight' => $previous !== null ? (float) $previous->value : null,
            'previous_date' => $previous?->event_date->toDateString(), 'gain' => $gain,
            'gain_per_day' => $gain !== null && $days > 0 ? round($gain / $days, 2) : null, 'camp' => $where,
        ];
    }
}
