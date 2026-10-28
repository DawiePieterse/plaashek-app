<?php

namespace App\Support;

use App\Models\Device;
use App\Models\Worker;
use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A worker's day on one of the farm's phones (PLAN.md section 5.1). The phone was paired by the office (its
 * X-Device token, App\Support\Devices); scanning a card on it opens a session until the farm's midnight, so a
 * day at the crush needs one scan, not one per animal.
 *
 * The card's QR holds only the worker number, by the owner's call; what keeps that honest is that only a paired
 * phone accepts a scan, every record names the worker and the phone, an inactive worker's card is refused, and the
 * office can end a day by switching the worker or the phone off.
 *
 * Also the route middleware `field.worker` (`field.worker:kudde` for a module's routes): no paired phone is a 401
 * with `unpaired`, no live session a 401 with `signed_out` (the phone shows the scan screen), and a module the
 * worker may not use, or the farm has switched off, a 403.
 */
class FieldSession
{
    private const WORKER = 'field.worker';

    private const DEVICE = 'field.device';

    /**
     * Signs an active worker in on this phone for the rest of the day. The caller has already refused an unknown
     * number or a worker who no longer works here, each with its own message.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public static function start(Worker $worker, Device $device): array
    {
        $token = Str::random(48);
        $expires = today()->endOfDay();

        DB::table('field_sessions')->insert([
            'worker_id' => $worker->id,
            'device_id' => $device->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
            'created_at' => now(),
        ]);
        $device->forceFill(['last_worker_id' => $worker->id, 'last_seen_at' => now()])->save();

        // Yesterday's tokens are dead weight; clear them as today's first scans come in.
        DB::table('field_sessions')->where('expires_at', '<', now()->subDay())->delete();

        return ['token' => $token, 'expires_at' => $expires];
    }

    public static function end(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            DB::table('field_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    /** The worker this request's session belongs to, once the middleware has let it through. */
    public static function worker(Request $request): ?Worker
    {
        $worker = $request->attributes->get(self::WORKER);

        return $worker instanceof Worker ? $worker : null;
    }

    /** The phone the request came from, once the middleware has let it through. */
    public static function device(Request $request): ?Device
    {
        $device = $request->attributes->get(self::DEVICE);

        return $device instanceof Device ? $device : null;
    }

    /**
     * The modules this worker may use on a phone today: with screens on a phone, on for the farm and ticked for
     * the worker.
     *
     * @return list<string>
     */
    public static function modulesFor(Worker $worker): array
    {
        $codes = [];
        foreach (self::fieldModules() as $code => $name) {
            if ($worker->mayUse($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The farm's modules that have screens on a phone, code to name: what a worker can be given.
     *
     * @return array<string, string>
     */
    public static function fieldModules(): array
    {
        $registry = app(ModuleRegistry::class);
        $modules = [];
        foreach ($registry->all() as $module) {
            if ($module::fieldUrl() !== null && $registry->isEnabled($module::code())) {
                $modules[$module::code()] = __($module::nameKey());
            }
        }

        return $modules;
    }

    public function handle(Request $request, Closure $next, ?string $module = null): Response
    {
        $device = app(Devices::class)->fromRequest($request);
        if ($device === null) {
            return response()->json(['message' => __('plaashek.devices.not_paired'), 'unpaired' => true], 401);
        }

        $token = $request->bearerToken();
        $session = $token === null ? null : DB::table('field_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->where('device_id', $device->id)
            ->where('expires_at', '>', now())
            ->first();
        $worker = $session === null ? null : Worker::query()->find($session->worker_id);

        // A worker switched off in the office stops at their next request, not at midnight.
        if ($session === null || $worker === null || ! $worker->active) {
            return response()->json(['message' => __('plaashek.field.scan_card'), 'signed_out' => true], 401);
        }
        if ($module !== null && ! $worker->mayUse($module)) {
            return response()->json(['message' => __('plaashek.field.not_allowed', ['name' => $worker->displayName()])], 403);
        }

        DB::table('field_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
        // A request that got through is a sync: the 21-day window counts from the last one.
        $device->forceFill(['last_seen_at' => now(), 'last_sync_at' => now(), 'last_worker_id' => $worker->id])->save();
        $request->attributes->set(self::WORKER, $worker);
        $request->attributes->set(self::DEVICE, $device);

        return $next($request);
    }
}
