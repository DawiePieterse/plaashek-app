<?php

namespace App\Modules\Kudde\Support;

use App\Models\Camp;
use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\AnimalEvent;
use App\Modules\Kudde\Models\Species;
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
    /**
     * @param  Collection<int, Animal>  $animals  every animal, whatever its status, in tag order
     * @param  array<int, string>  $locations  latest camp per animal id
     * @param  array<int, array{0: float, 1: Carbon}>  $weights  latest kg and date per animal id
     * @param  array<int, Carbon>  $withdrawal  last day still in withdrawal, per animal id
     * @param  array<int, true>  $calved  animal ids with a birth they gave on record
     * @param  Collection<int, Species>  $species  every species, keyed by id
     */
    private function __construct(
        public readonly Collection $animals,
        public readonly array $locations,
        public readonly array $weights,
        public readonly array $withdrawal,
        public readonly array $calved,
        public readonly Collection $species,
    ) {}

    public static function load(): self
    {
        $species = Species::query()->inFarmOrder()->get()->keyBy('id');
        $animals = Animal::query()->get()->sort(fn (Animal $a, Animal $b) => Tags::compare($a->tag, $b->tag))->values();

        return new self($animals, self::currentLocations(), self::lastWeights(), self::withdrawalUntil(),
            self::calvedIds($animals, $species), $species);
    }

    public function speciesOf(Animal $animal): Species
    {
        return $this->species[$animal->species_id];
    }

    /**
     * The species the screens and counts are about: the ones the farm keeps, and any other an alive animal
     * still belongs to (a species switched off with animals left), in the farm's order.
     *
     * @return Collection<int, Species>
     */
    public function speciesInPlay(): Collection
    {
        $withAnimals = $this->alive()->pluck('species_id')->unique()->all();

        return $this->species->filter(fn (Species $s) => $s->enabled || in_array($s->id, $withAnimals, true))->values();
    }

    /** True when the farm keeps more than one species, so a class needs its species named beside it. */
    public function mixed(): bool
    {
        return $this->speciesInPlay()->count() > 1;
    }

    public function roleOf(Animal $animal): string
    {
        return AnimalClass::role($animal, isset($this->calved[$animal->id]), $this->speciesOf($animal));
    }

    /**
     * What a herd count calls the animal's class: "Koei", or "Skape: ooi" on a farm with more than one species.
     * Counts and report columns are keyed by this, so two roles a species names alike (a sheep's ewe before and
     * after lambing) count together, as the farmer counts them.
     */
    public function classLabel(Animal $animal): string
    {
        return self::label($this->speciesOf($animal), $this->roleOf($animal), $this->mixed());
    }

    /**
     * Every class label the counts can have, in order (species, then role), so a summary card is stable.
     *
     * @return list<string>
     */
    public function classLabels(): array
    {
        $labels = [];
        foreach ($this->speciesInPlay() as $species) {
            foreach (Species::ROLES as $role) {
                $labels[] = self::label($species, $role, $this->mixed());
            }
        }

        return array_values(array_unique($labels));
    }

    private static function label(Species $species, string $role, bool $mixed): string
    {
        $name = mb_strtoupper(mb_substr($species->className($role), 0, 1)).mb_substr($species->className($role), 1);

        return $mixed ? $species->name().': '.mb_strtolower($name) : $name;
    }

    /** Overdue a weighing by its species' rule: never weighed, or not within "weigh every". */
    public function needsWeighing(Animal $animal): bool
    {
        if (! $animal->isAlive()) {
            return false;
        }
        $last = $this->weights[$animal->id][1] ?? null;

        return $last === null || $last->lt(today()->subDays($this->speciesOf($animal)->weighEveryDays()));
    }

    /** @return Collection<int, Animal> */
    public function alive(): Collection
    {
        return $this->animals->filter(fn (Animal $a) => $a->isAlive())->values();
    }

    /** The animal's class in its species' words, in the farm's language, lower case ("vers", "ewe lamb"). */
    public function classOf(Animal $animal): string
    {
        return AnimalClass::of($animal, isset($this->calved[$animal->id]), $this->speciesOf($animal));
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
            'class_role' => $this->roleOf($animal),
            'species' => $this->speciesOf($animal)->code,
            'weigh_every_days' => $this->speciesOf($animal)->weighEveryDays(),
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

    /**
     * The animal with this tag, of the species given, or a 404 the phones show as it is. Without a species the
     * tag must name one animal: sheep 001 and cow 001 may both exist (tags are unique per species), and then the
     * request must say which (409), rather than the herd guessing and recording on the wrong animal.
     */
    public static function animalByTag(string $tag, ?Species $species = null): Animal
    {
        $wanted = Tags::normalise($tag) ?? '';
        $found = Animal::query()->where('tag', $wanted)->when($species, fn ($q, $s) => $q->where('species_id', $s->id))->get();

        if ($found->count() > 1) {
            throw HerdException::ambiguousTag($wanted);
        }

        return $found->first() ?? throw HerdException::noAnimal($tag);
    }

    /**
     * The animals for a batch of tags, in the order given, all or nothing: a 404 naming the first unknown
     * tag, so a camp move or a group treatment never half-lands.
     *
     * @param  list<string>  $tags
     * @return list<Animal>
     */
    public static function animalsByTags(array $tags, ?Species $species = null): array
    {
        $wanted = array_map(fn ($t) => Tags::normalise((string) $t) ?? '', $tags);
        $found = Animal::query()->whereIn('tag', $wanted)->when($species, fn ($q, $s) => $q->where('species_id', $s->id))->get();

        $animals = [];
        foreach ($wanted as $i => $tag) {
            // Matched ignoring case, as the database's unique index does.
            $matches = $found->filter(fn (Animal $a) => mb_strtolower($a->tag) === mb_strtolower($tag));
            if ($matches->count() > 1) {
                throw HerdException::ambiguousTag($tag);
            }
            $animals[] = $matches->first() ?? throw HerdException::noAnimal((string) $tags[$i]);
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

    /**
     * Alive animals per camp, keyed by the folded name so a count finds its camp whatever was typed. What the
     * camp lists show, and why a camp that still holds animals is not archived.
     *
     * @return array<string, int>
     */
    public static function aliveByCamp(): array
    {
        $where = self::currentLocations();
        $counts = [];
        foreach (Animal::query()->where('status', Animal::ALIVE)->pluck('id') as $id) {
            if (($where[$id] ?? '') !== '') {
                $key = CampNames::fold($where[$id]);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
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
     * @param  Collection<int, Species>  $species  keyed by id
     * @return array<int, true>
     */
    private static function calvedIds(Collection $animals, Collection $species): array
    {
        // A dam is looked for in the young one's own species: a ewe's lamb names a ewe, not a cow tagged alike.
        $byTag = $animals->keyBy(fn (Animal $a) => $a->species_id.'|'.mb_strtolower($a->tag));
        $calved = [];

        foreach ($animals as $animal) {
            $dam = $animal->dam_tag !== null ? $byTag->get($animal->species_id.'|'.mb_strtolower($animal->dam_tag)) : null;
            if ($dam !== null) {
                $calved[$dam->id] = true;
            }
        }

        // A birth event on her dated more than one gestation after her own birth is a birth she gave: nothing
        // gives birth sooner. One dated at her own birth is her own.
        $births = $animals->keyBy('id');
        AnimalEvent::query()->where('kind', AnimalEvent::BIRTH)->get(['animal_id', 'event_date'])
            ->each(function (AnimalEvent $e) use (&$calved, $births, $species) {
                $animal = $births->get($e->animal_id);
                $own = $animal?->birth_date;
                $gestation = $animal !== null ? ($species[$animal->species_id]->gestation_days ?? 283) : 283;
                if ($own !== null && $e->event_date->gt($own->copy()->addDays($gestation))) {
                    $calved[$e->animal_id] = true;
                }
            });

        return $calved;
    }
}
