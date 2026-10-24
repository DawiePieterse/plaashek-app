<?php

namespace App\Modules\Kudde\Support;

use App\Models\Worker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * A worker's day on the Kudde field app. Scanning their card (its QR holds only the worker number) signs the
 * phone in until the farm's midnight, so a day at the crush needs one scan, not one per animal.
 *
 * The scan is the whole of the sign-in, by the owner's call: anyone holding a working card can capture. What
 * keeps that honest is that every record names the worker, an inactive worker's card is refused, and the
 * office can end a day's sessions by switching the worker off.
 *
 * Also the route middleware `kudde.worker`: a request without a live session gets 401, which the phone reads
 * as "scan your card".
 */
class FieldSession
{
    private const ATTRIBUTE = 'kudde.worker';

    /**
     * Signs an active worker in for the rest of the day. The caller has already refused an unknown number or
     * a worker who no longer works here, each with its own message.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public static function start(Worker $worker, string $userAgent = ''): array
    {
        $token = Str::random(48);
        $expires = today()->endOfDay();

        DB::table('kudde_field_sessions')->insert([
            'worker_id' => $worker->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expires,
            'user_agent' => mb_substr($userAgent, 0, 200),
            'created_at' => now(),
        ]);

        // Yesterday's tokens are dead weight; clear them as today's first scans come in.
        DB::table('kudde_field_sessions')->where('expires_at', '<', now()->subDay())->delete();

        return ['token' => $token, 'expires_at' => $expires];
    }

    public static function end(Request $request): void
    {
        $token = $request->bearerToken();

        if ($token !== null) {
            DB::table('kudde_field_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    /** The worker this request's session belongs to, once the middleware has let it through. */
    public static function worker(Request $request): ?Worker
    {
        $worker = $request->attributes->get(self::ATTRIBUTE);

        return $worker instanceof Worker ? $worker : null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $session = $token === null ? null : DB::table('kudde_field_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
        $worker = $session === null ? null : Worker::query()->find($session->worker_id);

        // A worker switched off in the office stops at their next request, not at midnight.
        if ($session === null || $worker === null || ! $worker->active) {
            return response()->json(['message' => __('kudde::ui.scan_card'), 'signed_out' => true], 401);
        }

        DB::table('kudde_field_sessions')->where('id', $session->id)->update(['last_seen_at' => now()]);
        $request->attributes->set(self::ATTRIBUTE, $worker);

        return $next($request);
    }
}
