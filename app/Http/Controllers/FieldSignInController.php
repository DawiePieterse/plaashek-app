<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use App\Support\Devices;
use App\Support\FieldSession;
use App\Support\NaturalOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The card scan on a paired phone (PLAN.md section 5.1), for every module's field screens: the worker number from
 * the card in, the day's token out, with the modules this worker may use. Kudde's field app was the first to use it.
 */
class FieldSignInController extends Controller
{
    public function __construct(private Devices $devices) {}

    /**
     * Refuses a phone the office has not paired (or has disabled) before it looks at the card: a printed card on
     * an unknown phone opens nothing. Throttled per address: numbers are short, and this is the door.
     */
    public function signIn(Request $request): JsonResponse
    {
        $device = $this->devices->fromRequest($request);
        if ($device === null) {
            return response()->json(['message' => __('plaashek.devices.not_paired'), 'unpaired' => true], 401);
        }

        $number = trim((string) $request->validate(['number' => ['required', 'string', 'max:20']])['number']);
        $worker = Worker::query()->where('number', $number)->first();

        if ($worker === null) {
            return response()->json(['message' => __('plaashek.field.unknown_card', ['number' => $number])], 404);
        }
        if (! $worker->active) {
            return response()->json(['message' => __('plaashek.field.not_working_here', ['name' => $worker->displayName()])], 403);
        }

        $session = FieldSession::start($worker, $device);

        return response()->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at']->toIso8601String(),
            'worker' => self::present($worker),
            'modules' => FieldSession::modulesFor($worker),
        ]);
    }

    public function signOut(Request $request): Response
    {
        FieldSession::end($request);

        return response()->noContent();
    }

    /**
     * The active workers, for the phone to recognise a card with no signal: number, name and modules only, never
     * a cell number or anything Werkers will hold.
     */
    public function workers(): JsonResponse
    {
        return response()->json(Worker::query()->where('active', true)->get()
            ->sort(fn (Worker $a, Worker $b) => NaturalOrder::compare($a->number, $b->number))
            ->map(fn (Worker $w) => self::present($w) + ['modules' => FieldSession::modulesFor($w)])->values());
    }

    /** @return array{number: string, name: string} */
    private static function present(Worker $worker): array
    {
        return ['number' => $worker->number, 'name' => $worker->displayName()];
    }
}
