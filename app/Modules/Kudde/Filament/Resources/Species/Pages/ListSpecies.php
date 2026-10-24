<?php

namespace App\Modules\Kudde\Filament\Resources\Species\Pages;

use App\Modules\Kudde\Filament\Resources\Species\SpeciesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSpecies extends ListRecords
{
    protected static string $resource = SpeciesResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
