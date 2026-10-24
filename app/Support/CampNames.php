<?php

namespace App\Support;

use App\Models\Camp;

/**
 * How a typed location becomes a camp (old Kudde's campnames.py). A record's location stays plain text, so
 * every record already captured, and every one replayed from a phone's outbox by an older build, keeps
 * working. The rules that keep the two in step:
 *
 * - names are compared ignoring case, in PHP rather than SQL, because database case folding mishandles
 *   Afrikaans diacritics (Boord Notes learnt this first);
 * - a typed location snaps to the spelling the office holds;
 * - a location nobody set up is added as it is used, because a worker at a gate must never be blocked by a
 *   camp the office has not got round to. The office tidies it later.
 */
class CampNames
{
    public static function normalise(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /** The comparison form of a camp name. */
    public static function fold(?string $name): string
    {
        return mb_convert_case(self::normalise($name), MB_CASE_FOLD, 'UTF-8');
    }

    /** The camp called $name, whatever its capitals, archived or not. */
    public static function find(?string $name): ?Camp
    {
        $wanted = self::fold($name);

        if ($wanted === '') {
            return null;
        }

        return Camp::query()->get()->first(fn (Camp $camp) => self::fold($camp->name) === $wanted);
    }

    /**
     * The camp a typed location refers to, created if it is new and restored if it had been archived: an
     * animal was just put there, so it is plainly in use again. Callers wrap this in the same transaction as
     * the record that named it, so the two land together or not at all.
     */
    public static function ensure(string $name): Camp
    {
        $camp = self::find($name);

        if ($camp === null) {
            return Camp::query()->create([
                'name' => self::normalise($name),
                'sort_order' => ((int) Camp::query()->max('sort_order')) + (Camp::query()->exists() ? 1 : 0),
            ]);
        }

        if ($camp->archived) {
            $camp->update(['archived' => false]);
        }

        return $camp;
    }
}
