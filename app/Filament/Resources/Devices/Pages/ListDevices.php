<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\AuditLog;
use App\Models\Device;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** Phones are named and paired in place: one field, then the code. */
class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    /** The pairing code on screen, while its window is open. */
    public ?string $pairingCode = null;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('plaashek.devices.add'))
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])
                ->after(fn (Device $record) => AuditLog::record('device.created', $record->label)),
        ];
    }
}
