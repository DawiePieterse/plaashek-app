<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone the farm employs (PLAN.md section 2.4), as far as the core needs: a number, a name, a photo and
 * whether they still work here. Not someone who signs in: most workers never touch a phone. The Werkers module
 * adds identity and payroll in its own tables.
 *
 * @property int $id
 * @property string $number the farm's own; the card's QR holds exactly this
 * @property string $first_name
 * @property string $last_name
 * @property string $preferred_name
 * @property string $cell
 * @property string|null $photo path on the private disk
 * @property bool $active
 * @property list<string>|null $modules the module codes this worker may use on a phone; null: every one (registered
 *                                      before modules were chosen per worker)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Worker extends Model
{
    public const PHOTO_DISK = 'local';

    public const PHOTO_DIRECTORY = 'workers';

    protected $guarded = ['id'];

    protected $attributes = ['last_name' => '', 'preferred_name' => '', 'cell' => '', 'active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'modules' => 'array'];
    }

    /** Whether this worker gets the module's tile on a phone (PLAN.md section 5.1). */
    public function mayUse(string $code): bool
    {
        return $this->modules === null || in_array($code, $this->modules, true);
    }

    /** An empty box on the form arrives as null; the register stores it as nothing, not as unknown. */
    public function setAttribute($key, $value)
    {
        if ($value === null && in_array($key, ['last_name', 'preferred_name', 'cell'], true)) {
            $value = '';
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Every change to the register is in the farm's audit log (who took someone off, who changed a number), and
     * a replaced photo's file goes with it: the farms share one hosting package's disk.
     */
    protected static function booted(): void
    {
        static::created(fn (Worker $w) => AuditLog::record('worker.created', $w->number, after: $w->audited()));

        static::updated(function (Worker $w) {
            $changed = array_keys($w->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at']));
            if ($changed !== []) {
                AuditLog::record('worker.updated', $w->number,
                    array_intersect_key($w->getOriginal(), array_flip($changed)), $w->only($changed));
            }

            $old = $w->getOriginal('photo');
            if ($w->wasChanged('photo') && is_string($old) && $old !== '') {
                app('filesystem')->disk(self::PHOTO_DISK)->delete($old);
            }
        });
    }

    /** @return array<string, mixed> */
    private function audited(): array
    {
        return $this->only(['number', 'first_name', 'last_name', 'preferred_name', 'active', 'modules']);
    }

    /** The name a card prints and a phone shows: the preferred name if there is one. */
    public function displayName(): string
    {
        $first = $this->preferred_name !== '' ? $this->preferred_name : $this->first_name;

        return trim($first.' '.$this->last_name);
    }

    /** The photo as a data URI, for the card sheet: one page, nothing more for the printer to fetch. */
    public function photoDataUri(): ?string
    {
        $disk = app('filesystem')->disk(self::PHOTO_DISK);

        if ($this->photo === null || ! $disk->exists($this->photo)) {
            return null;
        }

        return 'data:'.($disk->mimeType($this->photo) ?: 'image/jpeg').';base64,'.base64_encode((string) $disk->get($this->photo));
    }
}
