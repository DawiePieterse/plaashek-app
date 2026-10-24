<?php

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Actions\ReportsAction;
use App\Filament\Resources\Workers\WorkerResource;
use App\Filament\Resources\Workers\WorkerStats;
use App\Reports\Core\WorkerRegisterReport;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make(), ReportsAction::make([new WorkerRegisterReport])];
    }

    protected function getHeaderWidgets(): array
    {
        return [WorkerStats::class];
    }
}
