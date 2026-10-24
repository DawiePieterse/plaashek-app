<?php

namespace App\Filament\Resources\Workers;

use App\Models\Worker;
use App\Reports\DateRanges;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The register at a glance: who works here, who left, who still needs a photo for their card. */
class WorkerStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 4];
    }

    protected function getStats(): array
    {
        $season = DateRanges::seasonStart();

        return [
            Stat::make(__('plaashek.workers.stats.active'), (string) Worker::query()->where('active', true)->count())->color('success'),
            Stat::make(__('plaashek.workers.stats.inactive'), (string) Worker::query()->where('active', false)->count()),
            Stat::make(__('plaashek.workers.stats.no_photo'), (string) Worker::query()->where('active', true)->whereNull('photo')->count())
                ->description(__('plaashek.workers.stats.no_photo_hint'))->color('warning'),
            Stat::make(__('plaashek.workers.stats.added'), (string) Worker::query()->where('created_at', '>=', $season)->count())
                ->description(__('plaashek.workers.stats.since', ['date' => $season->translatedFormat('j M')])),
        ];
    }
}
