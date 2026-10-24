<?php

namespace App\Modules\Kudde\Support;

use App\Support\Settings;

/**
 * Kudde's own settings, typed, with their defaults. Edited on Kudde's settings screen (Filament\KuddeSettingsPage).
 */
class KuddeSettings
{
    public const WEIGH_EVERY_DAYS = 'kudde.weigh_every_days';

    /**
     * Past this many days without a weighing, an alive animal needs one. Six months by default: a farmer weighing
     * every muster or two isn't nagged, and an animal that fell through the cracks is still caught.
     */
    public const DEFAULT_WEIGH_EVERY_DAYS = 182;

    public static function weighEveryDays(): int
    {
        $days = (int) app(Settings::class)->get(self::WEIGH_EVERY_DAYS, (string) self::DEFAULT_WEIGH_EVERY_DAYS);

        return $days >= 7 && $days <= 730 ? $days : self::DEFAULT_WEIGH_EVERY_DAYS;
    }
}
