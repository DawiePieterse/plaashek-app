<?php

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditWorker extends EditRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('card')->label(__('plaashek.workers.print_card'))->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => route('workers.cards', ['ids' => $this->getRecord()->getKey()], absolute: false))
                ->openUrlInNewTab(),
        ];
    }
}
