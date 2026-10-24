<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Workers\WorkerResource;
use App\Models\Worker;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleTile;
use Filament\Widgets\Widget;

/**
 * The tiles on Home: the core's own (the workers) and one for each module the farm has switched on, asked of
 * the module itself (Module::homeTile), so the core never names a module.
 */
class ModuleTiles extends Widget
{
    protected string $view = 'filament.widgets.module-tiles';

    protected int|string|array $columnSpan = 'full';

    // In the page itself, not loaded after it: the tiles are what Home is for.
    protected static bool $isLazy = false;

    /** @return list<ModuleTile> */
    public function tiles(): array
    {
        $active = Worker::query()->where('active', true)->count();
        $tiles = [new ModuleTile(
            label: __('plaashek.workers.title'),
            icon: '🪪',
            value: (string) $active,
            caption: trans_choice('plaashek.home.workers_active', $active),
            url: WorkerResource::getUrl(),
            sort: 0,
        )];

        $modules = app(ModuleRegistry::class);
        foreach ($modules->all() as $module) {
            if ($modules->isEnabled($module::code()) && ($tile = $module::homeTile()) !== null) {
                $tiles[] = $tile;
            }
        }

        usort($tiles, fn (ModuleTile $a, ModuleTile $b) => $a->sort <=> $b->sort);

        return $tiles;
    }
}
