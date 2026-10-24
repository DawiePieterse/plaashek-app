<?php

namespace App\Modules\Kudde\Filament\Resources\Camps\Pages;

use App\Models\AuditLog;
use App\Models\Camp;
use App\Modules\Kudde\Filament\Resources\Camps\CampResource;
use App\Support\CampNames;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** Camps are added and renamed in place: one field each, so no page of their own. */
class ListCamps extends ListRecords
{
    protected static string $resource = CampResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // Through CampNames, so a camp archived under the same name comes back as the same camp.
                ->using(function (array $data): Camp {
                    $camp = CampNames::ensure($data['name']);
                    AuditLog::record('camp.created', $camp->name);

                    return $camp;
                }),
        ];
    }
}
