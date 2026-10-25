<?php

namespace App\Modules\Kudde\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An animal, as small-scale livestock recording needs it, of one of the farm's species: a deliberate trim of ICAR's animal resource, not a
 * stud registry (no herdbook numbers, breed codes or DNA). Who the animal is lives here; what happens to it
 * is an AnimalEvent; where it is, what it weighs and what kind of animal it is are derived (HerdState).
 *
 * @property int $id
 * @property int $species_id
 * @property string $tag the ear tag, what the farmer reads off the animal
 * @property string $name
 * @property string $breed free text: no breed-code lookup
 * @property string $sex male|female
 * @property Carbon|null $birth_date
 * @property string|null $sire_tag by tag, not a foreign key: the sire may be a borrowed bull never recorded here
 * @property string|null $dam_tag likewise: the dam may have been sold or died
 * @property bool $castrated a castrated male is a steer, the one part of the class nothing else records
 * @property string $status alive|dead|sold, set by a death or sale event, so there is no second field to forget
 * @property int|null $user_id who entered it
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Animal extends Model
{
    public const MALE = 'male';

    public const FEMALE = 'female';

    public const SEXES = [self::MALE, self::FEMALE];

    public const ALIVE = 'alive';

    public const DEAD = 'dead';

    public const SOLD = 'sold';

    public const STATUSES = [self::ALIVE, self::DEAD, self::SOLD];

    protected $table = 'kudde_animals';

    protected $guarded = ['id'];

    protected $attributes = ['name' => '', 'breed' => '', 'castrated' => false, 'status' => self::ALIVE];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'castrated' => 'boolean',
        ];
    }

    /** @return BelongsTo<Species, $this> */
    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    /** @return HasMany<AnimalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AnimalEvent::class);
    }

    /** @return HasMany<Photo, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function isAlive(): bool
    {
        return $this->status === self::ALIVE;
    }
}
