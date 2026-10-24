<?php

namespace App\Modules\Weer\Filament;

use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The Weather entry in the office menu. The Weather screens are their own page at /weer (installable on a
 * phone, offline-capable), so this entry just opens it.
 */
class WeatherPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'weer';

    protected string $view = 'weer::office';

    public static function getNavigationLabel(): string
    {
        return __('plaashek.modules.weer');
    }

    public static function getNavigationUrl(): string
    {
        return route('weer.page');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && app(ModuleRegistry::class)->isEnabled('weer');
    }

    public function mount(): void
    {
        $this->redirect(route('weer.page'));
    }
}
