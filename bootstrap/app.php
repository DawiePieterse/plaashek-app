<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetFarmLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The field app's API: no web group, so no session or CSRF (a phone sends its own tokens).
        then: fn () => Route::group([], base_path('routes/field.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            SetFarmLocale::class,
        ]);
        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
        ]);
        // Pages outside the panel (the Weather page) send a signed-out visitor to the panel's login.
        $middleware->redirectGuestsTo('/admin/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
