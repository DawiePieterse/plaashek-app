<?php

namespace App\Filament;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Settings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A module's own settings screen, in the Settings menu group beside Farm settings (owner's call, 25 October
 * 2026: Farm settings holds what sets the farm up, each module keeps its own). Owner only, like Farm settings.
 * A subclass names its module, its settings (form field => setting key, with defaults) and its form; saving,
 * the audit log line and the menu entry are here, so every module's settings behave alike.
 *
 * @property-read Schema $form
 */
abstract class ModuleSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected string $view = 'filament.pages.settings-form';

    /** @var array<string, mixed> */
    public array $data = [];

    /** The module these settings belong to: the page is hidden while the farm has it off. */
    abstract protected static function moduleCode(): string;

    /** Translation key of the menu entry and title, e.g. "kudde::ui.settings.title". */
    abstract protected static function titleKey(): string;

    /**
     * Form field => [setting key, default].
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    abstract protected static function keys(): array;

    abstract public function form(Schema $schema): Schema;

    public static function getNavigationGroup(): string
    {
        return __('plaashek.settings.group');
    }

    public static function getNavigationLabel(): string
    {
        return __(static::titleKey());
    }

    public function getTitle(): string|Htmlable
    {
        return __(static::titleKey());
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwner() && app(ModuleRegistry::class)->isEnabled(static::moduleCode());
    }

    public function mount(Settings $settings): void
    {
        $data = [];
        foreach (static::keys() as $field => [$key, $default]) {
            $data[$field] = $settings->get($key, $default);
        }

        $this->form->fill($data);
    }

    public function save(Settings $settings): void
    {
        $data = $this->form->getState();
        $before = [];
        $after = [];

        foreach (static::keys() as $field => [$key]) {
            $old = $settings->get($key);
            $new = $data[$field] ?? null;
            $new = $new === null || $new === '' ? null : (string) $new;
            if ($old !== $new) {
                $settings->set($key, $new);
                $before[$field] = $old;
                $after[$field] = $new;
            }
        }

        if ($after !== []) {
            AuditLog::record('settings.changed', static::moduleCode(), $before, $after);
        }

        Notification::make()->title(__('plaashek.settings.saved'))->success()->send();
    }
}
