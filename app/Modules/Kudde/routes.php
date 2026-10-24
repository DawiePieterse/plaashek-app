<?php

use App\Http\Middleware\SetFarmLocale;
use App\Modules\Kudde\Http\FieldController as Field;
use App\Modules\Kudde\Http\KuddeApiController as Api;
use Illuminate\Support\Facades\Route;

// Kudde's API (PLAN.md section 2.2), old Kudde's /api under /kudde/api. Behind the office login until the
// field shell (phase 6) gives phones their own sign-in and device tokens; a viewer only reads.
Route::middleware(['web', 'auth', 'module:kudde', 'throttle:600,1'])->prefix('kudde/api')->name('kudde.api.')->group(function () {
    Route::get('/animals', [Api::class, 'animals'])->name('animals');
    Route::post('/animals', [Api::class, 'storeAnimal'])->name('animals.store');
    Route::get('/animals/{tag}', [Api::class, 'animal'])->name('animal');
    Route::patch('/animals/{tag}', [Api::class, 'updateAnimal'])->name('animal.update');
    Route::get('/animals/{tag}/events', [Api::class, 'animalEvents'])->name('animal.events');

    Route::get('/events', [Api::class, 'events'])->name('events');
    Route::post('/events', [Api::class, 'storeEvent'])->name('events.store');
    Route::post('/events/movement/bulk', [Api::class, 'moveAnimals'])->name('events.move');
    Route::post('/events/treatment/bulk', [Api::class, 'treatAnimals'])->name('events.treat');
    Route::post('/calvings', [Api::class, 'storeCalving'])->name('calvings.store');
    Route::get('/litters', [Api::class, 'litters'])->name('litters');
    Route::post('/litters/{litter}/wean', [Api::class, 'weanLitter'])->whereNumber('litter')->name('litters.wean');

    Route::get('/products', [Api::class, 'products'])->name('products');
    Route::get('/locations', [Api::class, 'locations'])->name('locations');

    Route::get('/camps', [Api::class, 'camps'])->name('camps');
    Route::post('/camps', [Api::class, 'storeCamp'])->name('camps.store');
    Route::put('/camps/order', [Api::class, 'orderCamps'])->name('camps.order');
    Route::patch('/camps/{camp}', [Api::class, 'updateCamp'])->whereNumber('camp')->name('camps.update');

    Route::get('/dashboard', [Api::class, 'dashboard'])->name('dashboard');
    Route::get('/export/animals.csv', [Api::class, 'exportAnimals'])->name('export.animals');
    Route::get('/export/events.csv', [Api::class, 'exportEvents'])->name('export.events');
});

// The field app (old Kudde's field screens), signed in by a worker's card scan once a day (FieldSession). The
// page and its service worker are open: the sign-in screen is in the page, and nothing in it is the farm's.
Route::middleware(['web', 'module:kudde'])->prefix('kudde/field')->group(function () {
    Route::get('/', [Field::class, 'page'])->name('kudde.field');
    Route::get('/sw.js', [Field::class, 'serviceWorker'])->name('kudde.field.sw');
    Route::get('/manifest.webmanifest', [Field::class, 'manifest'])->name('kudde.field.manifest');
});

// Its API: the same methods as the office's, behind the card session, less the office's corrections, camp
// management and exports. No session cookie and no CSRF: the phone sends its token as a Bearer header.
Route::middleware([SetFarmLocale::class, 'module:kudde', 'throttle:600,1'])->prefix('kudde/field/api')->name('kudde.field.api.')->group(function () {
    Route::post('/sign-in', [Field::class, 'signIn'])->middleware('throttle:kudde-sign-in')->name('sign-in');
    Route::delete('/sign-in', [Field::class, 'signOut'])->name('sign-out');

    Route::middleware('kudde.worker')->group(function () {
        Route::get('/workers', [Field::class, 'workers'])->name('workers');
        Route::get('/animals', [Api::class, 'animals'])->name('animals');
        Route::post('/animals', [Api::class, 'storeAnimal'])->name('animals.store');
        Route::get('/animals/{tag}/events', [Api::class, 'animalEvents'])->name('animal.events');
        Route::post('/events', [Api::class, 'storeEvent'])->name('events.store');
        Route::post('/events/movement/bulk', [Api::class, 'moveAnimals'])->name('events.move');
        Route::post('/events/treatment/bulk', [Api::class, 'treatAnimals'])->name('events.treat');
        Route::post('/calvings', [Api::class, 'storeCalving'])->name('calvings.store');
        Route::get('/litters', [Api::class, 'litters'])->name('litters');
        Route::post('/litters/{litter}/wean', [Api::class, 'weanLitter'])->whereNumber('litter')->name('litters.wean');
        Route::get('/products', [Api::class, 'products'])->name('products');
        Route::get('/locations', [Api::class, 'locations'])->name('locations');
        Route::get('/camps', [Api::class, 'camps'])->name('camps');
    });
});
