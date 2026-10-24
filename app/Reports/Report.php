<?php

namespace App\Reports;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;

/**
 * One report a farmer can pick on a module's screen and take away as Excel or PDF (owner's call, 25 October
 * 2026). A report says what it is called, which filters it takes beyond the standard date range, its columns and
 * its rows; picking, filtering and exporting are the same for every report (ReportsAction, ReportExport).
 *
 * Rows are plain values (strings, numbers, null), already in the farm's terms: the same row goes to a
 * spreadsheet someone will sort and sum, and to a PDF someone will print for the vet.
 */
abstract class Report
{
    /** Unique among all reports, e.g. "kudde.weighings". Used as the form key and the file name. */
    abstract public function key(): string;

    abstract public function label(): string;

    /** False for "as it stands today" reports (the herd register): no date range is asked for. */
    public function usesDateRange(): bool
    {
        return true;
    }

    /**
     * Filters of this report's own, as Filament form components named by their key (no state path: the action
     * puts them under the report's key).
     *
     * @return list<Component|Field>
     */
    public function filters(): array
    {
        return [];
    }

    /** @return array<string, string> column key => heading */
    abstract public function columns(): array;

    /**
     * @return iterable<array<string, string|int|float|null>> rows keyed as columns()
     */
    abstract public function rows(ReportFilters $filters): iterable;

    /** Wide reports print landscape. */
    public function landscape(): bool
    {
        return count($this->columns()) > 6;
    }
}
