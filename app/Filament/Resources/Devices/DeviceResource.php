<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use App\Support\Devices;
use App\Support\Qr;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The farm's phones (PLAN.md section 5.1, owner's call 25 October 2026): named here, paired with a one-use code,
 * disabled when lost or replaced. A card scan works only on a phone paired here. The owner and managers look
 * after the phones; a viewer has no business here.
 */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?int $navigationSort = 102;

    protected static ?string $slug = 'devices';

    public static function getNavigationGroup(): string
    {
        return __('plaashek.settings.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('plaashek.devices.title');
    }

    public static function getModelLabel(): string
    {
        return __('plaashek.devices.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('plaashek.devices.title');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && in_array($user->role, [User::OWNER, User::MANAGER, User::STAFF], true);
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canViewAny();
    }

    /** Never: records will name the phone they were captured on. A lost phone is disabled. */
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
            TextInput::make('label')->label(__('plaashek.devices.label'))->helperText(__('plaashek.devices.label_hint'))
                ->required()->maxLength(60),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('label')
            ->paginated(false)
            ->emptyStateHeading(__('plaashek.devices.none'))
            ->emptyStateDescription(__('plaashek.devices.none_hint'))
            ->columns([
                TextColumn::make('label')->label(__('plaashek.devices.label'))->weight('bold')->searchable(),
                TextColumn::make('state')->label(__('plaashek.devices.state'))->badge()
                    ->state(fn (Device $d) => match (true) {
                        $d->isDisabled() => __('plaashek.devices.disabled'),
                        $d->isPaired() => __('plaashek.devices.paired'),
                        default => __('plaashek.devices.waiting'),
                    })
                    ->color(fn (Device $d) => match (true) {
                        $d->isDisabled() => 'danger',
                        $d->isPaired() => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('lastWorker.first_name')->label(__('plaashek.devices.last_worker'))
                    ->state(fn (Device $d) => $d->lastWorker?->displayName())->placeholder('–'),
                TextColumn::make('last_sync_at')->label(__('plaashek.devices.last_sync'))->since()->placeholder('–'),
                TextColumn::make('last_seen_at')->label(__('plaashek.devices.last_seen'))->since()->placeholder('–')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // The code is made when the window opens and kept on the page while it is open: the window is
                // redrawn on every Livewire round trip, and a new code each time would outdate the one on screen.
                Action::make('pair')->label(fn (Device $d) => $d->isPaired() ? __('plaashek.devices.pair_again') : __('plaashek.devices.pair'))
                    ->icon(Heroicon::OutlinedQrCode)
                    ->visible(fn (Device $d) => ! $d->isDisabled())
                    ->modalHeading(fn (Device $d) => __('plaashek.devices.pair_heading', ['label' => $d->label]))
                    ->mountUsing(fn (Device $record, ListDevices $livewire) => $livewire->pairingCode = app(Devices::class)->pairingCode($record))
                    ->modalContent(fn (ListDevices $livewire) => view('filament.devices.pairing', self::pairing((string) $livewire->pairingCode)))
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('plaashek.devices.done')),
                EditAction::make()->label(__('plaashek.devices.rename'))
                    ->after(fn (Device $record) => AuditLog::record('device.renamed', $record->label)),
                Action::make('disable')->label(__('plaashek.devices.disable'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                    ->visible(fn (Device $d) => ! $d->isDisabled())
                    ->requiresConfirmation()->modalDescription(__('plaashek.devices.disable_hint'))
                    ->action(fn (Device $record) => app(Devices::class)->disable($record)),
                Action::make('enable')->label(__('plaashek.devices.enable'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Device $d) => $d->isDisabled())
                    ->action(fn (Device $record) => app(Devices::class)->enable($record)),
            ]);
    }

    /**
     * What the pairing window shows: the code, and a QR of the field app's address with the code in it, so the
     * phone's camera opens the app already pairing.
     *
     * @return array{code: string, url: string, qr: string, minutes: int}
     */
    public static function pairing(string $code): array
    {
        $url = url('/field').'#pair='.$code;

        return ['code' => $code, 'url' => $url, 'qr' => Qr::svg($url), 'minutes' => Devices::PAIRING_MINUTES];
    }

    public static function getPages(): array
    {
        return ['index' => ListDevices::route('/')];
    }
}
