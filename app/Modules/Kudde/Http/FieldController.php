<?php

namespace App\Modules\Kudde\Http;

use App\Http\Controllers\Controller;
use App\Models\Worker;
use App\Modules\Kudde\Support\FieldSession;
use App\Modules\Kudde\Support\KuddeSettings;
use App\Support\Farm;
use App\Support\NaturalOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The Kudde field app at /kudde/field: old Kudde's field screens, opened by a worker's card scan. Its own
 * installable app with a service worker scoped to /kudde/field, as the Weather page is, so it opens with no
 * signal at the crush.
 */
class FieldController extends Controller
{
    /** Shell files the service worker keeps. Changing any of them changes the cache name. */
    private const SHELL = [
        'css/styles.css',
        'js/api.js', 'js/app.js', 'js/camps.js', 'js/idb.js', 'js/products.js', 'js/ptr.js', 'js/scan.js', 'js/signin.js',
        'vendor/jsqr/jsQR.js', 'vendor/tailwind.js',
        'vendor/fontawesome/css/all.min.css', 'vendor/fontawesome/webfonts/fa-solid-900.woff2',
        'icons/icon-180.png', 'icons/icon-192.png',
    ];

    public function page(Farm $farm): View
    {
        return view('kudde::field', [
            'page' => [
                'farm' => $farm->name(),
                't' => trans('kudde::ui.field'),
                'serviceWorker' => route('kudde.field.sw', absolute: false),
                'weighEveryDays' => KuddeSettings::weighEveryDays(),
            ],
            'version' => self::version(),
        ]);
    }

    /**
     * The card scan. Answers the token the phone keeps until midnight, and who it belongs to so the header can
     * say whose day it is. Throttled per address: numbers are short, and this is the door.
     */
    public function signIn(Request $request): JsonResponse
    {
        $number = trim((string) $request->validate(['number' => ['required', 'string', 'max:20']])['number']);
        $worker = Worker::query()->where('number', $number)->first();

        if ($worker === null) {
            return response()->json(['message' => __('kudde::ui.unknown_card', ['number' => $number])], 404);
        }
        if (! $worker->active) {
            return response()->json(['message' => __('kudde::ui.not_working_here', ['name' => $worker->displayName()])], 403);
        }

        $session = FieldSession::start($worker, (string) $request->userAgent());

        return response()->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at']->toIso8601String(),
            'worker' => self::present($worker),
        ]);
    }

    public function signOut(Request $request): Response
    {
        FieldSession::end($request);

        return response()->noContent();
    }

    /**
     * The active workers, for the phone to recognise a card with no signal: number and name only, never a
     * cell number or anything Werkers will hold.
     */
    public function workers(): JsonResponse
    {
        return response()->json(Worker::query()->where('active', true)->get()
            ->sort(fn (Worker $a, Worker $b) => NaturalOrder::compare($a->number, $b->number))
            ->map(fn (Worker $w) => self::present($w))->values());
    }

    public function serviceWorker(): Response
    {
        $base = asset('modules/kudde');
        $shell = array_map(fn (string $file) => $base.'/'.$file.'?v='.self::version(), self::SHELL);
        $shell[] = route('kudde.field', absolute: false);

        $script = view('kudde::sw', ['cache' => 'plaashek-kudde-'.self::version(), 'shell' => $shell, 'scope' => '/kudde/field'])->render();

        return response($script, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Service-Worker-Allowed', '/kudde/field')
            ->header('Cache-Control', 'no-cache');
    }

    public function manifest(Farm $farm): JsonResponse
    {
        $icons = asset('modules/kudde/icons');

        return response()->json([
            'name' => $farm->name().' · Kudde',
            'short_name' => 'Kudde',
            'start_url' => '/kudde/field',
            'scope' => '/kudde/field',
            'display' => 'standalone',
            'background_color' => '#0A2F6B',
            'theme_color' => '#0A2F6B',
            'icons' => [
                ['src' => $icons.'/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $icons.'/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /** A short hash of the shell files and the page: changes with any of them, so no CACHE bump to forget. */
    public static function version(): string
    {
        $stamp = (string) @filemtime(dirname(__DIR__).'/views/field.blade.php');
        foreach (self::SHELL as $file) {
            $stamp .= '|'.@filemtime(public_path('modules/kudde/'.$file));
        }

        return substr(md5($stamp), 0, 10);
    }

    /** @return array{number: string, name: string} */
    private static function present(Worker $worker): array
    {
        return ['number' => $worker->number, 'name' => $worker->displayName()];
    }
}
