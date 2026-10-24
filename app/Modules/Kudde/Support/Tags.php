<?php

namespace App\Modules\Kudde\Support;

use App\Support\NaturalOrder;

/**
 * Ear-tag helpers (old Kudde's tags.py).
 */
class Tags
{
    /**
     * A tag as it is stored: trimmed, or null if nothing is left. The phones trim before sending, but an
     * outbox replaying a capture from an older build may not, and without this " A1 " lands beside "A1" as
     * a second animal identical on every screen, and "" as an animal nothing can refer to.
     */
    public static function normalise(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Compares tags the way a farmer reads them: B2 before B10, A20 before A100 (App\Support\NaturalOrder).
     * The phones' list (compareTags in the field app) must agree.
     */
    public static function compare(?string $a, ?string $b): int
    {
        return NaturalOrder::compare($a, $b);
    }
}
