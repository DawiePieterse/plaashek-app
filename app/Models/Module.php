<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether the farm has a module switched on. Read through App\Modules\ModuleRegistry, which also knows
 * that free modules are always on.
 *
 * @property string $code
 * @property bool $enabled
 */
class Module extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
