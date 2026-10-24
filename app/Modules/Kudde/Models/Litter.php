<?php

namespace App\Modules\Kudde\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A birth of young that are not tagged yet (a sow's litter): counted on the mother until weaning, when the
 * young become animals (Herd::weanLitter). Written only through Herd.
 *
 * @property int $id
 * @property int $dam_id
 * @property int $birth_event_id
 * @property Carbon $born_on
 * @property int $born_alive
 * @property int $stillborn
 * @property string|null $sire_tag
 * @property int $fostered on minus off
 * @property Carbon|null $weaned_on
 * @property int|null $weaned
 * @property string|null $wean_uuid
 * @property-read Animal $dam
 */
class Litter extends Model
{
    protected $table = 'kudde_litters';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'born_on' => 'date',
            'weaned_on' => 'date',
            'born_alive' => 'integer',
            'stillborn' => 'integer',
            'fostered' => 'integer',
            'weaned' => 'integer',
        ];
    }

    /** @return BelongsTo<Animal, $this> */
    public function dam(): BelongsTo
    {
        return $this->belongsTo(Animal::class, 'dam_id');
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'id' => $this->id,
            'dam_tag' => $this->dam->tag,
            'species' => $this->dam->species->code,
            'born_on' => $this->born_on->toDateString(),
            'age_days' => (int) $this->born_on->diffInDays(today()),
            'born_alive' => $this->born_alive,
            'stillborn' => $this->stillborn,
            'sire_tag' => $this->sire_tag,
            'fostered' => $this->fostered,
            'weaned_on' => $this->weaned_on?->toDateString(),
            'weaned' => $this->weaned,
        ];
    }
}
