<?php

namespace App\Modules\Kudde\Models;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that happened to an animal. One table for every kind, as old Kudde: the ICAR event types a small
 * farm actually uses (birth, weight, treatment, movement, death, sale), collapsed instead of a schema each.
 *
 * Dates are days, not timestamps, so several events on one date is the normal case (weighed and treated at
 * the same muster): every list orders by event_date then id, or the order changes each time it is read.
 *
 * @property int $id
 * @property string|null $client_uuid the phone's id for the capture, what makes a replay record once
 * @property int $animal_id
 * @property string $kind
 * @property Carbon $event_date
 * @property float|null $value kg, for a weight event
 * @property string $note
 * @property string $location the camp as typed, snapped to the office's spelling for movements and births
 * @property string $product
 * @property string $dose
 * @property int|null $withdrawal_days
 * @property float|null $weather_temp_max
 * @property float|null $weather_temp_min
 * @property float|null $weather_rain
 * @property int|null $user_id
 * @property int|null $worker_id
 * @property Carbon $created_at
 */
class AnimalEvent extends Model
{
    public const BIRTH = 'birth';

    public const WEIGHT = 'weight';

    public const TREATMENT = 'treatment';

    public const MOVEMENT = 'movement';

    public const DEATH = 'death';

    public const SALE = 'sale';

    public const KINDS = [self::BIRTH, self::WEIGHT, self::TREATMENT, self::MOVEMENT, self::DEATH, self::SALE];

    /**
     * Only these place an animal in a camp. A weighing's location, if ever typed, is where the scale was,
     * not where the animal lives.
     */
    public const PLACING_KINDS = [self::MOVEMENT, self::BIRTH];

    protected $table = 'kudde_events';

    protected $guarded = ['id'];

    protected $attributes = ['note' => '', 'location' => '', 'product' => '', 'dose' => ''];

    protected function casts(): array
    {
        return [
            'event_date' => 'date:Y-m-d',
            'value' => 'float',
            'withdrawal_days' => 'integer',
            'weather_temp_max' => 'float',
            'weather_temp_min' => 'float',
            'weather_rain' => 'float',
        ];
    }

    /** @return BelongsTo<Animal, $this> */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
