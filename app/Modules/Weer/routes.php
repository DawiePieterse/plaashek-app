<?php

use App\Modules\Weer\Http\WeerController;
use Illuminate\Support\Facades\Route;

// The Weather page and its Open-Meteo proxy (PLAN.md section 2.3). Both need a signed-in office user:
// the proxy spends Plaashek's Open-Meteo allowance. The page is its own installable app at /weer until the
// field shell exists (built with Kudde), with a service worker scoped to /weer so it opens offline.
Route::middleware(['web', 'module:weer'])->prefix('weer')->group(function () {
    Route::get('/', [WeerController::class, 'page'])->middleware('auth')->name('weer.page');
    Route::get('/now', [WeerController::class, 'now'])->middleware('auth')->name('weer.now');
    Route::get('/sw.js', [WeerController::class, 'serviceWorker'])->name('weer.sw');
    Route::get('/manifest.webmanifest', [WeerController::class, 'manifest'])->name('weer.manifest');
    Route::get('/api/{endpoint}', [WeerController::class, 'api'])
        ->whereIn('endpoint', ['archive', 'hf', 'fc', 'climate'])
        ->middleware('throttle:240,1')
        ->name('weer.api');
});
