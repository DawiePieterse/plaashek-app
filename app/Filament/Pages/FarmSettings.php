<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Farm;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Farm settings (PLAN.md section 2.1), owner only: what the farm is called, where it is and how its screens
 * speak. The position is what the Weather module forecasts for. Each change is written to the audit log.
 *
 * @property-read Schema $form
 */
class FarmSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.farm-settings';

    /** @var array<string, mixed> */
    public array $data = [];

    /** Form field => setting key. */
    private const KEYS = [
        'name' => Farm::NAME,
        'farmer' => Farm::FARMER,
        'phone' => Farm::PHONE,
        'latitude' => Farm::LATITUDE,
        'longitude' => Farm::LONGITUDE,
        'language' => Farm::LANGUAGE,
        'season_start_month' => Farm::SEASON_START_MONTH,
        'frost_threshold' => Farm::FROST_THRESHOLD,
    ];

    /** Farm settings is a menu group: this page (the farm itself) and the worker register. */
    public static function getNavigationGroup(): string
    {
        return __('plaashek.settings.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('plaashek.settings.farm');
    }

    public function getTitle(): string|Htmlable
    {
        return __('plaashek.settings.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner();
    }

    public function mount(Settings $settings, Farm $farm): void
    {
        $data = [];
        foreach (self::KEYS as $field => $key) {
            $data[$field] = $settings->get($key);
        }
        // Farms set up before setup stored the farmer have none: the owner is who it would have been. It is
        // only shown here, and saved with the next Save, so nothing changes behind the owner's back.
        $data['farmer'] ??= User::query()->where('role', User::OWNER)->orderBy('id')->value('name');
        $data['language'] = $farm->language();
        $data['season_start_month'] = (string) $farm->seasonStartMonth();
        $data['frost_threshold'] = $farm->frostThreshold();

        $this->form->fill($data);
    }

    public function form(Schema $schema): Schema
    {
        $months = [];
        foreach (range(1, 12) as $m) {
            $months[(string) $m] = ucfirst(now()->setDate(2000, $m, 1)->locale(app()->getLocale())->translatedFormat('F'));
        }

        return $schema->components([
            Section::make(__('plaashek.settings.farm'))->schema([
                TextInput::make('name')->label(__('plaashek.settings.name'))->required()->maxLength(100),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('farmer')->label(__('plaashek.settings.farmer'))->maxLength(100),
                    TextInput::make('phone')->label(__('plaashek.settings.phone'))->tel()->maxLength(30),
                ]),
                Select::make('language')->label(__('plaashek.settings.language'))
                    ->options(['af' => 'Afrikaans', 'en' => 'English'])->required()->native(),
            ]),
            Section::make(__('plaashek.settings.position'))
                ->description(__('plaashek.settings.position_hint'))
                ->schema([
                    Grid::make(['default' => 2])->schema([
                        TextInput::make('latitude')->label(__('plaashek.settings.latitude'))
                            ->numeric()->minValue(-90)->maxValue(90)->requiredWith('longitude')->inputMode('decimal'),
                        TextInput::make('longitude')->label(__('plaashek.settings.longitude'))
                            ->numeric()->minValue(-180)->maxValue(180)->requiredWith('latitude')->inputMode('decimal'),
                    ]),
                    // A phone standing on the farm knows where it is; that beats looking up coordinates.
                    View::make('filament.pages.partials.use-my-location'),
                ]),
            Section::make(__('plaashek.settings.weather'))->schema([
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

    public function save(Settings $settings): void
    {
        $data = $this->form->getState();

        $before = [];
        $after = [];
        foreach (self::KEYS as $field => $key) {
            $old = $settings->get($key);
            $new = $data[$field] ?? null;
            $new = $new === null || $new === '' ? null : (string) $new;
            if (in_array($field, ['latitude', 'longitude'], true) && $new !== null) {
                $new = (string) round((float) $new, 4);
            }
            if ($old !== $new) {
                $settings->set($key, $new);
                $before[$field] = $old;
                $after[$field] = $new;
            }
        }

        if ($after !== []) {
            AuditLog::record('settings.changed', 'farm', $before, $after);
        }

        Notification::make()->title(__('plaashek.settings.saved'))->success()->send();

        // The language may have changed; reload so the whole panel speaks it.
        $this->redirect(self::getUrl());
    }
}
