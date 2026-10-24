<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An office login (PLAN.md section 5). The owner may do everything, a manager everything but users and
 * Farm settings, a viewer only reads. Plaashek's support account is a role of its own so the audit log
 * can tell it apart from the farm's people.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const VIEWER = 'viewer';

    public const STAFF = 'plaashek_staff';

    public const ROLES = [self::OWNER, self::MANAGER, self::VIEWER, self::STAFF];

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** Owner-level rights: the farm's owner, and Plaashek staff acting for them. */
    public function isOwner(): bool
    {
        return in_array($this->role, [self::OWNER, self::STAFF], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, self::ROLES, true);
    }
}
