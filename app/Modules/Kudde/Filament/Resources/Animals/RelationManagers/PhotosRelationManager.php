<?php

namespace App\Modules\Kudde\Filament\Resources\Animals\RelationManagers;

use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Photo;
use App\Modules\Kudde\Support\Herd;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The animal's photos on its office page: added from the office here, from the field app through the API.
 * Shown by signed link only (the private disk), removed with the file.
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('kudde::ui.photos.title');
    }

    public function isReadOnly(): bool
    {
        return ! AnimalResource::mayCorrect();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['worker', 'user'])->reorder()->orderByDesc('taken_on')->orderByDesc('id'))
            ->paginated([12, 24, 'all'])
            ->emptyStateHeading(__('kudde::ui.photos.none'))
            ->columns([
                ImageColumn::make('url')->label('')->state(fn (Photo $p) => $p->url())->imageSize(160)
                    ->url(fn (Photo $p) => $p->url(), shouldOpenInNewTab: true),
                TextColumn::make('taken_on')->label(__('kudde::ui.columns.date'))->date('Y-m-d'),
                TextColumn::make('note')->label(__('kudde::ui.columns.note'))->color('gray')->wrap(),
                TextColumn::make('by')->label(__('kudde::ui.columns.by'))->color('gray')
                    ->state(fn (Photo $p) => $p->worker?->displayName() ?? $p->user?->name),
            ])
            ->headerActions([
                Action::make('add')->label(__('kudde::ui.photos.add'))->icon(Heroicon::OutlinedCamera)
                    ->visible(fn () => ! $this->isReadOnly())
                    ->schema([
                        // Resized in the browser before it is sent, as the phones do: the farms share one disk.
                        FileUpload::make('photo')->label(__('kudde::ui.photos.one'))->required()
                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk(Photo::DISK)->directory(fn () => Photo::directory((int) $this->getOwnerRecord()->getKey()))->visibility('private')
                            ->imageResizeMode('contain')->imageResizeTargetWidth('1600')->imageResizeTargetHeight('1600')
                            ->maxSize(Photo::MAX_KB),
                        DatePicker::make('taken_on')->label(__('kudde::ui.columns.date'))->default(today())->maxDate(today())->required(),
                        TextInput::make('note')->label(__('kudde::ui.columns.note'))->maxLength(200),
                    ])
                    ->action(function (array $data) {
                        /** @var Animal $animal */
                        $animal = $this->getOwnerRecord();
                        app(Herd::class)->by(auth()->id())->addPhoto($animal, $data['photo'], $data['taken_on'], (string) ($data['note'] ?? ''));
                    }),
            ])
            ->recordActions([
                Action::make('remove')->label(__('kudde::ui.corrections.remove'))->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn () => ! $this->isReadOnly())
                    ->requiresConfirmation()
                    ->action(fn (Photo $record) => app(Herd::class)->removePhoto($record)),
            ]);
    }
}
