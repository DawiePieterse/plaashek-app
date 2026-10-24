<?php

namespace App\Modules\Kudde\Filament;

use App\Filament\ModuleSettings;
use App\Models\Camp;
use App\Modules\Kudde\Filament\Resources\Camps\CampResource;
use App\Modules\Kudde\Support\KuddeSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Kudde's settings screen: how often animals should be weighed, and the field app's address with a QR code, so
 * a farmer standing in the office holds a phone up to the screen and has Kudde open on it. The livestock types
 * and their rules join this screen with phase 4b (PLAN.md section 2.2).
 */
class KuddeSettingsPage extends ModuleSettings
{
    protected static ?int $navigationSort = 130;

    protected static ?string $slug = 'settings/kudde';

    protected static function moduleCode(): string
    {
        return 'kudde';
    }

    protected static function titleKey(): string
    {
        return 'kudde::ui.settings.title';
    }

    protected static function keys(): array
    {
        return [
            'weigh_every_days' => [KuddeSettings::WEIGH_EVERY_DAYS, (string) KuddeSettings::DEFAULT_WEIGH_EVERY_DAYS],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('kudde::ui.settings.herd'))->schema([
                TextInput::make('weigh_every_days')->label(__('kudde::ui.settings.weigh_every_days'))
                    ->helperText(__('kudde::ui.settings.weigh_every_days_hint'))
                    ->integer()->minValue(7)->maxValue(730)->suffix(__('kudde::ui.settings.days'))->required(),
            ]),
            // The camps are their own list (renamed, reordered, archived one at a time), opened from here.
            Section::make(__('kudde::ui.camps.title'))
                ->description(fn () => trans_choice('kudde::ui.settings.camps_hint', Camp::query()->where('archived', false)->count(),
                    ['count' => Camp::query()->where('archived', false)->count()]))
                ->headerActions([
                    Action::make('camps')->label(__('kudde::ui.settings.manage_camps'))->icon(Heroicon::OutlinedMap)
                        ->url(CampResource::getUrl()),
                ]),
            Section::make(__('kudde::ui.settings.field_app'))
                ->description(__('kudde::ui.settings.field_app_hint'))
                ->schema([View::make('kudde::settings-field-app')]),
        ])->statePath('data');
    }
}
