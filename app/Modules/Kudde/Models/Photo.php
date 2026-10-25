<?php

namespace App\Modules\Kudde\Models;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo of an animal. The file lives on the private disk under photos/kudde/<animal id>/ and is only ever
 * shown by a signed link from the app: a farm's animals are its own business, and the hosting is shared.
 *
 * @property int $id
 * @property int $animal_id
 * @property string $path on the private disk
 * @property string|null $client_uuid the phone's id for the capture
 * @property Carbon $taken_on
 * @property string $note
 * @property int|null $user_id
 * @property int|null $worker_id
 * @property Carbon $created_at
 */
class Photo extends Model
{
    public const DISK = 'local';

    /** What a phone may send: it resizes before uploading (~200 KB), so this only stops a full-size original. */
    public const MAX_KB = 4096;

    protected $table = 'kudde_photos';

    protected $guarded = ['id'];

    protected $attributes = ['note' => ''];

    protected function casts(): array
    {
        return ['taken_on' => 'date:Y-m-d'];
    }

    public static function directory(int $animalId): string
    {
        return 'photos/kudde/'.$animalId;
    }

    protected static function booted(): void
    {
        // The file goes with its row: every farm on the package shares one disk.
        static::deleted(fn (Photo $photo) => app('filesystem')->disk(self::DISK)->delete($photo->path));
    }

    /** A link that works for an hour, for the office page and the phones' img tags (which cannot send a token). */
    public function url(): string
    {
        return app('filesystem')->disk(self::DISK)->temporaryUrl($this->path, now()->addHour());
    }

    /** @return BelongsTo<Animal, $this> */
    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Worker, $this> */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
