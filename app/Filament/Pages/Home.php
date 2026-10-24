<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ModuleTiles;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The office's first page (owner's call, 25 October 2026): who is signed in, then one tile per module the farm
 * has, each with the one number that says how it is going, a tap away from the module itself.
 */
class Home extends Dashboard
{
    public static function getNavigationLabel(): string
    {
        return __('plaashek.home.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('plaashek.home.title');
    }

    public function getWidgets(): array
    {
        return [AccountWidget::class, ModuleTiles::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
