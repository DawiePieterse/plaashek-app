<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A camp (paddock) the farm can put animals in: the list the office manages and the chips the phones offer.
 *
 * Deliberately NOT what a record points at. A Kudde event's location stays the text that was typed, and an
 * animal's current camp is derived from its history: this table only decides what the pickers show and what
 * spelling a typed name snaps to (App\Support\CampNames). Renaming a camp therefore rewrites nothing, and
 * archiving one hides it from the pickers without touching a single record.
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order the way the camps lie on the farm, not alphabetical
 * @property bool $archived archived rather than deleted: the name still appears in every record that used it
 */
class Camp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'archived' => 'boolean'];
    }

    /** @param  Builder<Camp>  $query */
    public function scopeInFarmOrder(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
