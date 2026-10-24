<?php

namespace App\Modules\Kudde\Filament\Resources\Species;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Kudde\Filament\Resources\Species\Pages\CreateSpecies;
use App\Modules\Kudde\Filament\Resources\Species\Pages\EditSpecies;
use App\Modules\Kudde\Filament\Resources\Species\Pages\ListSpecies;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Species;
use App\Modules\Kudde\Support\KuddeSettings;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The livestock the farm keeps, under Kudde's settings (PLAN.md section 2.2, phase 4b): which species are on, and
 * each one's rules, all the farm's to set: its name, what its classes are called in both languages, how long its
 * young count as young, its gestation, how often to weigh it, the word for a birth, and whether its young are
 * tagged only at weaning. Owner only, like every settings screen. A species is never deleted: its animals keep
 * pointing at it; it is switched off instead, and comes back with its rules intact.
 */
class SpeciesResource extends Resource
{
    protected static ?string $model = Species::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 132;

    protected static ?string $slug = 'settings/kudde/species';

    public static function getNavigationParentItem(): ?string
    {
        return __('kudde::ui.settings.title');
    }

    public static function getNavigationGroup(): string
    {
        return __('plaashek.settings.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('kudde::ui.species.title');
    }

    public static function getModelLabel(): string
    {
        return __('kudde::ui.species.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kudde::ui.species.title');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner() && app(ModuleRegistry::class)->isEnabled('kudde');
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $classNames = [];
        foreach (Species::ROLES as $role) {
            $classNames[] = TextInput::make('class_names.af.'.$role)->label(__('kudde::ui.roles.'.$role).' (Afrikaans)')->required()->maxLength(30);
            $classNames[] = TextInput::make('class_names.en.'.$role)->label(__('kudde::ui.roles.'.$role).' (English)')->required()->maxLength(30);
        }

        return $schema->columns(1)->components([
            Section::make(__('kudde::ui.species.names'))->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('name_af')->label(__('kudde::ui.species.name').' (Afrikaans)')->required()->maxLength(50),
                    TextInput::make('name_en')->label(__('kudde::ui.species.name').' (English)')->required()->maxLength(50),
                    TextInput::make('birth_word.af')->label(__('kudde::ui.species.birth_word').' (Afrikaans)')->required()->maxLength(30),
                    TextInput::make('birth_word.en')->label(__('kudde::ui.species.birth_word').' (English)')->required()->maxLength(30),
                ]),
                Toggle::make('enabled')->label(__('kudde::ui.species.enabled'))->helperText(__('kudde::ui.species.enabled_hint'))
                    ->rule(fn (?Species $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                        // A farm with no species on would have nowhere to put a new animal.
                        if (! $value && Species::query()->where('enabled', true)->when($record, fn ($q) => $q->whereKeyNot($record->id))->doesntExist()) {
                            $fail(__('kudde::ui.species.keep_one'));
                        }
                    }),
            ]),
            Section::make(__('kudde::ui.species.rules'))->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    TextInput::make('young_until_days')->label(__('kudde::ui.species.young_until'))->helperText(__('kudde::ui.species.young_until_hint'))
                        ->integer()->minValue(7)->maxValue(1500)->suffix(__('kudde::ui.settings.days'))->required(),
                    TextInput::make('gestation_days')->label(__('kudde::ui.species.gestation'))->helperText(__('kudde::ui.species.gestation_hint'))
                        ->integer()->minValue(20)->maxValue(500)->suffix(__('kudde::ui.settings.days'))->required(),
                    TextInput::make('weigh_every_days')->label(__('kudde::ui.settings.weigh_every_days'))
                        ->helperText(fn () => __('kudde::ui.species.weigh_hint', ['days' => KuddeSettings::weighEveryDays()]))
                        ->integer()->minValue(7)->maxValue(730)->suffix(__('kudde::ui.settings.days')),
                ]),
                Toggle::make('young_tagged_at_weaning')->label(__('kudde::ui.species.weaning'))->helperText(__('kudde::ui.species.weaning_hint')),
            ]),
            Section::make(__('kudde::ui.species.classes'))->description(__('kudde::ui.species.classes_hint'))
                ->schema([Grid::make(['default' => 1, 'sm' => 2])->schema($classNames)]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label(__('kudde::ui.columns.species'))->state(fn (Species $s) => $s->name())
                    ->description(fn (Species $s) => $s->className('mother').', '.$s->className('male').', '.$s->className('young_female')),
                TextColumn::make('alive')->label(__('kudde::ui.stats.alive'))
                    ->state(fn (Species $s) => Animal::query()->where('species_id', $s->id)->where('status', Animal::ALIVE)->count()),
                TextColumn::make('gestation_days')->label(__('kudde::ui.species.gestation'))->suffix(' '.__('kudde::ui.settings.days')),
                ToggleColumn::make('enabled')->label(__('kudde::ui.species.enabled'))
                    // The last species on stays on: a farm with none would have nowhere to put a new animal.
                    ->disabled(fn (Species $record) => $record->enabled && Species::query()->where('enabled', true)->count() === 1)
                    ->afterStateUpdated(fn (Species $record, bool $state) => AuditLog::record('kudde.species.updated', $record->code,
                        ['enabled' => ! $state], ['enabled' => $state])),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecies::route('/'),
            'create' => CreateSpecies::route('/create'),
            'edit' => EditSpecies::route('/{record}/edit'),
        ];
    }
}
