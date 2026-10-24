<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Support\AnimalClass;
use App\Modules\Kudde\Support\HerdState;
use App\Reports\ReportFilters;
use App\Support\CampNames;
use Filament\Forms\Components\Select;

/** The herd as it stands today: every animal with its class, camp, last weight and withdrawal. */
class HerdRegister extends KuddeReport
{
    protected function name(): string
    {
        return 'herd';
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [
            Select::make('status')->label(__('kudde::ui.columns.status'))
                ->options(array_combine(Animal::STATUSES, array_map(fn ($s) => __('kudde::ui.status.'.$s), Animal::STATUSES)))
                ->default(Animal::ALIVE)->placeholder(__('kudde::ui.reports.all')),
            self::campFilter(),
            self::classFilter(),
        ];
    }

    public function columns(): array
    {
        return self::headings(['tag', 'name', 'breed', 'sex', 'class', 'birth_date', 'camp', 'last_weight', 'last_weighed',
            'withdrawal_until', 'status', 'dam', 'sire']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $state = HerdState::load();
        $camp = $filters->get('camp');

        foreach ($state->animals as $a) {
            $d = $state->describe($a);
            if (($filters->get('status') !== null && $a->status !== $filters->get('status'))
                || ($camp !== null && CampNames::fold((string) $d['camp']) !== CampNames::fold($camp))
                || ($filters->get('class') !== null && $d['class'] !== $filters->get('class'))) {
                continue;
            }
            yield [
                'tag' => $a->tag, 'name' => $a->name, 'breed' => $a->breed, 'sex' => __('kudde::ui.sex.'.$a->sex),
                'class' => AnimalClass::label($d['class']), 'birth_date' => $a->birth_date?->toDateString(), 'camp' => $d['camp'],
                'last_weight' => $d['last_weight'], 'last_weighed' => $d['last_weight_date'],
                'withdrawal_until' => $d['withdrawal_until'], 'status' => __('kudde::ui.status.'.$a->status),
                'dam' => $a->dam_tag, 'sire' => $a->sire_tag,
            ];
        }
    }
}
