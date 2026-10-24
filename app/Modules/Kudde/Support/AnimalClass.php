<?php

namespace App\Modules\Kudde\Support;

use App\Modules\Kudde\Models\Animal;
use Illuminate\Support\Carbon;

/**
 * What kind of animal this is, in the words a farmer uses (old Kudde's classes.py). The herd is cows,
 * heifers, bulls, steers and calves, and all of that follows from what is already recorded plus the
 * castrated flag, so it is derived here and can never disagree with the record.
 *
 * - under a year old (by birth date) it is a calf: heifer calf, bull calf, or steer calf once castrated;
 * - a male is a bull, or a steer if castrated;
 * - a female is a cow once she has calved, a heifer until then, however old;
 * - no birth date reads as grown: an animal entered without one is nearly always a bought-in adult.
 */
class AnimalClass
{
    public const CALF_UNTIL_DAYS = 365;

    /** Every class, in the order a herd summary lists them. */
    public const CLASSES = ['cow', 'heifer', 'bull', 'steer', 'heifer calf', 'bull calf', 'steer calf'];

    /** The class as the farm's screens and reports say it ("Vers", "Heifer calf"); the key stays English. */
    public static function label(string $class): string
    {
        return __('kudde::ui.class.'.str_replace(' ', '_', $class));
    }

    public static function of(Animal $animal, bool $calved, ?Carbon $today = null): string
    {
        $today ??= today();
        $isCalf = $animal->birth_date !== null
            && (int) $animal->birth_date->diffInDays($today, false) < self::CALF_UNTIL_DAYS;

        if ($animal->sex === Animal::FEMALE) {
            return $isCalf ? 'heifer calf' : ($calved ? 'cow' : 'heifer');
        }

        $base = $animal->castrated ? 'steer' : 'bull';

        return $isCalf ? $base.' calf' : $base;
    }
}
