<?php

use App\Http\Controllers\FieldDeviceController;
use App\Http\Middleware\SetFarmLocale;
use Illuminate\Support\Facades\Route;

// The field app's core API (PLAN.md section 5.1), loaded outside the web group: a phone has no session cookie
// and no CSRF token, it sends its token as the X-Device header. A phone becomes one of the farm's phones with a
// code from the devices page.
Route::middleware([SetFarmLocale::class])->prefix('field/api')->name('field.api.')->group(function () {
    Route::post('/pair', [FieldDeviceController::class, 'pair'])->middleware('throttle:field-pair')->name('pair');
    Route::get('/device', [FieldDeviceController::class, 'show'])->middleware('throttle:120,1')->name('device');
});
