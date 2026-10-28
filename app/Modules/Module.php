<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * A Plaashek module (PLAN.md section 4.1). Each module is one service provider under app/Modules/<Name>/
 * that registers its own migrations, routes, office pages and settings, so the core never names a module.
 *
 * Every module's tables exist in every farm's database whether the farm has it or not: one schema for all
 * farms, one migration run for all. Switching a module on only makes it visible.
 */
abstract class Module extends ServiceProvider
{
    /** Short code, the Afrikaans name as the suite decided: `weer`, `kudde`, `werkers`. Prefixes its tables. */
    abstract public static function code(): string;

    /** Translation key of the module's name. */
    abstract public static function nameKey(): string;

    /** A free module is on for every farm and is never switched off for non-payment. */
    /** Whether the module may save new records today (its licence: ModuleRegistry::isWritable). */
    public static function writable(): bool
    {
        return app(ModuleRegistry::class)->isWritable(static::code());
    }

    public static function free(): bool
    {
        return false;
    }

    /**
     * Office pages (Filament page classes) the module adds to the panel. Each should hide itself with
     * canAccess() when the module is off. A module's own settings screen extends App\Filament\ModuleSettings,
     * which puts it in the shared Settings menu group.
     *
     * @return list<class-string>
     */
    public static function filamentPages(): array
    {
        return [];
    }

    /**
     * Office resources (Filament resource classes: lists with their forms) the module adds, such as Kudde's
     * camps. Each should refuse access with canViewAny() when the module is off.
     *
     * @return list<class-string>
     */
    public static function filamentResources(): array
    {
        return [];
    }

    /**
     * Where the module's screens open on a phone, or null if it has none. A module with one gets a tick on each
     * worker (the modules they may use) and, from the field app, a tile on the phone's home screen.
     */
    public static function fieldUrl(): ?string
    {
        return null;
    }

    /**
     * The module's tile on the office Home page, or null for none. Only asked for when the farm has the module
     * switched on. Keep it to a couple of quick queries: Home is the first page every office user opens.
     */
    public static function homeTile(): ?ModuleTile
    {
        return null;
    }

    /**
     * A view to show in the office's top bar after the farm's name (Weather's "now"), or null. Keep it light:
     * it is on every office page, so anything slow belongs in a request the view makes after the page is up.
     */
    public static function topbarView(): ?string
    {
        return null;
    }

    /** Absolute path of the module's own folder. */
    protected function modulePath(string $path = ''): string
    {
        $dir = dirname((string) (new \ReflectionClass(static::class))->getFileName());

        return $path === '' ? $dir : $dir.'/'.$path;
    }

    public function boot(): void
    {
        if (is_dir($this->modulePath('migrations'))) {
            $this->loadMigrationsFrom($this->modulePath('migrations'));
        }

        if (is_file($this->modulePath('routes.php'))) {
            $this->loadRoutesFrom($this->modulePath('routes.php'));
        }

        $this->bootModule();
    }

    /** The module's own boot work: commands, schedules, view namespaces. */
    protected function bootModule(): void {}
}
