<?php

namespace App\Filament\Resources\Workers;

use App\Filament\Resources\Workers\Pages\CreateWorker;
use App\Filament\Resources\Workers\Pages\EditWorker;
use App\Filament\Resources\Workers\Pages\ListWorkers;
use App\Models\User;
use App\Models\Worker;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * The worker register under Farm settings (owner's call, 25 October 2026): the minimum the modules need, and
 * the cards. Every office user can see it and print cards; the owner and managers add and change workers. A
 * worker is never deleted, only made inactive, because records point at them.
 */
class WorkerResource extends Resource
{
    protected static ?string $model = Worker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 110;

    protected static ?string $slug = 'workers';

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): string
    {
        return __('plaashek.settings.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('plaashek.workers.title');
    }

    public static function getModelLabel(): string
    {
        return __('plaashek.workers.worker');
    }

    public static function getPluralModelLabel(): string
    {
        return __('plaashek.workers.title');
    }

    public static function canViewAny(): bool
    {
        return auth()->user() instanceof User;
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
            Section::make()->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('number')->label(__('plaashek.workers.number'))
                        ->helperText(__('plaashek.workers.number_hint'))
                        ->required()->maxLength(20)->unique(ignoreRecord: true)
                        // The QR is read by a camera, so keep to what prints and scans plainly.
                        ->regex('/^\s*[A-Za-z0-9\-\/]+\s*$/')
                        ->dehydrateStateUsing(fn (?string $state) => trim((string) $state)),
                    Toggle::make('active')->label(__('plaashek.workers.active'))
                        ->helperText(__('plaashek.workers.active_hint'))->default(true)->inline(false),
                    TextInput::make('first_name')->label(__('plaashek.workers.first_name'))->required()->maxLength(100),
                    TextInput::make('last_name')->label(__('plaashek.workers.last_name'))->maxLength(100),
                    TextInput::make('preferred_name')->label(__('plaashek.workers.preferred_name'))
                        ->helperText(__('plaashek.workers.preferred_name_hint'))->maxLength(50),
                    TextInput::make('cell')->label(__('plaashek.workers.cell'))->tel()->maxLength(30),
                ]),
                // Cropped square and shrunk in the browser before upload (~100 KB), because farm signal is
                // slow and the photos share the hosting package's disk with every other farm.
                FileUpload::make('photo')->label(__('plaashek.workers.photo'))
                    ->image()->disk(Worker::PHOTO_DISK)->directory(Worker::PHOTO_DIRECTORY)->visibility('private')
                    ->imageEditor()->imageCropAspectRatio('1:1')
                    ->imageResizeMode('cover')->imageResizeTargetWidth('400')->imageResizeTargetHeight('400')
                    ->maxSize(4096),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')->label('')->disk(Worker::PHOTO_DISK)->visibility('private')->circular()->imageSize(36),
                TextColumn::make('number')->label(__('plaashek.workers.number'))->searchable()->sortable(),
                TextColumn::make('first_name')->label(__('plaashek.workers.first_name'))->searchable()
                    ->description(fn (Worker $w) => $w->preferred_name !== '' ? $w->preferred_name : null),
                TextColumn::make('last_name')->label(__('plaashek.workers.last_name'))->searchable()->sortable(),
                TextColumn::make('cell')->label(__('plaashek.workers.cell'))->toggleable(),
                IconColumn::make('active')->label(__('plaashek.workers.active'))->boolean(),
            ])
            // Numbers in the order the farm reads them, as far as SQL can: by value (001, 002, 9, 010), then
            // by text. A number with letters counts as 0 for the first key, so those list first, in text order.
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('CAST(number AS UNSIGNED), CHAR_LENGTH(number), number'))
            ->filters([
                TernaryFilter::make('active')->label(__('plaashek.workers.active'))->default(true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('cards')->label(__('plaashek.workers.print_cards'))
                    ->icon(Heroicon::OutlinedPrinter)
                    ->action(fn (Collection $records, $livewire) => $livewire->js(
                        'window.open('.json_encode(route('workers.cards', ['ids' => $records->modelKeys() === [] ? '' : implode(',', $records->modelKeys())], absolute: false)).", '_blank')"
                    ))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkers::route('/'),
            'create' => CreateWorker::route('/create'),
            'edit' => EditWorker::route('/{record}/edit'),
        ];
    }

    private static function mayChange(): bool
    {
        $user = auth()->user();

        return $user instanceof User && in_array($user->role, [User::OWNER, User::MANAGER, User::STAFF], true);
    }
}
