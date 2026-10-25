<?php

namespace App\Modules\Kudde\Filament\Resources\Animals\Pages;

use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Support\Herd;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAnimal extends ViewRecord
{
    protected static string $resource = AnimalResource::class;

    /** The tag, as the farmer calls the animal, and its name if it has one. */
    public function getTitle(): string
    {
        $animal = $this->getRecord();

        return trim($animal->getAttribute('tag').' '.$animal->getAttribute('name'));
    }

    /**
     * The record put right (Herd::updateAnimal, audited). Not the tag: it is what every event and every phone's
     * cache knows the animal by. Not the status either: a death or sale event sets it, so removing that event
     * from the history is how a wrongly sold animal comes back.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('correct')->label(__('kudde::ui.corrections.correct_record'))->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn () => AnimalResource::mayCorrect())
                ->fillForm(fn (Animal $record) => $record->only(['name', 'breed', 'sex', 'birth_date', 'sire_tag', 'dam_tag', 'castrated'])
                    + ['birth_date' => $record->birth_date?->toDateString()])
                ->schema([
                    TextInput::make('name')->label(__('kudde::ui.columns.name'))->maxLength(100),
                    TextInput::make('breed')->label(__('kudde::ui.columns.breed'))->maxLength(100),
                    Select::make('sex')->label(__('kudde::ui.columns.sex'))->required()
                        ->options(collect(Animal::SEXES)->mapWithKeys(fn (string $s) => [$s => __('kudde::ui.sex.'.$s)])->all()),
                    Toggle::make('castrated')->label(__('kudde::ui.corrections.castrated')),
                    DatePicker::make('birth_date')->label(__('kudde::ui.columns.birth_date'))->maxDate(today()),
                    TextInput::make('dam_tag')->label(__('kudde::ui.columns.dam'))->maxLength(50),
                    TextInput::make('sire_tag')->label(__('kudde::ui.columns.sire'))->maxLength(50),
                ])
                ->action(function (Animal $record, array $data) {
                    app(Herd::class)->updateAnimal($record, $data);
                    $this->refreshFormData(['name', 'breed', 'sex', 'birth_date', 'sire_tag', 'dam_tag', 'castrated']);
                }),
        ];
    }
}
