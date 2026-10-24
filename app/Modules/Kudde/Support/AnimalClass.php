<?php

namespace App\Modules\Kudde\Support;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Species;
use Illuminate\Support\Carbon;

/**
 * What kind of animal this is, in the words a farmer uses (old Kudde's classes.py, now for every species). The
 * herd is cows, heifers, bulls, steers and calves; a flock is ewes, rams, wethers and lambs. All of it follows
 * from what is recorded already plus the castrated flag, so it is derived here and can never disagree with the
 * record. One rule for every species; the species gives the age limit and the names (Species).
 *
 * - young (by birth date, under the species' "young until") it is a young female, young male, or young castrated;
 * - a male is a male, or castrated if castrated;
 * - a female is a mother once she has given birth, a female until then, however old;
 * - no birth date reads as grown: an animal entered without one is nearly always a bought-in adult.
 */
class AnimalClass
{
    public static function role(Animal $animal, bool $gaveBirth, Species $species, ?Carbon $today = null): string
    {
        $today ??= today();
        $young = $animal->birth_date !== null
            && (int) $animal->birth_date->diffInDays($today, false) < $species->young_until_days;

        if ($animal->sex === Animal::FEMALE) {
            return $young ? 'young_female' : ($gaveBirth ? 'mother' : 'female');
        }

        if ($animal->castrated) {
            return $young ? 'young_castrated' : 'castrated';
        }

        return $young ? 'young_male' : 'male';
    }

    /** The animal's class in its species' words, in the farm's language ("heifer calf", "ooilam"). */
    public static function of(Animal $animal, bool $gaveBirth, Species $species, ?Carbon $today = null): string
    {
        return $species->className(self::role($animal, $gaveBirth, $species, $today));
    }
}
