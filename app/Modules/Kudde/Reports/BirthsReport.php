<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Reports\ReportFilters;

/**
 * The calves born in the period: each with its dam and sire, the camp it was born in and how it went, which is
 * the note on the dam's birth event ("Calf K1 - pulled, up and sucking").
 */
class BirthsReport extends KuddeReport
{
    protected function name(): string
    {
        return 'births';
    }

    public function columns(): array
    {
        return self::headings(['date', 'tag', 'sex', 'dam', 'sire', 'camp', 'note']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $births = self::events($filters, AnimalEvent::BIRTH)->get();
        $damNotes = [];
        foreach ($births as $e) {
            if (preg_match('/^Calf (\S+)(?: - (.*))?$/u', $e->note, $m)) {
                $damNotes[mb_strtolower($m[1])] = $m[2] ?? '';
            }
        }

        foreach ($births as $e) {
            $calf = $e->animal;
            // The calf's own birth event is dated at its birth date; the dam's is her calving.
            if (! $calf instanceof Animal || $calf->birth_date === null || ! $calf->birth_date->isSameDay($e->event_date)) {
                continue;
            }
            yield [
                'date' => $e->event_date->toDateString(), 'tag' => $calf->tag, 'sex' => __('kudde::ui.sex.'.$calf->sex),
                'dam' => $calf->dam_tag, 'sire' => $calf->sire_tag, 'camp' => $e->location,
                'note' => $damNotes[mb_strtolower($calf->tag)] ?? '',
            ];
        }
    }
}
