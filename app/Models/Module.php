<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Whether the farm has a module switched on, and a paid module's licence. Read through
 * App\Modules\ModuleRegistry, which also knows that free modules are always on and never licensed.
 *
 * @property string $code
 * @property bool $enabled
 * @property string|null $licence active|grace|suspended|cancelled; null for a free module (and, before licences
 *                                were recorded, for a paid one switched on: that counts as active)
 * @property Carbon|null $licence_since
 * @property Carbon|null $grace_until the last day a module in grace still saves
 */
class Module extends Model
{
    public const ACTIVE = 'active';

    /** Paid late: everything works for GRACE_DAYS, then the module is read-only. */
    public const GRACE = 'grace';

    /** Read-only: the farm sees and exports its records; nothing new is saved. */
    public const SUSPENDED = 'suspended';

    /** Switched off: no tab, no tile, the API refuses. The tables and records stay, for the farm's export. */
    public const CANCELLED = 'cancelled';

    public const LICENCES = [self::ACTIVE, self::GRACE, self::SUSPENDED, self::CANCELLED];

    /** PLAN.md section 1.1: a lapsed licence keeps capturing 14 days. */
    public const GRACE_DAYS = 14;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'licence_since' => 'date:Y-m-d', 'grace_until' => 'date:Y-m-d'];
    }
}
