<?php

namespace App\Modules\Kudde\Models;

use App\Modules\Kudde\Support\KuddeSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of livestock the farm may keep (PLAN.md section 2.2, phase 4b), with its own rules, all set in Kudde's
 * settings: the names of its classes in each language, how long its young count as young, its gestation, how
 * often to weigh, the word for a birth, and whether its young are tagged only at weaning.
 *
 * The class logic is one rule for every species (sex, young, has given birth, castrated: AnimalClass); a species
 * only names the resulting roles. Seeded with cattle (on), sheep, goats and pigs (off); a farm switches on what
 * it keeps and may add its own.
 *
 * @property int $id
 * @property string $code
 * @property string $name_en
 * @property string $name_af
 * @property bool $enabled
 * @property int $sort_order
 * @property array<string, array<string, string>> $class_names language => role => name
 * @property int $young_until_days
 * @property int $gestation_days
 * @property int|null $weigh_every_days null: Kudde's own setting
 * @property array<string, string> $birth_word language => word
 * @property bool $young_tagged_at_weaning
 */
class Species extends Model
{
    /** The class roles, in the order a herd summary lists them. */
    public const ROLES = ['mother', 'female', 'male', 'castrated', 'young_female', 'young_male', 'young_castrated'];

    public const CATTLE = 'cattle';

    protected $table = 'kudde_species';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'class_names' => 'array',
            'birth_word' => 'array',
            'young_tagged_at_weaning' => 'boolean',
            'sort_order' => 'integer',
            'young_until_days' => 'integer',
            'gestation_days' => 'integer',
            'weigh_every_days' => 'integer',
        ];
    }

    /** @return HasMany<Animal, $this> */
    public function animals(): HasMany
    {
        return $this->hasMany(Animal::class);
    }

    /** @param  Builder<Species>  $query */
    public function scopeInFarmOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The species the farm keeps, in its order.
     *
     * @return Collection<int, self>
     */
    public static function enabled(): Collection
    {
        return self::query()->where('enabled', true)->inFarmOrder()->get();
    }

    /**
     * The species a request means when it names none: the only one the farm keeps, else cattle (what every
     * record before species was, and what an older phone's outbox still sends without saying).
     */
    public static function fallback(): self
    {
        $enabled = self::enabled();

        $only = $enabled->count() === 1 ? $enabled->first() : null;

        return $only ?? self::query()->where('code', self::CATTLE)->firstOrFail();
    }

    public static function byCode(?string $code): ?self
    {
        return $code === null || $code === '' ? null : self::query()->where('code', $code)->first();
    }

    /** The species' name in the farm's language. */
    public function name(): string
    {
        return app()->getLocale() === 'af' ? $this->name_af : $this->name_en;
    }

    /** What this species calls a class role, in the farm's language ("vers", "ewe lamb"). */
    public function className(string $role): string
    {
        $names = $this->class_names[app()->getLocale()] ?? $this->class_names['en'] ?? [];

        return (string) ($names[$role] ?? $this->class_names['en'][$role] ?? $role);
    }

    public function birthWord(): string
    {
        return (string) ($this->birth_word[app()->getLocale()] ?? $this->birth_word['en'] ?? '');
    }

    /** How often an alive animal of this species should be weighed: its own setting, else Kudde's. */
    public function weighEveryDays(): int
    {
        return $this->weigh_every_days ?: KuddeSettings::weighEveryDays();
    }
}
