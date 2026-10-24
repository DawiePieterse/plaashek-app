<?php

namespace App\Modules\Kudde\Filament\Resources\Species\Pages;

use App\Models\AuditLog;
use App\Modules\Kudde\Filament\Resources\Species\SpeciesResource;
use App\Modules\Kudde\Models\Species;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

/** A species the seed did not have (horses, game): its code is made from its English name, once. */
class CreateSpecies extends CreateRecord
{
    protected static string $resource = SpeciesResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $base = Str::slug($data['name_en']) ?: 'species';
        $code = $base;
        for ($i = 2; Species::query()->where('code', $code)->exists(); $i++) {
            $code = $base.'-'.$i;
        }

        return $data + ['code' => $code, 'sort_order' => ((int) Species::query()->max('sort_order')) + 1];
    }

    protected function afterCreate(): void
    {
        AuditLog::record('kudde.species.created', $this->getRecord()->getAttribute('code'));
    }
}
