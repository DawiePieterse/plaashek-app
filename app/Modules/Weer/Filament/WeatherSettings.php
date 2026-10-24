<?php

namespace App\Modules\Weer\Filament;

use App\Filament\ModuleSettings;
use App\Support\Farm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The Weather module's settings: when the rain season starts and what counts as frost. Moved here from Farm
 * settings, which now holds only what sets the farm up. The farm's position stays in Farm settings: every
 * module uses it, not only Weather.
 */
class WeatherSettings extends ModuleSettings
{
    protected static ?int $navigationSort = 120;

    protected static ?string $slug = 'settings/weer';

    protected static function moduleCode(): string
    {
        return 'weer';
    }

    protected static function titleKey(): string
    {
        return 'weer::ui.settings_title';
    }

    protected static function keys(): array
    {
        return [
            'season_start_month' => [Farm::SEASON_START_MONTH, '7'],
            'frost_threshold' => [Farm::FROST_THRESHOLD, '2'],
        ];
    }

    public function form(Schema $schema): Schema
    {
        $months = [];
        foreach (range(1, 12) as $m) {
            $months[(string) $m] = ucfirst(now()->setDate(2000, $m, 1)->locale(app()->getLocale())->translatedFormat('F'));
        }

        return $schema->components([
            Section::make()->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('season_start_month')->label(__('plaashek.settings.season_start_month'))
                        ->helperText(__('plaashek.settings.season_start_hint'))
                        ->options($months)->required()->native(),
                    TextInput::make('frost_threshold')->label(__('plaashek.settings.frost_threshold'))
                        ->helperText(__('plaashek.settings.frost_hint'))
                        ->numeric()->minValue(-10)->maxValue(10)->step(0.5)->suffix('°C')->required(),
                ]),
            ]),
        ])->statePath('data');
    }
}
