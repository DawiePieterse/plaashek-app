<?php

namespace App\Modules\Kudde\Filament;

use App\Filament\Actions\ReportsAction;
use App\Modules\Kudde\Filament\Widgets\AverageWeightChart;
use App\Modules\Kudde\Filament\Widgets\HerdByCampChart;
use App\Modules\Kudde\Filament\Widgets\HerdByClassChart;
use App\Modules\Kudde\Filament\Widgets\HerdOverTimeChart;
use App\Modules\Kudde\Filament\Widgets\HerdWatchList;
use App\Modules\Kudde\Filament\Widgets\KuddeStats;
use App\Modules\Kudde\KuddeModule;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Kudde's screen in the office (owner's call, 25 October 2026): what is happening in the herd at a glance, in
 * numbers, graphs and two short lists, with the reports a farmer takes away as Excel or PDF. Recording is done
 * in the field app, one tap away.
 */
class KuddePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'kudde';

    protected string $view = 'kudde::office';

    public static function getNavigationLabel(): string
    {
        return 'Kudde';
    }

    public function getTitle(): string
    {
        return 'Kudde';
    }

    public static function canAccess(): bool
    {
        return auth()->check() && app(ModuleRegistry::class)->isEnabled('kudde');
    }

    protected function getHeaderActions(): array
    {
        return [
            ReportsAction::make(KuddeModule::reports()),
            Action::make('field')->label(__('kudde::ui.open_field_app'))->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->url(route('kudde.field'))->openUrlInNewTab(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [KuddeStats::class, HerdOverTimeChart::class, HerdByClassChart::class, HerdByCampChart::class,
            AverageWeightChart::class, HerdWatchList::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
