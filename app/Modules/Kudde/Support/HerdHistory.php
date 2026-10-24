<?php

namespace App\Modules\Kudde\Support;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The herd over time, for Kudde's screen: how many head there were at each month's end, what came in (born,
 * entered) and what went out (sold, died), and how fast animals are growing. Derived from the events like
 * everything else in Kudde (HerdState): nothing here is stored, so it can never disagree with the records.
 *
 * An animal is counted from its first record (the birth or the day it was entered) until its sale or death.
 */
class HerdHistory
{
    /** @var array<int, array{in: Carbon, out: Carbon|null, out_kind: string|null, in_kind: string}> */
    private array $spans = [];

    public function __construct()
    {
        $events = AnimalEvent::query()->orderBy('event_date')->orderBy('id')
            ->get(['animal_id', 'kind', 'event_date']);
        $first = [];
        $out = [];
        foreach ($events as $e) {
            $first[$e->animal_id] ??= $e;
            if (in_array($e->kind, [AnimalEvent::SALE, AnimalEvent::DEATH], true)) {
                $out[$e->animal_id] ??= $e;
            }
        }

        foreach (Animal::query()->get(['id', 'created_at']) as $animal) {
            $f = $first[$animal->id] ?? null;
            $in = $f !== null ? $f->event_date->copy() : $animal->created_at->copy()->startOfDay();
            if ($f !== null && $animal->created_at->copy()->startOfDay()->lt($in)) {
                $in = $animal->created_at->copy()->startOfDay();
            }
            $this->spans[$animal->id] = [
                'in' => $in,
                'in_kind' => $f !== null && $f->kind === AnimalEvent::BIRTH ? 'born' : 'entered',
                'out' => isset($out[$animal->id]) ? $out[$animal->id]->event_date->copy() : null,
                'out_kind' => isset($out[$animal->id]) ? $out[$animal->id]->kind : null,
            ];
        }
    }

    /**
     * The last $months months, oldest first: each month's label, head count at its end (today for this month),
     * and what came in and went out in it.
     *
     * @return list<array{month: Carbon, head: int, born: int, entered: int, sold: int, died: int}>
     */
    public function months(int $months = 12): array
    {
        $rows = [];
        $start = today()->startOfMonth()->subMonths($months - 1);

        for ($i = 0; $i < $months; $i++) {
            $from = $start->copy()->addMonths($i);
            $end = $from->copy()->endOfMonth()->startOfDay();
            $at = $end->gt(today()) ? today() : $end;
            $row = ['month' => $from, 'head' => 0, 'born' => 0, 'entered' => 0, 'sold' => 0, 'died' => 0];

            foreach ($this->spans as $s) {
                if ($s['in']->lte($at) && ($s['out'] === null || $s['out']->gt($at))) {
                    $row['head']++;
                }
                if ($s['in']->betweenIncluded($from, $end)) {
                    $row[$s['in_kind']]++;
                }
                if ($s['out'] !== null && $s['out']->betweenIncluded($from, $end)) {
                    $row[$s['out_kind'] === AnimalEvent::SALE ? 'sold' : 'died']++;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** How many came in or went out between two dates, by kind ("born", "entered", "sold", "died"). */
    public function count(string $kind, Carbon $from, Carbon $to): int
    {
        $n = 0;
        foreach ($this->spans as $s) {
            if (in_array($kind, ['born', 'entered'], true)) {
                $n += $s['in_kind'] === $kind && $s['in']->betweenIncluded($from, $to) ? 1 : 0;
            } else {
                $n += $s['out'] !== null && ($s['out_kind'] === AnimalEvent::SALE) === ($kind === 'sold')
                    && $s['out']->betweenIncluded($from, $to) ? 1 : 0;
            }
        }

        return $n;
    }

    /**
     * Average daily gain in kg: for every alive animal weighed at least twice in the period, the gain between its
     * last two weighings there divided by the days between them, averaged over those animals. The last two,
     * because a farm weighs every few months and the latest stretch is what tells how the animal is doing now.
     * Null when no animal was weighed twice.
     */
    public static function averageDailyGain(Carbon $from, Carbon $to): ?float
    {
        /** @var Collection<int, Collection<int, AnimalEvent>> $byAnimal */
        $byAnimal = AnimalEvent::query()->where('kind', AnimalEvent::WEIGHT)
            ->whereBetween('event_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('animal_id', Animal::query()->where('status', Animal::ALIVE)->select('id'))
            ->orderBy('event_date')->orderBy('id')
            ->get(['animal_id', 'event_date', 'value'])
            ->groupBy('animal_id');

        $gains = [];
        foreach ($byAnimal as $weighings) {
            if ($weighings->count() < 2) {
                continue;
            }
            [$before, $last] = $weighings->slice(-2)->values()->all();
            $days = (int) $before->event_date->diffInDays($last->event_date);
            if ($days > 0) {
                $gains[] = ((float) $last->value - (float) $before->value) / $days;
            }
        }

        return $gains === [] ? null : array_sum($gains) / count($gains);
    }

    /**
     * Average weight of the animals weighed in each of the last $months months, oldest first; null for a month
     * with no weighing.
     *
     * @return list<array{month: Carbon, kg: float|null, weighed: int}>
     */
    public static function averageWeights(int $months = 12): array
    {
        $start = today()->startOfMonth()->subMonths($months - 1);
        $byMonth = AnimalEvent::query()->where('kind', AnimalEvent::WEIGHT)
            ->where('event_date', '>=', $start->toDateString())
            ->get(['event_date', 'value'])
            ->groupBy(fn (AnimalEvent $e) => $e->event_date->format('Y-m'));

        $rows = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $w = $byMonth->get($month->format('Y-m'));
            $rows[] = ['month' => $month, 'kg' => $w ? round((float) $w->avg('value'), 1) : null, 'weighed' => $w ? $w->count() : 0];
        }

        return $rows;
    }
}
