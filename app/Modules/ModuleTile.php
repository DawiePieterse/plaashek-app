<?php

namespace App\Modules;

/**
 * A module's tile on the office Home page: what it is, one number that says how it is doing, and where a tap
 * goes. One number on purpose: the Home page is read on a phone between other jobs, and a tile with a table
 * on it is a tile nobody reads.
 */
final class ModuleTile
{
    public function __construct(
        public readonly string $label,
        public readonly string $icon,
        public readonly string $value,
        public readonly string $caption,
        public readonly ?string $url = null,
        public readonly int $sort = 50,
    ) {}
}
