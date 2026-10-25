<?php

namespace App\Modules\Kudde\Filament\Resources\Animals\Pages;

use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAnimal extends ViewRecord
{
    protected static string $resource = AnimalResource::class;

    /** The tag, as the farmer calls the animal, and its name if it has one. */
    public function getTitle(): string
    {
        $animal = $this->getRecord();

        return trim($animal->getAttribute('tag').' '.$animal->getAttribute('name'));
    }
}
