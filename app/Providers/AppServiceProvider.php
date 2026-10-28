<?php

namespace App\Providers;

use App\Console\Commands\FarmCreate;
use App\Console\Commands\FarmLicence;
use App\Console\Commands\FarmModules;
use App\Console\Commands\FarmStaffPassword;
use App\Modules\ModuleRegistry;
use App\Support\Farm;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->singleton(Farm::class);
        $this->app->singleton(ModuleRegistry::class);

        // Every module this build has registers itself, on or off for this farm (PLAN.md section 4.1).
        foreach ((array) config('plaashek.modules', []) as $module) {
            $this->app->register($module);
        }
    }

    public function boot(): void
    {
        // Outside production, fail on lazy loading (catches one-query-per-row pages) and on unknown attributes.
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands($this->app->isProduction());

        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => Password::min(8));

        // Pairing codes are eight characters from 31, open ten minutes: this keeps guessing them out of reach.
        // Card scans: numbers are short, and this is what stops someone trying them all.
        RateLimiter::for('field-sign-in', fn (Request $request) => Limit::perMinute(20)->by('field-sign-in|'.$request->ip()));
        RateLimiter::for('field-pair', fn (Request $request) => Limit::perMinute(10)->by('field-pair|'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([FarmCreate::class, FarmLicence::class, FarmModules::class, FarmStaffPassword::class]);
        }
    }
}
