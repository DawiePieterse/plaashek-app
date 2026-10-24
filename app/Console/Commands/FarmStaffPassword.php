<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Gives Plaashek's support account a fresh password when support needs to sign in, and writes that to the
 * farm's audit log so the owner can see it happened.
 */
class FarmStaffPassword extends Command
{
    protected $signature = 'farm:staff-password';

    protected $description = "Set a new password on Plaashek's support account";

    public function handle(): int
    {
        $staff = User::query()->where('role', User::STAFF)->first();

        if ($staff === null) {
            $this->error('This farm has no Plaashek support account.');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);
        $staff->update(['password' => $password]);
        AuditLog::record('staff.password', $staff->email);

        $this->info("Sign in at /admin as {$staff->email} with: {$password}");

        return self::SUCCESS;
    }
}
