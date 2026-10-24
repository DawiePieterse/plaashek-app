<?php

namespace App\Http\Controllers;

use App\Support\Farm;
use Illuminate\Http\JsonResponse;

/**
 * The office as an app on the farmer's phone: the gate on the home screen, opening straight into /admin. Open
 * without a login because the browser fetches the manifest without the session cookie. Weer and Kudde's field
 * app keep their own, scoped to their folders.
 */
class ManifestController extends Controller
{
    public function __invoke(Farm $farm): JsonResponse
    {
        $icons = asset('icons');

        return response()->json([
            'name' => $farm->name().' · Plaashek',
            'short_name' => 'Plaashek',
            'start_url' => '/admin',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#0A2F6B',
            'theme_color' => '#0A2F6B',
            'icons' => [
                ['src' => $icons.'/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $icons.'/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $icons.'/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
