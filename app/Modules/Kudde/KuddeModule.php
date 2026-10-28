<?php

namespace App\Modules\Kudde;

use App\Modules\Kudde\Console\StampWeather;
use App\Modules\Kudde\Filament\KuddePage;
use App\Modules\Kudde\Filament\KuddeSettingsPage;
use App\Modules\Kudde\Filament\Resources\Animals\AnimalResource;
use App\Modules\Kudde\Filament\Resources\Camps\CampResource;
use App\Modules\Kudde\Filament\Resources\Species\SpeciesResource;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Reports\BirthsReport;
use App\Modules\Kudde\Reports\EventsReport;
use App\Modules\Kudde\Reports\ExitsReport;
use App\Modules\Kudde\Reports\HeadCountReport;
use App\Modules\Kudde\Reports\HerdRegister;
use App\Modules\Kudde\Reports\TreatmentsReport;
use App\Modules\Kudde\Reports\WeighingsReport;
use App\Modules\Kudde\Support\Herd;
use App\Modules\Module;
use App\Modules\ModuleTile;
use App\Reports\Report;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Kudde (PLAN.md section 2.2), the first paid module: cattle recording for small farms, the animals and the
 * handful of things that happen to them. The behaviour is the farm-PC Kudde's (DawiePieterse/Kudde),
 * rebuilt here: identity and events stored, everything else derived in one place (Support\HerdState).
 */
class KuddeModule extends Module
{
    public static function code(): string
    {
        return 'kudde';
    }

    public static function nameKey(): string
    {
        return 'plaashek.modules.kudde';
    }

    public static function filamentPages(): array
    {
        return [KuddePage::class, KuddeSettingsPage::class];
    }

    /** Alive animals: the number a farmer keeps in their head and checks first. */
    /** @return list<class-string<\Filament\Resources\Resource>> */
    public static function filamentResources(): array
    {
        return [AnimalResource::class, CampResource::class, SpeciesResource::class];
    }

    public static function fieldUrl(): ?string
    {
        return route('kudde.field', absolute: false);
    }

    public static function homeTile(): ?ModuleTile
    {
        $alive = Animal::query()->where('status', Animal::ALIVE)->count();

        return new ModuleTile(
            label: 'Kudde',
            icon: '🐄',
            value: (string) $alive,
            caption: trans_choice('kudde::ui.tile_alive', $alive),
            url: KuddePage::getUrl(),
            sort: 20,
        );
    }

    /**
     * The reports on Kudde's screen, in the order the picker lists them.
     *
     * @return list<Report>
     */
    public static function reports(): array
    {
        return [new HerdRegister, new HeadCountReport, new EventsReport, new WeighingsReport, new TreatmentsReport,
            new BirthsReport, new ExitsReport];
    }

    public function register(): void
    {
        $this->app->singleton(Herd::class);
    }

    protected function bootModule(): void
    {
        $this->loadTranslationsFrom($this->modulePath('lang'), 'kudde');
        $this->loadViewsFrom($this->modulePath('views'), 'kudde');

        if ($this->app->runningInConsole()) {
            $this->commands([StampWeather::class]);
        }

        // After weer:days (05:10) has stored yesterday, the events captured before their day was in
        // weather_days get their weather.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('kudde:weather')->dailyAt('05:25')->withoutOverlapping();
        });
    }
}
