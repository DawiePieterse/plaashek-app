<?php

use App\Http\Controllers\SetupController;
use App\Services\FarmSetup;
use Illuminate\Support\Facades\Route;

// The farm's front door: the setup page until a farm exists, then the office. Each module adds its own
// routes from its folder (app/Modules/<Name>/routes.php).
Route::get('/', fn (FarmSetup $setup) => redirect($setup->isSetUp() ? '/admin' : '/setup'));

Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store')->middleware('throttle:10,1');
