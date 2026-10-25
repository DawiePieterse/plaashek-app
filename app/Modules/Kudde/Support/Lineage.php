<?php

namespace App\Modules\Kudde\Support;

use App\Modules\Kudde\Models\Animal;
use App\Modules\Kudde\Models\Litter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An animal's family, for its page (PLAN.md section 2.2, lineage): three generations of parents, its offspring,
 * and for a mother her births with the interval between them, the number a farmer culls a cow on.
 *
 * Parents are recorded by tag, so they are found by tag within the animal's own species (sheep 001 is not cow
 * 001's mother). Tags are reused over the years, so where two animals have carried a parent's tag the one born
 * before the young one is taken, the latest such. A tag no record carries (a borrowed bull, a dam bought in
 * with the calf) still shows, as a name with nothing behind it. The stored links (`sire_id`, `dam_id`) come with
 * breeding (phase 7); this reads what is recorded today.
 */
class Lineage
{
    private const GENERATIONS = 3;

    /** @var array<string, list<Animal>> the species' animals by folded tag */
    private array $byTag = [];

    public function __construct(private readonly HerdState $state) {}

    /** @return array<string, mixed> */
    public function of(Animal $animal): array
    {
        $kin = $this->state->animals->where('species_id', $animal->species_id);
        $this->byTag = [];
        foreach ($kin as $a) {
            $this->byTag[mb_strtolower($a->tag)][] = $a;
        }

        $offspring = $this->offspring($animal, $kin);

        return [
            'tag' => $animal->tag,
            'species' => $this->state->speciesOf($animal)->code,
            'dam' => $this->parent($animal, 'dam_tag', self::GENERATIONS),
            'sire' => $this->parent($animal, 'sire_tag', self::GENERATIONS),
            'offspring' => $offspring->map(fn (Animal $a) => $this->brief($a))->values()->all(),
            'births' => $animal->sex === Animal::FEMALE ? $this->births($animal, $offspring) : [],
        ];
    }

    /**
     * A parent and, while generations remain, its own parents. Null when nothing is recorded.
     *
     * @return array<string, mixed>|null
     */
    private function parent(Animal $child, string $field, int $generations): ?array
    {
        $tag = $child->{$field};
        if ($tag === null || $tag === '') {
            return null;
        }

        $found = $this->find($tag, $child);
        $node = $found === null ? ['tag' => $tag, 'on_record' => false] : $this->brief($found) + ['on_record' => true];

        if ($found !== null && $generations > 1) {
            $node['dam'] = $this->parent($found, 'dam_tag', $generations - 1);
            $node['sire'] = $this->parent($found, 'sire_tag', $generations - 1);
        }

        return $node;
    }

    /** The animal that carried this tag when the child was born: born before it, the latest such. */
    private function find(string $tag, Animal $child): ?Animal
    {
        $candidates = collect($this->byTag[mb_strtolower($tag)] ?? [])->reject(fn (Animal $a) => $a->id === $child->id);
        if ($child->birth_date !== null) {
            $candidates = $candidates->filter(fn (Animal $a) => $a->birth_date === null || $a->birth_date->lt($child->birth_date));
        }

        return $candidates->sortBy(fn (Animal $a) => $a->birth_date?->toDateString() ?? '')->last();
    }

    /**
     * Young that name this animal as dam or sire, and whose own parent lookup lands back on it (so a reused tag's
     * young stay with the animal that really carried it), oldest first.
     *
     * @param  Collection<int, Animal>  $kin
     * @return Collection<int, Animal>
     */
    private function offspring(Animal $animal, Collection $kin): Collection
    {
        $field = $animal->sex === Animal::FEMALE ? 'dam_tag' : 'sire_tag';
        $tag = mb_strtolower($animal->tag);

        return $kin->filter(fn (Animal $a) => $a->{$field} !== null && mb_strtolower($a->{$field}) === $tag
            && $this->find($a->{$field}, $a)?->id === $animal->id)
            ->sortBy(fn (Animal $a) => [$a->birth_date?->toDateString() ?? '9999', $a->id])
            ->values();
    }

    /**
     * A mother's births, oldest first: her tagged young grouped by birth date, plus litters counted on her and not
     * yet (or never) tagged. Each with the days since the one before: the calving interval.
     *
     * @param  Collection<int, Animal>  $offspring
     * @return list<array<string, mixed>>
     */
    private function births(Animal $dam, Collection $offspring): array
    {
        $byDate = [];
        foreach ($offspring as $young) {
            if ($young->birth_date !== null) {
                $byDate[$young->birth_date->toDateString()]['young'][] = $young->tag;
            }
        }
        foreach (Litter::query()->where('dam_id', $dam->id)->get() as $litter) {
            $date = $litter->born_on->toDateString();
            $byDate[$date]['litter'] = ['born_alive' => $litter->born_alive, 'stillborn' => $litter->stillborn, 'weaned' => $litter->weaned];
        }
        ksort($byDate);

        $births = [];
        $previous = null;
        foreach ($byDate as $date => $birth) {
            $on = Carbon::parse($date);
            $births[] = [
                'date' => $date,
                'young' => $birth['young'] ?? [],
                'litter' => $birth['litter'] ?? null,
                'interval_days' => $previous === null ? null : (int) $previous->diffInDays($on),
            ];
            $previous = $on;
        }

        return $births;
    }

    /** @return array<string, mixed> */
    private function brief(Animal $a): array
    {
        [$kg] = $this->state->weights[$a->id] ?? [null];

        return [
            'tag' => $a->tag,
            'name' => $a->name,
            'sex' => $a->sex,
            'birth_date' => $a->birth_date?->toDateString(),
            'status' => $a->status,
            'class' => $this->state->classOf($a),
            'last_weight' => $kg,
        ];
    }
}
