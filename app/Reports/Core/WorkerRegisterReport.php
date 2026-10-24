<?php

namespace App\Reports\Core;

use App\Models\Worker;
use App\Reports\Report;
use App\Reports\ReportFilters;
use App\Support\NaturalOrder;
use Filament\Forms\Components\Select;

/** The worker register as it stands: number, names, cell and whether they still work here. */
class WorkerRegisterReport extends Report
{
    public function key(): string
    {
        return 'workers.register';
    }

    public function label(): string
    {
        return __('plaashek.workers.report');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function filters(): array
    {
        return [Select::make('active')->label(__('plaashek.workers.active'))->default('1')
            ->options(['1' => __('plaashek.workers.active_only'), '0' => __('plaashek.workers.inactive_only')])
            ->placeholder(__('plaashek.workers.everyone'))];
    }

    public function columns(): array
    {
        return ['number' => __('plaashek.workers.number'), 'first_name' => __('plaashek.workers.first_name'),
            'last_name' => __('plaashek.workers.last_name'), 'preferred_name' => __('plaashek.workers.preferred_name'),
            'cell' => __('plaashek.workers.cell'), 'active' => __('plaashek.workers.active'), 'added' => __('plaashek.workers.added')];
    }

    public function rows(ReportFilters $filters): iterable
    {
        $active = $filters->get('active');
        $workers = Worker::query()->when($active !== null, fn ($q) => $q->where('active', $active === '1'))->get()
            ->sort(fn (Worker $a, Worker $b) => NaturalOrder::compare($a->number, $b->number));

        foreach ($workers as $w) {
            yield ['number' => $w->number, 'first_name' => $w->first_name, 'last_name' => $w->last_name,
                'preferred_name' => $w->preferred_name, 'cell' => $w->cell,
                'active' => $w->active ? __('plaashek.workers.yes') : __('plaashek.workers.no'), 'added' => $w->created_at->toDateString()];
        }
    }
}
