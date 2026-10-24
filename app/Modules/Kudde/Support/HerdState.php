<?php

namespace App\Modules\Kudde\Support;

use App\Models\Camp;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Support\CampNames;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What the herd looks like right now, derived from the records (old Kudde's herd.py).
 *
 * Kudde stores an animal's identity and its events, nothing else: where an animal is, what it last weighed,
 * whether it is still in withdrawal and whether a cow has calved are read off the history every time. This
 * is the one place those derivations live, so the herd list, the dashboard, the export and the camp pickers
 * all get the same answer from the same code. Built once per request with load(), a handful of queries for
 * the whole herd rather than a few per animal.
 */
class HerdState
{
    /** A birth event this long after her own birth is a calving, not her birth. Nothing calves at ten months. */
    private const OWN_BIRTH_WINDOW_DAYS = 300;

    /**
     * @param  Collection<int, Animal>  $animals  every animal, whatever its status, in tag order
     * @param  array<int, string>  $locations  latest camp per animal id
     * @param  array<int, array{0: float, 1: Carbon}>  $weights  latest kg and date per animal id
     * @param  array<int, Carbon>  $withdrawal  last day still in withdrawal, per animal id
     * @param  array<int, true>  $calved  animal ids with a calving on record
     */
    private function __construct(
        public readonly Collection $animals,
        public readonly array $locations,
        public readonly array $weights,
        public readonly array $withdrawal,
        public readonly array $calved,
    ) {}

    public static function load(): self
    {
        $animals = Animal::query()->get()->sort(fn (Animal $a, Animal $b) => Tags::compare($a->tag, $b->tag))->values();

        return new self($animals, self::currentLocations(), self::lastWeights(), self::withdrawalUntil(), self::calvedIds($animals));
    }

    /** @return Collection<int, Animal> */
    public function alive(): Collection
    {
        return $this->animals->filter(fn (Animal $a) => $a->isAlive())->values();
    }

    public function classOf(Animal $animal): string
    {
        return AnimalClass::of($animal, isset($this->calved[$animal->id]));
    }

    /**
     * An animal as the apps list it: the record, plus where it is, its last weighing, its withdrawal and its
     * class. Carried on the list rather than fetched per animal because the phone caches this list and that
     * is the only copy it has at the crush: the last weight is what lets a 412 typed as 41 be noticed before
     * it is saved, and the camp is what the farmer wants to see while walking.
     *
     * @return array<string, mixed>
     */
    public function describe(Animal $animal): array
    {
        [$kg, $on] = $this->weights[$animal->id] ?? [null, null];

        return $animal->toArray() + [
            'camp' => $this->locations[$animal->id] ?? null,
            'last_weight' => $kg,
            'last_weight_date' => $on?->toDateString(),
            'withdrawal_until' => isset($this->withdrawal[$animal->id]) ? $this->withdrawal[$animal->id]->toDateString() : null,
            'class' => $this->classOf($animal),
        ];
    }

    /**
     * The alive herd camp by camp, as both the Move picker and the dashboard show it: every active camp in
     * farm order (empty ones too, so the farmer sees the whole farm), then any camp only the history knows
     * (older records), then null for the animals with no camp at all, so they can be moved INTO one.
     *
     * @return list<array{0: string|null, 1: list<Animal>}>
     */
    public function campGroups(): array
    {
        $names = [];
        foreach (Camp::query()->inFarmOrder()->where('archived', false)->get() as $camp) {
            $names[CampNames::fold($camp->name)] ??= $camp->name;
        }
        $managed = array_keys($names);
        $members = array_fill_keys($managed, []);
        $unplaced = [];

        foreach ($this->alive() as $animal) {
            $location = $this->locations[$animal->id] ?? '';
            if ($location === '') {
                $unplaced[] = $animal;

                continue;
            }
            $key = CampNames::fold($location);
            $names[$key] ??= CampNames::normalise($location);
            $members[$key][] = $animal;
        }

        $stray = array_values(array_diff(array_keys($members), $managed));
        sort($stray);

        $groups = [];
        foreach ([...$managed, ...$stray] as $key) {
            $groups[] = [$names[$key], $members[$key]];
        }
        if ($unplaced !== []) {
            $groups[] = [null, $unplaced];
        }

        return $groups;
    }

    /** The animal with this tag, or a 404 the phones show as it is. */
    public static function animalByTag(string $tag): Animal
    {
        $wanted = Tags::normalise($tag) ?? '';

        return Animal::query()->where('tag', $wanted)->first() ?? throw HerdException::noAnimal($tag);
    }

    /**
     * The animals for a batch of tags, in the order given, all or nothing: a 404 naming the first unknown
     * tag, so a camp move or a group treatment never half-lands.
     *
     * @param  list<string>  $tags
     * @return list<Animal>
     */
    public static function animalsByTags(array $tags): array
    {
        $wanted = array_map(fn ($t) => Tags::normalise((string) $t) ?? '', $tags);
        $found = Animal::query()->whereIn('tag', $wanted)->get();

        $animals = [];
        foreach ($wanted as $i => $tag) {
            // Matched ignoring case, as the database's unique index does.
            $animal = $found->first(fn (Animal $a) => mb_strtolower($a->tag) === mb_strtolower($tag));
            $animals[] = $animal ?? throw HerdException::noAnimal((string) $tags[$i]);
        }

        return $animals;
    }

    /**
     * Latest camp per animal, from the movement and birth events that recorded one. There is no stored
     * current-camp field.
     *
     * @return array<int, string>
     */
    public static function currentLocations(): array
    {
        $latest = [];
        AnimalEvent::query()
            ->whereIn('kind', AnimalEvent::PLACING_KINDS)
            ->where('location', '!=', '')
            ->orderByDesc('event_date')->orderByDesc('id')
            ->get(['animal_id', 'location'])
            ->each(function (AnimalEvent $e) use (&$latest) {
                $latest[$e->animal_id] ??= $e->location;
            });

        return $latest;
    }

    /** @return array<int, array{0: float, 1: Carbon}> */
    private static function lastWeights(): array
    {
        $latest = [];
        AnimalEvent::query()
            ->where('kind', AnimalEvent::WEIGHT)
            ->orderByDesc('event_date')->orderByDesc('id')
            ->get(['animal_id', 'value', 'event_date'])
            ->each(function (AnimalEvent $e) use (&$latest) {
                $latest[$e->animal_id] ??= [(float) $e->value, $e->event_date];
            });

        return $latest;
    }

    /**
     * The last day each animal is still inside a treatment's withdrawal, for those that are. Overlapping
     * treatments: the later-ending one decides when the animal is clear.
     *
     * @return array<int, Carbon>
     */
    private static function withdrawalUntil(): array
    {
        $today = today();
        $until = [];
        AnimalEvent::query()
            ->where('kind', AnimalEvent::TREATMENT)
            ->whereNotNull('withdrawal_days')
            ->get(['animal_id', 'event_date', 'withdrawal_days'])
            ->each(function (AnimalEvent $e) use (&$until, $today) {
                $end = $e->event_date->copy()->addDays((int) $e->withdrawal_days);
                if ($end->gte($today) && (! isset($until[$e->animal_id]) || $end->gt($until[$e->animal_id]))) {
                    $until[$e->animal_id] = $end;
                }
            });

        return $until;
    }

    /**
     * The animals with a calving on record, by either sign of one: a calf with her as dam (the calving
     * writes that), or a birth event on her dated well after her own birth (a birth event at her own birth
     * date is her own).
     *
     * @param  Collection<int, Animal>  $animals  every animal, every status: a dam may be dead or sold
     * @return array<int, true>
     */
    private static function calvedIds(Collection $animals): array
    {
        $byTag = $animals->keyBy(fn (Animal $a) => mb_strtolower($a->tag));
        $calved = [];

        foreach ($animals as $animal) {
            $dam = $animal->dam_tag !== null ? $byTag->get(mb_strtolower($animal->dam_tag)) : null;
            if ($dam !== null) {
                $calved[$dam->id] = true;
            }
        }

        $births = $animals->keyBy('id');
        AnimalEvent::query()->where('kind', AnimalEvent::BIRTH)->get(['animal_id', 'event_date'])
            ->each(function (AnimalEvent $e) use (&$calved, $births) {
                $own = $births->get($e->animal_id)?->birth_date;
                if ($own !== null && $e->event_date->gt($own->copy()->addDays(self::OWN_BIRTH_WINDOW_DAYS))) {
                    $calved[$e->animal_id] = true;
                }
            });

        return $calved;
    }
}
