<?php

namespace App\Modules\Weer\Http;

use App\Filament\Pages\FarmSettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Weer\OpenMeteoProxy;
use App\Support\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class WeerController extends Controller
{
    /** Shell files the service worker keeps, so the page opens with no signal. */
    private const SHELL = [
        'css/styles.css',
        'js/app.js', 'js/cache.js', 'js/chart.js', 'js/data.js', 'js/dates.js', 'js/farm.js', 'js/fields.js',
        'js/insights.js', 'js/openmeteo.js', 'js/series.js', 'js/state.js', 'js/ui.js',
        'vendor/fontawesome/css/all.min.css', 'vendor/fontawesome/webfonts/fa-solid-900.woff2',
        'vendor/html2canvas/html2canvas.min.js', 'vendor/jspdf/jspdf.umd.min.js', 'vendor/tailwind.js',
        'icons/icon-192.png',
    ];

    public function page(Farm $farm): View
    {
        $position = $farm->position();
        $user = auth()->user();

        return view('weer::page', [
            'page' => [
                'locale' => app()->getLocale(),
                'farm' => [
                    'name' => $farm->name(),
                    'lat' => $position['lat'] ?? null,
                    'lon' => $position['lon'] ?? null,
                    'seasonMonth' => $farm->seasonStartMonth(),
                    'frostThreshold' => $farm->frostThreshold(),
                ],
                't' => trans('weer::ui'),
                'settingsUrl' => $user instanceof User && $user->isOwner() ? FarmSettings::getUrl() : null,
                'serviceWorker' => route('weer.sw', absolute: false),
                'scope' => '/weer',
            ],
            'version' => self::version(),
        ]);
    }

    /**
     * The proxy. Signed out answers 401 as JSON (not a redirect to the login page), so the screens can say
     * "sign in again" instead of failing to parse a login form as weather.
     */
    public function api(Request $request, string $endpoint, OpenMeteoProxy $proxy): Response|JsonResponse
    {
        if (! auth()->check()) {
            return response()->json(['error' => true, 'reason' => __('weer::ui.signed_out')], 401);
        }

        $result = $proxy->get($endpoint, $request->query());

        return response($result['body'], $result['status'])
            ->header('Content-Type', 'application/json')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Weer-Cache', $result['cached'] ? 'hit' : 'miss');
    }

    /**
     * Served by a route rather than from public/ so it can be scoped to /weer (the page) while its files
     * live in public/modules/weer, and so its cache name changes whenever a shell file does: no manual
     * CACHE bump to forget.
     */
    public function serviceWorker(): Response
    {
        $base = asset('modules/weer');
        $shell = array_map(fn (string $file) => $base.'/'.$file.'?v='.self::version(), self::SHELL);
        $shell[] = route('weer.page', absolute: false);

        $script = view('weer::sw', ['cache' => 'plaashek-weer-'.self::version(), 'shell' => $shell])->render();

        return response($script, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Service-Worker-Allowed', '/weer')
            ->header('Cache-Control', 'no-cache');
    }

    public function manifest(Farm $farm): JsonResponse
    {
        $icons = asset('modules/weer/icons');

        return response()->json([
            'name' => $farm->name().' · '.__('plaashek.modules.weer'),
            'short_name' => __('plaashek.modules.weer'),
            'start_url' => '/weer',
            'scope' => '/weer',
            'display' => 'standalone',
            'background_color' => '#2f6b3a',
            'theme_color' => '#2f6b3a',
            'icons' => [
                ['src' => $icons.'/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $icons.'/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $icons.'/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /** A short hash of the shell files' modification times: changes with any of them. */
    public static function version(): string
    {
        $stamp = '';
        foreach (self::SHELL as $file) {
            $stamp .= @filemtime(public_path('modules/weer/'.$file)).'|';
        }

        return substr(md5($stamp), 0, 10);
    }
}
