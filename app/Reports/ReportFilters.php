<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * What the farmer chose: the date range (both ends included) and the report's own filters by key.
 */
final class ReportFilters
{
    /** @param  array<string, mixed>  $values */
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly array $values = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' || $value === [] ? $default : $value;
    }

    /** "1 Jul 2026 – 25 Oct 2026", or null when the report has no range. */
    public function rangeLabel(): ?string
    {
        if ($this->from === null && $this->to === null) {
            return null;
        }

        return trim(($this->from?->translatedFormat('j M Y') ?? '…').' – '.($this->to?->translatedFormat('j M Y') ?? '…'));
    }
}
