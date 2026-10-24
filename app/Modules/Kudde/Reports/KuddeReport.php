<?php

namespace App\Modules\Kudde\Reports;

use App\Models\Camp;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Models\Species;
use App\Modules\Kudde\Support\HerdState;
use App\Reports\Report;
use App\Reports\ReportFilters;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

/** What Kudde's reports share: their headings' translations and the event query with its date range. */
abstract class KuddeReport extends Report
{
    public function label(): string
    {
        return __('kudde::ui.reports.'.$this->name());
    }

    public function key(): string
    {
        return 'kudde.'.$this->name();
    }

    abstract protected function name(): string;

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    protected static function headings(array $keys): array
    {
        $columns = [];
        foreach ($keys as $key) {
            $columns[$key] = __('kudde::ui.columns.'.$key);
        }

        return $columns;
    }

    /** @return Builder<AnimalEvent> */
    protected static function events(ReportFilters $filters, ?string $kind = null): Builder
    {
        return AnimalEvent::query()->with(['animal.species', 'worker', 'user'])
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($filters->from, fn ($q, $from) => $q->where('event_date', '>=', $from->toDateString()))
            ->when($filters->to, fn ($q, $to) => $q->where('event_date', '<=', $to->toDateString()))
            ->orderBy('event_date')->orderBy('id');
    }

    protected static function campFilter(): Select
    {
        return Select::make('camp')->label(__('kudde::ui.columns.camp'))->placeholder(__('kudde::ui.reports.all'))
            ->options(Camp::query()->inFarmOrder()->pluck('name', 'name')->all());
    }

    protected static function classFilter(): Select
    {
        $labels = HerdState::load()->classLabels();

        return Select::make('class')->label(__('kudde::ui.columns.class'))->placeholder(__('kudde::ui.reports.all'))
            ->options(array_combine($labels, $labels));
    }

    /** Only offered on a farm with more than one species. */
    protected static function speciesFilter(): Select
    {
        return Select::make('species')->label(__('kudde::ui.columns.species'))->placeholder(__('kudde::ui.reports.all'))
            ->options(Species::enabled()->mapWithKeys(fn (Species $s) => [$s->code => $s->name()])->all())
            ->visible(fn () => Species::enabled()->count() > 1);
    }

    protected static function who(AnimalEvent $e): ?string
    {
        return $e->worker?->displayName() ?? $e->user?->name;
    }
}
