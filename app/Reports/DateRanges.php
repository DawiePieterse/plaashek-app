<?php

namespace App\Reports;

use App\Support\Farm;
use Illuminate\Support\Carbon;

/**
 * The standard date ranges every report offers, so "this season" means the same thing on every screen: from the
 * first of the farm's season start month (Weather settings), as the rain record counts it.
 */
final class DateRanges
{
    public const PRESETS = ['this_month', 'last_month', 'this_season', 'last_season', 'this_year', 'last_12_months', 'custom'];

    /** @return array<string, string> preset => label in the farm's language */
    public static function options(): array
    {
        $options = [];
        foreach (self::PRESETS as $preset) {
            $options[$preset] = __('plaashek.reports.ranges.'.$preset);
        }

        return $options;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function resolve(string $preset, ?string $from = null, ?string $to = null): array
    {
        $today = today();
        $seasonStart = self::seasonStart($today);

        return match ($preset) {
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_season' => [$seasonStart, $today->copy()],
            'last_season' => [$seasonStart->copy()->subYear(), $seasonStart->copy()->subDay()],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()],
            'last_12_months' => [$today->copy()->subYear()->addDay(), $today->copy()],
            default => [
                $from ? Carbon::parse($from)->startOfDay() : $today->copy()->subYear()->addDay(),
                $to ? Carbon::parse($to)->startOfDay() : $today->copy(),
            ],
        };
    }

    public static function seasonStart(?Carbon $on = null): Carbon
    {
        $on ??= today();
        $start = $on->copy()->setDate($on->year, app(Farm::class)->seasonStartMonth(), 1)->startOfDay();

        return $start->gt($on) ? $start->subYear() : $start;
    }
}
