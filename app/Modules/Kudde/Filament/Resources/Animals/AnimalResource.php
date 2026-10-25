<?php

namespace App\Modules\Kudde\Filament\Resources\Animals;

use App\Modules\Kudde\Filament\Resources\Animals\Pages\ListAnimals;
use App\Modules\Kudde\Filament\Resources\Animals\Pages\ViewAnimal;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Species;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\Kudde\Support\Lineage;
use App\Modules\ModuleRegistry;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The herd in the office (owner's call, 25 October 2026): every animal in a list, and a page per animal with its
 * record, its family (parents to great-grandparents, births and offspring: Support\Lineage) and its history.
 * Read only: recording is the field app's job, at the animal.
 */
class AnimalResource extends Resource
{
    protected static ?string $model = Animal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'kudde/animals';

    protected static ?string $recordTitleAttribute = 'tag';

    /** Under Kudde's own menu entry. */
    public static function getNavigationParentItem(): ?string
    {
        return 'Kudde';
    }

    public static function getNavigationLabel(): string
    {
        return __('kudde::ui.animals.title');
    }

    public static function getModelLabel(): string
    {
        return __('kudde::ui.animals.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kudde::ui.animals.title');
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && app(ModuleRegistry::class)->isEnabled('kudde');
    }

    public static function canView(Model $record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The derived herd (camp, weight, class), worked out once per request: a list of two hundred animals asks for
     * it once per row and column otherwise.
     */
    public static function state(): HerdState
    {
        $attributes = request()->attributes;
        if (! $attributes->get('kudde.herd') instanceof HerdState) {
            $attributes->set('kudde.herd', HerdState::load());
        }

        return $attributes->get('kudde.herd');
    }

    public static function table(Table $table): Table
    {
        $mixed = fn () => Species::enabled()->count() > 1;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('species'))
            ->defaultSort('tag')
            ->columns([
                TextColumn::make('tag')->label(__('kudde::ui.columns.tag'))->searchable()->weight('bold')
                    ->sortable(query: fn (Builder $query, string $direction) => self::byTag($query, $direction)),
                TextColumn::make('name')->label(__('kudde::ui.columns.name'))->searchable()->toggleable(),
                TextColumn::make('species_name')->label(__('kudde::ui.columns.species'))
                    ->state(fn (Animal $a) => $a->species->name())->visible($mixed),
                TextColumn::make('herd_class')->label(__('kudde::ui.columns.class'))
                    ->state(fn (Animal $a) => ucfirst(self::state()->classOf($a))),
                TextColumn::make('camp')->label(__('kudde::ui.columns.camp'))
                    ->state(fn (Animal $a) => self::state()->locations[$a->id] ?? null),
                TextColumn::make('last_weight')->label(__('kudde::ui.columns.last_weight'))
                    ->state(fn (Animal $a) => self::state()->weights[$a->id][0] ?? null)->numeric(1),
                TextColumn::make('birth_date')->label(__('kudde::ui.columns.birth_date'))->date('Y-m-d')->sortable()->toggleable(),
                TextColumn::make('status')->label(__('kudde::ui.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('kudde::ui.status.'.$state))
                    ->color(fn (string $state) => $state === Animal::ALIVE ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('kudde::ui.columns.status'))->default(Animal::ALIVE)
                    ->options(collect(Animal::STATUSES)->mapWithKeys(fn (string $s) => [$s => __('kudde::ui.status.'.$s)])->all()),
                SelectFilter::make('species_id')->label(__('kudde::ui.columns.species'))
                    ->options(fn () => Species::enabled()->mapWithKeys(fn (Species $s) => [$s->id => $s->name()])->all())
                    ->visible($mixed),
            ])
            ->recordUrl(fn (Animal $a) => self::getUrl('view', ['record' => $a]))
            ->paginated([50, 100, 'all']);
    }

    /**
     * Tags as they are read, B2 before B10 (Support\Tags::compare does this in PHP; a paged table needs it in SQL):
     * the letters, then the first run of digits as a number, then the whole tag. MariaDB's REGEXP functions.
     *
     * @param  Builder<Animal>  $query
     * @return Builder<Animal>
     */
    public static function byTag(Builder $query, string $direction): Builder
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw("REGEXP_REPLACE(tag, '[0-9]+', '') {$direction}")
            ->orderByRaw("CAST(REGEXP_SUBSTR(tag, '[0-9]+') AS UNSIGNED) {$direction}")
            ->orderBy('tag', $direction);
    }

    public static function infolist(Schema $schema): Schema
    {
        $describe = fn (Animal $record, string $key) => self::state()->describe($record)[$key] ?? null;

        return $schema->columns(1)->components([
            Section::make(__('kudde::ui.animals.record'))->schema([
                Grid::make(['default' => 2, 'md' => 4])->schema([
                    TextEntry::make('species_name')->label(__('kudde::ui.columns.species'))->state(fn (Animal $record) => $record->species->name()),
                    TextEntry::make('class')->label(__('kudde::ui.columns.class'))->state(fn (Animal $record) => ucfirst((string) $describe($record, 'class'))),
                    TextEntry::make('sex')->label(__('kudde::ui.columns.sex'))->formatStateUsing(fn (string $state) => __('kudde::ui.sex.'.$state)),
                    TextEntry::make('status')->label(__('kudde::ui.columns.status'))->badge()
                        ->formatStateUsing(fn (string $state) => __('kudde::ui.status.'.$state)),
                    TextEntry::make('name')->label(__('kudde::ui.columns.name'))->placeholder('-'),
                    TextEntry::make('breed')->label(__('kudde::ui.columns.breed'))->placeholder('-'),
                    TextEntry::make('birth_date')->label(__('kudde::ui.columns.birth_date'))->date('Y-m-d')->placeholder('-'),
                    TextEntry::make('camp')->label(__('kudde::ui.columns.camp'))->state(fn (Animal $record) => $describe($record, 'camp'))->placeholder('-'),
                    TextEntry::make('last_weight')->label(__('kudde::ui.columns.last_weight'))
                        ->state(fn (Animal $record) => $describe($record, 'last_weight'))->numeric(1)->placeholder('-'),
                    TextEntry::make('last_weight_date')->label(__('kudde::ui.columns.last_weighed'))
                        ->state(fn (Animal $record) => $describe($record, 'last_weight_date'))->placeholder('-'),
                    TextEntry::make('withdrawal_until')->label(__('kudde::ui.columns.withdrawal_until'))
                        ->state(fn (Animal $record) => $describe($record, 'withdrawal_until'))->placeholder('-'),
                ]),
            ]),
            Section::make(__('kudde::ui.animals.family'))->schema([
                View::make('kudde::animal-family')
                    ->viewData(fn (Animal $record) => ['tree' => (new Lineage(self::state()))->of($record)]),
            ]),
            Section::make(__('kudde::ui.animals.history'))->schema([
                View::make('kudde::animal-history')
                    ->viewData(fn (Animal $record) => ['events' => $record->events()->with('worker', 'user')
                        ->orderByDesc('event_date')->orderByDesc('id')->get()]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnimals::route('/'),
            'view' => ViewAnimal::route('/{record}'),
        ];
    }
}
