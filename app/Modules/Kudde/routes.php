<?php

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
