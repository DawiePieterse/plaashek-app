<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Support\HerdState;
use App\Reports\ReportFilters;

/** The animals that left the herd in the period, sold or dead, with what they last weighed. */
class ExitsReport extends KuddeReport
{
    protected function name(): string
    {
        return 'exits';
    }

    public function columns(): array
    {
        return self::headings(['date', 'tag', 'name', 'kind', 'class', 'last_weight', 'note']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $state = HerdState::load();
        $events = self::events($filters)->whereIn('kind', [AnimalEvent::SALE, AnimalEvent::DEATH])->get();
        foreach ($events as $e) {
            $a = $e->animal;
            yield [
                'date' => $e->event_date->toDateString(), 'tag' => $a?->tag, 'name' => $a?->name,
                'kind' => __('kudde::ui.kind.'.$e->kind), 'class' => $a ? $state->classLabel($a) : null,
                'last_weight' => $a ? ($state->weights[$a->id][0] ?? null) : null, 'note' => $e->note,
            ];
        }
    }
}
