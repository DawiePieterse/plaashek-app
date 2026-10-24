<?php

namespace App\Modules\Kudde\Filament\Resources\Camps;

use App\Models\AuditLog;
use App\Models\Camp;
use App\Models\User;
use App\Modules\Kudde\Filament\Resources\Camps\Pages\ListCamps;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\ModuleRegistry;
use App\Support\CampNames;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The farm's camps, under Kudde's settings (owner's call, 25 October 2026: camps are where the animals are kept,
 * so they live with Kudde). Listed in the farm's order, dragged to reorder; renamed without touching a single
 * record; archived, never deleted, and only once no alive animal is in the camp.
 */
class CampResource extends Resource
{
    protected static ?string $model = Camp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 131;

    protected static ?string $slug = 'settings/kudde/camps';

    /** Nested under Kudde's settings in the Settings group. */
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
        return __('kudde::ui.camps.title');
    }

    public static function getModelLabel(): string
    {
        return __('kudde::ui.camps.camp');
    }

    public static function getPluralModelLabel(): string
    {
        return __('kudde::ui.camps.title');
    }

    public static function canViewAny(): bool
    {
        return auth()->user() instanceof User && app(ModuleRegistry::class)->isEnabled('kudde');
    }

    public static function canCreate(): bool
    {
        return self::mayChange();
    }

    public static function canEdit(Model $record): bool
    {
        return self::mayChange();
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
        return $schema->components([
            TextInput::make('name')->label(__('kudde::ui.camps.name'))->required()->maxLength(100)
                ->dehydrateStateUsing(fn (?string $state) => CampNames::normalise($state))
                // One camp whatever its capitals, compared in PHP (Afrikaans diacritics), as CampNames does.
                ->rule(fn (?Camp $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    // Adding a name that was archived brings that camp back (ListCamps); renaming onto it is a clash.
                    $other = CampNames::find((string) $value);
                    if ($other !== null && $other->id !== $record?->id && ($record !== null || ! $other->archived)) {
                        $fail(__('kudde::ui.camps.exists', ['name' => $other->name]));
                    }
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order', self::mayChange())
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label(__('kudde::ui.camps.name'))->searchable()
                    ->badge(fn (Camp $camp) => $camp->archived)->color(fn (Camp $camp) => $camp->archived ? 'gray' : null)
                    ->description(fn (Camp $camp) => $camp->archived ? __('kudde::ui.camps.archived') : null),
                TextColumn::make('holds')->label(__('kudde::ui.camps.holds'))
                    ->state(fn (Camp $camp) => self::describeUsage($camp)),
            ])
            ->filters([
                TernaryFilter::make('archived')->label(__('kudde::ui.camps.archived'))->default(false),
            ])
            ->recordActions([
                EditAction::make()->after(fn (Camp $camp) => AuditLog::record('camp.updated', $camp->name)),
                Action::make('archive')->label(__('kudde::ui.camps.archive'))->icon(Heroicon::OutlinedArchiveBox)
                    ->visible(fn (Camp $camp) => self::mayChange() && ! $camp->archived)
                    ->requiresConfirmation()
                    ->action(function (Camp $camp) {
                        if (self::aliveIn($camp) > 0) {
                            Notification::make()->danger()
                                ->title(__('kudde::ui.camps.still_holds', ['name' => $camp->name, 'animals' => self::describeUsage($camp)]))
                                ->send();

                            return;
                        }
                        $camp->update(['archived' => true]);
                        AuditLog::record('camp.updated', $camp->name, ['archived' => false], ['archived' => true]);
                    }),
                Action::make('restore')->label(__('kudde::ui.camps.restore'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Camp $camp) => self::mayChange() && $camp->archived)
                    ->action(function (Camp $camp) {
                        $camp->update(['archived' => false]);
                        AuditLog::record('camp.updated', $camp->name, ['archived' => true], ['archived' => false]);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCamps::route('/')];
    }

    /** "12 animals" or "–": the alive animals in the camp. */
    public static function describeUsage(Camp $camp): string
    {
        $n = self::aliveIn($camp);

        return $n === 0 ? '–' : trans_choice('kudde::ui.camps.animals', $n, ['count' => $n]);
    }

    private static function aliveIn(Camp $camp): int
    {
        return HerdState::aliveByCamp()[CampNames::fold($camp->name)] ?? 0;
    }

    private static function mayChange(): bool
    {
        $user = auth()->user();

        return $user instanceof User && in_array($user->role, [User::OWNER, User::MANAGER, User::STAFF], true);
    }
}
