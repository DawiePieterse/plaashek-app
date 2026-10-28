<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pairing the farm's phones (PLAN.md section 5.1). The office asks for a code for a named phone; the phone
 * redeems it once, within ten minutes, for the long-lived token it keeps. Card scans then work on that phone.
 */
class Devices
{
    /** How long a pairing code stays open: long enough to walk the phone over, short enough not to leak. */
    public const PAIRING_MINUTES = 10;

    /** No 0/O, 1/I/L: the code is read off a screen and typed on a phone in the sun. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * A one-use code for this phone. Asking again for a paired phone (reset, replaced) pairs it afresh: its old
     * token stops working once the new code is used.
     *
     * @return string the code, shown once
     */
    public function pairingCode(Device $device): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $device->forceFill([
            'pairing_hash' => self::hash($code),
            'pairing_expires_at' => now()->addMinutes(self::PAIRING_MINUTES),
        ])->save();
        AuditLog::record('device.pairing', $device->label);

        return $code;
    }

    /**
     * The phone's side: an open code for a phone not disabled gives it its token. Spaces, dashes and small
     * letters are forgiven, as a typed code has them.
     *
     * @return array{device: Device, token: string}|null
     */
    public function pair(string $code, string $userAgent = ''): ?array
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        $device = $normalised === '' ? null : Device::query()->where('pairing_hash', self::hash($normalised))->first();

        if ($device === null || $device->isDisabled() || $device->pairing_expires_at === null || $device->pairing_expires_at->isPast()) {
            return null;
        }

        $token = Str::random(64);
        $device->forceFill([
            'token_hash' => self::hash($token),
            'pairing_hash' => null,
            'pairing_expires_at' => null,
            'paired_at' => now(),
            'last_seen_at' => now(),
            'user_agent' => mb_substr($userAgent, 0, 200),
        ])->save();

        return ['device' => $device, 'token' => $token];
    }

    /** The paired, enabled phone a request comes from (its X-Device header), or null. */
    public function fromRequest(Request $request): ?Device
    {
        $token = (string) $request->header('X-Device', '');
        if ($token === '') {
            return null;
        }
        $device = Device::query()->where('token_hash', self::hash($token))->first();

        return $device === null || $device->isDisabled() ? null : $device;
    }

    public function disable(Device $device): void
    {
        $device->forceFill(['disabled_at' => now(), 'pairing_hash' => null, 'pairing_expires_at' => null])->save();
        AuditLog::record('device.disabled', $device->label);
    }

    public function enable(Device $device): void
    {
        $device->forceFill(['disabled_at' => null])->save();
        AuditLog::record('device.enabled', $device->label);
    }

    private static function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
