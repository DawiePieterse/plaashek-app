<?php

namespace App\Modules\Kudde\Filament\Resources\Animals\RelationManagers;

use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Support\Herd;
use App\Modules\Kudde\Support\HerdException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The animal's history on its office page, newest first, and where the office puts a capture right (PLAN.md
 * section 2.2): a mistyped weight, a wrong date or dose is corrected; an event on the wrong animal, or recorded
 * twice, is removed. Every correction goes to the audit log, through Support\Herd like every other write.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    /** Rendered with the page: the history is what the page is opened for. */
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kudde::ui.animals.history');
    }

    /** A viewer reads; the people who run the herd correct it. */
    public function isReadOnly(): bool
    {
        return ! AnimalResource::mayCorrect();
    }

    public function form(Schema $schema): Schema
    {
        $is = fn (string ...$kinds) => fn (?AnimalEvent $record) => in_array($record?->kind, $kinds, true);

        return $schema->columns(2)->components([
            DatePicker::make('event_date')->label(__('kudde::ui.columns.date'))->required()->maxDate(today()),
            TextInput::make('value')->label(__('kudde::ui.columns.weight'))->numeric()->minValue(0.1)->required()
                ->visible($is(AnimalEvent::WEIGHT)),
            TextInput::make('location')->label(__('kudde::ui.columns.camp'))->maxLength(100)
                ->visible($is(AnimalEvent::MOVEMENT, AnimalEvent::BIRTH)),
            TextInput::make('product')->label(__('kudde::ui.columns.product'))->maxLength(100)->visible($is(AnimalEvent::TREATMENT)),
            TextInput::make('dose')->label(__('kudde::ui.columns.dose'))->maxLength(50)->visible($is(AnimalEvent::TREATMENT)),
            TextInput::make('withdrawal_days')->label(__('kudde::ui.columns.withdrawal_days'))->integer()->minValue(0)->maxValue(3650)
                ->visible($is(AnimalEvent::TREATMENT)),
            TextInput::make('note')->label(__('kudde::ui.columns.note'))->maxLength(500)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['worker', 'user'])->reorder()->orderByDesc('event_date')->orderByDesc('id'))
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('kudde::ui.animals.no_history'))
            ->columns([
                TextColumn::make('event_date')->label(__('kudde::ui.columns.date'))->date('Y-m-d'),
                TextColumn::make('kind')->label(__('kudde::ui.columns.kind'))->weight('bold')
                    ->formatStateUsing(fn (string $state) => __('kudde::ui.kind.'.$state)),
                TextColumn::make('detail')->label('')
                    ->state(fn (AnimalEvent $e) => self::detail($e)),
                TextColumn::make('note')->label(__('kudde::ui.columns.note'))->color('gray')->wrap(),
                TextColumn::make('by')->label(__('kudde::ui.columns.by'))->color('gray')
                    ->state(fn (AnimalEvent $e) => $e->worker?->displayName() ?? $e->user?->name),
            ])
            ->recordActions([
                EditAction::make()->label(__('kudde::ui.corrections.correct'))->modalHeading(__('kudde::ui.corrections.correct_event'))
                    ->using(fn (AnimalEvent $record, array $data) => app(Herd::class)->correctEvent($record, $data)),
                Action::make('remove')->label(__('kudde::ui.corrections.remove'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->requiresConfirmation()->modalDescription(__('kudde::ui.corrections.remove_event_hint'))
                    ->action(function (AnimalEvent $record) {
                        try {
                            app(Herd::class)->removeEvent($record);
                        } catch (HerdException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }

    /** What the event was, in a few words: the weight, the camp, the product and dose. */
    private static function detail(AnimalEvent $e): string
    {
        return implode(' · ', array_filter([
            $e->value !== null ? number_format($e->value, 1).' kg' : null,
            $e->location ?: null,
            trim($e->product.' '.$e->dose) ?: null,
            $e->withdrawal_days !== null ? trans_choice('kudde::ui.corrections.withdrawal', $e->withdrawal_days, ['days' => $e->withdrawal_days]) : null,
        ]));
    }
}
