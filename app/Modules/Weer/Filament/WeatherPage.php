<?php

namespace App\Modules\Weer\Filament;

use App\Filament\Actions\ReportsAction;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Modules\Weer\Filament\Widgets\RecentWeatherChart;
use App\Modules\Weer\Filament\Widgets\SeasonRainChart;
use App\Modules\Weer\Filament\Widgets\TemperatureChart;
use App\Modules\Weer\Filament\Widgets\WeatherStats;
use App\Modules\Weer\WeerModule;
use App\Reports\DateRanges;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

/**
 * Weather's screen in the office: the weather that has been at the farm (rain this week, month and season,
 * frost, the extremes) from its own record, with reports to take away. The forecast and the year-against-year
 * charts are the Weather app (/weer), one tap away, which also works offline on a phone.
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

    public function getTitle(): string
    {
        return __('plaashek.modules.weer');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && app(ModuleRegistry::class)->isEnabled('weer');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('forecast')->label(__('weer::ui.open_forecast'))->icon(Heroicon::OutlinedCloud)
                ->url(route('weer.page')),
            ReportsAction::make(WeerModule::reports()),
            // A farm's record starts the night it was set up. This fetches last season and this one at once, so
            // the season charts mean something from the first day. Owner only: it spends Open-Meteo calls.
            Action::make('history')->label(__('weer::ui.fill_history'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => auth()->user() instanceof User && auth()->user()->isOwner())
                ->requiresConfirmation()->modalDescription(__('weer::ui.fill_history_hint'))
                ->action(function () {
                    $code = Artisan::call('weer:days', ['--from' => DateRanges::seasonStart()->subYear()->toDateString()]);
                    $n = WeerModule::recordedDays();
                    Notification::make()->title($code === 0 ? __('weer::ui.history_filled', ['n' => $n]) : __('weer::ui.history_failed'))
                        ->{$code === 0 ? 'success' : 'danger'}()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [WeatherStats::class, RecentWeatherChart::class, SeasonRainChart::class, TemperatureChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
