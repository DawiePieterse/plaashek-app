<?php

namespace App\Modules\Kudde\Filament\Widgets;

use App\Modules\Kudde\Support\HerdHistory;
use App\Modules\Kudde\Support\HerdState;
use App\Modules\Kudde\Support\KuddeSettings;
use App\Reports\DateRanges;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Kudde's six numbers: the herd, this season's births and losses, the animals that must not go to the sale, the
 * ones overdue a weighing, and how fast the herd is growing.
 */
class KuddeStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 3];
    }

    protected function getStats(): array
    {
        $state = HerdState::load();
        $history = new HerdHistory;
        $months = $history->months(12);
        $season = DateRanges::seasonStart();
        $today = today();
        $alive = $state->alive();
        $cutoff = $today->copy()->subDays(KuddeSettings::weighEveryDays());
        $overdue = $alive->filter(fn ($a) => ! isset($state->weights[$a->id]) || $state->weights[$a->id][1]->lt($cutoff))->count();
        $withdrawal = $alive->filter(fn ($a) => isset($state->withdrawal[$a->id]))->count();
        $born = $history->count('born', $season, $today);
        $sold = $history->count('sold', $season, $today);
        $died = $history->count('died', $season, $today);
        $adg = HerdHistory::averageDailyGain($today->copy()->subYear(), $today);
        $locale = app()->getLocale();

        return [
            Stat::make(__('kudde::ui.stats.alive'), (string) $alive->count())
                ->description(__('kudde::ui.stats.alive_hint', ['in' => end($months)['born'] + end($months)['entered'], 'out' => end($months)['sold'] + end($months)['died']]))
                // Filament draws the sparkline from floats.
                ->chart(array_map(fn (int $head) => (float) $head, array_column($months, 'head')))->color('success'),
            Stat::make(__('kudde::ui.stats.born_season'), (string) $born)
                ->description(__('kudde::ui.stats.since', ['date' => $season->translatedFormat('j M')]))->color('success'),
            Stat::make(__('kudde::ui.stats.out_season'), (string) ($sold + $died))
                ->description(__('kudde::ui.stats.out_hint', ['sold' => $sold, 'died' => $died]))
                ->color($died > 0 ? 'warning' : 'gray'),
            Stat::make(__('kudde::ui.stats.withdrawal'), (string) $withdrawal)
                ->description(__('kudde::ui.stats.withdrawal_hint'))->color($withdrawal > 0 ? 'danger' : 'gray'),
            Stat::make(__('kudde::ui.stats.weighing'), (string) $overdue)
                ->description(__('kudde::ui.stats.weighing_hint', ['days' => KuddeSettings::weighEveryDays()]))
                ->color($overdue > 0 ? 'warning' : 'gray'),
            Stat::make(__('kudde::ui.stats.adg'), $adg === null ? '–' : Number::format($adg, precision: 2, locale: $locale).' kg')
                ->description(__('kudde::ui.stats.adg_hint')),
        ];
    }
}
