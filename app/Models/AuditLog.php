<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the farm's audit log. Written through record(), never edited.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?string $subject = null, ?array $before = null, ?array $after = null): self
    {
        return self::query()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject' => $subject,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
