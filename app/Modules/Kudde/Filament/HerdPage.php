<?php

namespace App\Modules\Kudde\Filament;

use App\Filament\Actions\ReportsAction;
use App\Models\Camp;
use App\Models\User;
use App\Modules\Kudde\KuddeModule;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\Kudde\Support\Tags;
use App\Modules\ModuleRegistry;
use App\Support\CampNames;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * The herd list in the office (PLAN.md section 2.2, phase 5): every animal with what the farmer asks of it
 * first, where it is, what it is, what it last weighed and whether it may go to the sale. Camp, class, weight
 * and withdrawal are derived, never stored (Support\HerdState), so the table is built from HerdState's rows and
 * searched, filtered and sorted in PHP rather than in SQL: a farm's herd is hundreds of animals, not millions,
 * and this way the list can never disagree with the dashboard, the reports or the phones.
 * A page with its own table, not a resource: Filament's resource lists take Eloquent rows only. Recording stays
 * in the field app; the animal page and corrections come next.
 */
class HerdPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 21;

    protected static ?string $slug = 'kudde/herd';

    protected string $view = 'kudde::herd';

    /** Under Kudde's screen in the menu: the herd is the module's second place to look. */
    public static function getNavigationParentItem(): ?string
    {
        return 'Kudde';
    }

    public static function getNavigationLabel(): string
    {
        return __('kudde::ui.herd.title');
    }

    public function getTitle(): string
    {
        return __('kudde::ui.herd.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && app(ModuleRegistry::class)->isEnabled('kudde');
    }

    /** The same reports as Kudde's screen (the herd register is this list on paper), and the way back to it. */
    protected function getHeaderActions(): array
    {
        return [
            ReportsAction::make(KuddeModule::reports()),
            Action::make('kudde')->label('Kudde')->icon(Heroicon::OutlinedSquares2x2)->color('gray')->url(KuddePage::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        // One load per request: the closures below are asked per row and per filter.
        $state = HerdState::load();
        $classes = $state->classLabels();

        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, array $filters, int|string $page, int|string $recordsPerPage) => self::page(
                self::rows($state, $search, $filters, $sortColumn, $sortDirection), (int) $page, (int) $recordsPerPage))
            ->defaultSort('tag')
            ->modelLabel(__('kudde::ui.herd.animal'))
            ->pluralModelLabel(__('kudde::ui.herd.title'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->searchPlaceholder(__('kudde::ui.herd.search'))
            ->emptyStateHeading(__('kudde::ui.herd.empty'))
            ->columns([
                TextColumn::make('tag')->label(__('kudde::ui.columns.tag'))->weight('bold')->sortable()->searchable()
                    ->description(fn (array $record) => $record['name'] ?: null),
                TextColumn::make('species_name')->label(__('kudde::ui.columns.species'))->sortable()
                    ->visible($state->mixed()),
                TextColumn::make('class_label')->label(__('kudde::ui.columns.class'))->sortable(),
                TextColumn::make('camp')->label(__('kudde::ui.columns.camp'))->sortable()
                    ->placeholder(__('kudde::ui.no_camp_yet')),
                TextColumn::make('last_weight')->label(__('kudde::ui.columns.last_weight'))->sortable()->numeric(1)
                    ->description(fn (array $record) => $record['last_weight_date'] !== null
                        ? Carbon::parse($record['last_weight_date'])->translatedFormat('j M Y') : null)
                    ->color(fn (array $record) => $record['needs_weighing'] ? 'warning' : null)
                    ->placeholder('–'),
                TextColumn::make('withdrawal_until')->label(__('kudde::ui.columns.withdrawal_until'))->sortable()
                    ->date('j M Y')->badge()->color('danger')->placeholder('–'),
                TextColumn::make('birth_date')->label(__('kudde::ui.columns.birth_date'))->sortable()
                    ->date('j M Y')->placeholder('–')->toggleable(),
                TextColumn::make('breed')->label(__('kudde::ui.columns.breed'))->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('dam_tag')->label(__('kudde::ui.columns.dam'))->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sire_tag')->label(__('kudde::ui.columns.sire'))->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('kudde::ui.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state) => __('kudde::ui.status.'.$state))
                    ->color(fn (string $state) => $state === Animal::ALIVE ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('kudde::ui.columns.status'))
                    ->options(array_combine(Animal::STATUSES, array_map(fn ($s) => __('kudde::ui.status.'.$s), Animal::STATUSES)))
                    ->default(Animal::ALIVE),
                SelectFilter::make('species')->label(__('kudde::ui.columns.species'))
                    ->options($state->speciesInPlay()->mapWithKeys(fn ($s) => [$s->code => $s->name()])->all())
                    ->visible($state->mixed()),
                SelectFilter::make('camp')->label(__('kudde::ui.columns.camp'))
                    ->options(Camp::query()->inFarmOrder()->where('archived', false)->pluck('name', 'name')->all()),
                SelectFilter::make('class')->label(__('kudde::ui.columns.class'))->options(array_combine($classes, $classes)),
                Filter::make('in_withdrawal')->label(__('kudde::ui.herd.in_withdrawal'))->toggle(),
                Filter::make('needs_weighing')->label(__('kudde::ui.herd.needs_weighing'))->toggle(),
            ]);
    }

    /**
     * The herd as the table's rows, searched, filtered and sorted. A row is HerdState's describe() of the animal
     * (what the phones get) plus the class and species as the counts name them.
     *
     * @param  array<string, mixed>  $filters  the table's filter state, as Filament keeps it
     * @return list<array<string, mixed>>
     */
    public static function rows(HerdState $state, ?string $search = null, array $filters = [], ?string $sortColumn = 'tag', ?string $sortDirection = 'asc'): array
    {
        $status = $filters['status']['value'] ?? null;
        $species = $filters['species']['value'] ?? null;
        $camp = $filters['camp']['value'] ?? null;
        $class = $filters['class']['value'] ?? null;
        $needle = mb_strtolower(trim((string) $search));

        $rows = [];
        foreach ($state->animals as $animal) {
            $row = $state->describe($animal) + [
                ArrayRecord::getKeyName() => (string) $animal->id,
                'class_label' => $state->classLabel($animal),
                'species_name' => $state->speciesOf($animal)->name(),
                'needs_weighing' => $state->needsWeighing($animal),
            ];

            if (($status !== null && $status !== '' && $animal->status !== $status)
                || ($species !== null && $species !== '' && $row['species'] !== $species)
                || ($camp !== null && $camp !== '' && CampNames::fold((string) $row['camp']) !== CampNames::fold($camp))
                || ($class !== null && $class !== '' && $row['class_label'] !== $class)
                || (! empty($filters['in_withdrawal']['isActive']) && $row['withdrawal_until'] === null)
                || (! empty($filters['needs_weighing']['isActive']) && ! $row['needs_weighing'])
                || ($needle !== '' && ! self::matches($row, $needle))) {
                continue;
            }
            $rows[] = $row;
        }

        $column = $sortColumn ?: 'tag';
        $direction = $sortDirection === 'desc' ? -1 : 1;
        usort($rows, function (array $a, array $b) use ($column, $direction) {
            // Empty values last whichever way the column is sorted; ties in the tag's order, as the farmer reads it.
            $x = $a[$column] ?? null;
            $y = $b[$column] ?? null;
            if (($x === null) !== ($y === null)) {
                return $x === null ? 1 : -1;
            }

            return $direction * self::compare($column, $x, $y) ?: Tags::compare($a['tag'], $b['tag']);
        });

        return $rows;
    }

    /**
     * Tag, name, breed, dam or sire, ignoring capitals: what an office looks an animal up by.
     *
     * @param  array<string, mixed>  $row
     */
    private static function matches(array $row, string $needle): bool
    {
        foreach (['tag', 'name', 'breed', 'dam_tag', 'sire_tag'] as $field) {
            if (str_contains(mb_strtolower((string) ($row[$field] ?? '')), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function compare(string $column, mixed $x, mixed $y): int
    {
        return match (true) {
            $x === null => 0,
            in_array($column, ['tag', 'camp'], true) => Tags::compare((string) $x, (string) $y),
            is_numeric($x) && is_numeric($y) => (float) $x <=> (float) $y,
            default => strcmp((string) $x, (string) $y),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private static function page(array $rows, int $page, int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * $perPage, $perPage), count($rows), $perPage, $page);
    }
}
