<?php

namespace App\Modules\Kudde\Filament\Resources\Species\Pages;

use App\Models\AuditLog;
use App\Modules\Kudde\Filament\Resources\Species\SpeciesResource;
use Filament\Resources\Pages\EditRecord;

class EditSpecies extends EditRecord
{
    protected static string $resource = SpeciesResource::class;

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        $changed = array_keys(array_diff_key($record->getChanges(), ['updated_at' => true]));
        if ($changed !== []) {
            AuditLog::record('kudde.species.updated', $record->getAttribute('code'), null, $record->only($changed));
        }
    }
}
