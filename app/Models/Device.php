<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of the farm's phones (PLAN.md section 5.1). Paired by the office through App\Support\Devices; never
 * deleted, because records will name the phone they were captured on: a lost or replaced phone is disabled.
 *
 * @property int $id
 * @property string $label what the office calls it ("Crush phone", "Jan se foon")
 * @property string|null $token_hash the phone's token, hashed; null until paired
 * @property string|null $pairing_hash the open pairing code, hashed; null once used or never asked for
 * @property Carbon|null $pairing_expires_at
 * @property Carbon|null $paired_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_sync_at
 * @property int|null $last_worker_id
 * @property string $user_agent
 * @property Carbon|null $disabled_at
 * @property int|null $created_by
 */
class Device extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'pairing_hash'];

    protected $attributes = ['user_agent' => ''];

    protected function casts(): array
    {
        return [
            'pairing_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function isPaired(): bool
    {
        return $this->token_hash !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** @return BelongsTo<Worker, $this> */
    public function lastWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'last_worker_id');
    }
}
