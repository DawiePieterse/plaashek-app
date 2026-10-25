<?php

namespace App\Modules\Kudde\Filament\Resources\Animals\Pages;

use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use Filament\Resources\Pages\ListRecords;

class ListAnimals extends ListRecords
{
    protected static string $resource = AnimalResource::class;
}
