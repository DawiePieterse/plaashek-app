<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Support\HerdState;
use App\Reports\ReportFilters;

/** The live herd today, camp by camp and class by class: the count a farmer does at the gate, on paper. */
class HeadCountReport extends KuddeReport
{
    protected function name(): string
    {
        return 'head_count';
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(): array
    {
        $columns = ['camp' => __('kudde::ui.columns.camp')];
        foreach (HerdState::load()->classLabels() as $label) {
            $columns[$label] = $label;
        }

        return $columns + ['total' => __('kudde::ui.columns.total')];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $state = HerdState::load();
        $labels = $state->classLabels();
        $totals = array_fill_keys($labels, 0);
        foreach ($state->campGroups() as [$camp, $animals]) {
            $row = ['camp' => $camp ?? __('kudde::ui.no_camp_yet')] + array_fill_keys($labels, 0);
            foreach ($animals as $a) {
                $row[$state->classLabel($a)]++;
                $totals[$state->classLabel($a)]++;
            }
            $row['total'] = count($animals);
            yield $row;
        }
        yield ['camp' => __('kudde::ui.columns.total')] + $totals + ['total' => array_sum($totals)];
    }
}
