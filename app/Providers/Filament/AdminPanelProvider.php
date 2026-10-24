<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Filament\Pages\FarmSettings;
use App\Filament\Pages\Home;
use App\Http\Middleware\SetFarmLocale;
use App\Modules\ModuleRegistry;
use App\Support\Farm;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The farm office at /admin (PLAN.md section 5): one entry per module the farm has switched on, plus Farm
 * settings. Modules add their pages through App\Modules\Module::filamentPages(); each page hides itself
 * when its module is off.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $modulePages = [];
        $moduleResources = [];
        foreach ((array) config('plaashek.modules', []) as $module) {
            array_push($modulePages, ...$module::filamentPages());
            array_push($moduleResources, ...$module::filamentResources());
        }

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile(isSimple: false)
            ->brandName(fn (): string => app(Farm::class)->name())
            // No global search box: on a phone it pushed the farm's name out of the top bar, and every list
            // (workers, camps) has its own search.
            ->globalSearch(false)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::Green,
                'gray' => Color::Stone,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->resources($moduleResources)
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Home::class,
                FarmSettings::class,
                ...$modulePages,
            ])
            // The farm's name and the weather on every page, the release on the right (owner's sketch, 25 October).
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.head'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.styles'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => view('filament.topbar.farm', [
                'farm' => app(Farm::class)->name(),
                'views' => self::moduleTopbarViews(),
            ]))
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_BEFORE, fn () => view('filament.topbar.version', [
                'version' => config('plaashek.version'),
            ]))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetFarmLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /** @return list<string> */
    private static function moduleTopbarViews(): array
    {
        $modules = app(ModuleRegistry::class);
        $views = [];
        foreach ($modules->all() as $module) {
            if ($modules->isEnabled($module::code()) && ($view = $module::topbarView()) !== null) {
                $views[] = $view;
            }
        }

        return $views;
    }
}
