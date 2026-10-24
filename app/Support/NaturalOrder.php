<?php

namespace App\Support;

/**
 * Order for the codes people paint on animals and print on cards (ear tags, worker numbers).
 */
class NaturalOrder
{
    /**
     * Compares the way a farmer reads them: B2 before B10, A20 before A100. A database orders text character
     * by character, which put B10 between B1 and B2 on every screen of any herd numbered past nine. Digit runs
     * compare as numbers, letter runs ignoring case, and a number sorts before letters at the same position.
     */
    public static function compare(?string $a, ?string $b): int
    {
        $left = self::parts((string) $a);
        $right = self::parts((string) $b);

        for ($i = 0, $n = min(count($left), count($right)); $i < $n; $i++) {
            [$lk, $lv] = $left[$i];
            [$rk, $rv] = $right[$i];

            if ($lk !== $rk) {
                return $lk <=> $rk;
            }

            $c = $lk === 0 ? ((int) $lv <=> (int) $rv) : strcmp((string) $lv, (string) $rv);
            if ($c !== 0) {
                return $c;
            }
        }

        return count($left) <=> count($right);
    }

    /** @return list<array{0: int, 1: string}> */
    private static function parts(string $tag): array
    {
        $parts = [];

        foreach (preg_split('/(\d+)/', $tag, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $parts[] = ctype_digit($part) ? [0, $part] : [1, mb_strtolower($part)];
        }

        return $parts;
    }
}
