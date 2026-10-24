<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Support\Farm;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the farm in a fresh install (PLAN.md section 6): its settings, the owner's login, Plaashek's
 * support account and the modules it starts with. Used by `farm:create` and by the first-run setup page,
 * which ask the same questions.
 */
class FarmSetup
{
    public function __construct(private Settings $settings, private ModuleRegistry $modules) {}

    /** A farm exists once anyone can log in. */
    public function isSetUp(): bool
    {
        return User::query()->exists();
    }

    /**
     * @param  array{name: string, language: string, owner_name: string, owner_email: string,
     *               owner_password?: string|null, latitude?: float|string|null, longitude?: float|string|null,
     *               modules?: list<string>}  $input
     * @return array{0: User, 1: string} the owner and their password (generated when none was given)
     */
    public function create(array $input): array
    {
        $password = $input['owner_password'] ?? null ?: Str::password(12, symbols: false);

        $owner = DB::transaction(function () use ($input, $password) {
            $this->settings->set(Farm::NAME, $input['name']);
            $this->settings->set(Farm::LANGUAGE, $input['language']);
            $this->settings->set(Farm::LATITUDE, self::coordinate($input['latitude'] ?? null));
            $this->settings->set(Farm::LONGITUDE, self::coordinate($input['longitude'] ?? null));

            $owner = User::query()->create([
                'name' => $input['owner_name'],
                'email' => $input['owner_email'],
                'password' => $password,
                'role' => User::OWNER,
            ]);

            // Plaashek's support account gets a password nobody knows; `farm:staff-password` sets one when
            // support is needed, and every sign-in shows in the farm's audit log.
            $staffEmail = (string) config('plaashek.staff_email');
            if ($staffEmail !== '' && strcasecmp($staffEmail, $input['owner_email']) !== 0) {
                User::query()->create([
                    'name' => 'Plaashek',
                    'email' => $staffEmail,
                    'password' => Str::password(32),
                    'role' => User::STAFF,
                ]);
            }

            $this->modules->setEnabled($input['modules'] ?? []);

            AuditLog::record('farm.created', $input['name'], after: [
                'language' => $input['language'],
                'modules' => $input['modules'] ?? [],
            ]);

            return $owner;
        });

        return [$owner, $password];
    }

    private static function coordinate(float|string|null $value): ?string
    {
        return is_numeric($value) ? (string) round((float) $value, 4) : null;
    }
}
