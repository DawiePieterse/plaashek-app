<?php

namespace App\Modules\Kudde\Reports;

use App\Modules\Kudde\Models\AnimalEvent;
use App\Reports\ReportFilters;
use Filament\Forms\Components\TextInput;

/**
 * The medicine register: every treatment in the period with its product, dose and the day the animal is clear
 * of withdrawal. What the vet, a buyer or an audit asks for.
 */
class TreatmentsReport extends KuddeReport
{
    protected function name(): string
    {
        return 'treatments';
    }

    public function filters(): array
    {
        return [TextInput::make('product')->label(__('kudde::ui.columns.product'))];
    }

    public function columns(): array
    {
        return self::headings(['date', 'tag', 'name', 'product', 'dose', 'withdrawal_days', 'clear_on', 'note', 'by']);
    }

    public function rows(ReportFilters $filters): iterable
    {
        $product = mb_strtolower(trim((string) $filters->get('product', '')));
        foreach (self::events($filters, AnimalEvent::TREATMENT)->lazy() as $e) {
            if ($product !== '' && ! str_contains(mb_strtolower($e->product), $product)) {
                continue;
            }
            yield [
                'date' => $e->event_date->toDateString(), 'tag' => $e->animal?->tag, 'name' => $e->animal?->name,
                'product' => $e->product, 'dose' => $e->dose, 'withdrawal_days' => $e->withdrawal_days,
                'clear_on' => $e->withdrawal_days !== null ? $e->event_date->copy()->addDays($e->withdrawal_days)->toDateString() : null,
                'note' => $e->note, 'by' => self::who($e),
            ];
        }
    }
}
