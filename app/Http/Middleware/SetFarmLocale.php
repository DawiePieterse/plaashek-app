<?php

namespace App\Http\Middleware;

use App\Support\Farm;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Screens speak the farm's language (`af` or `en`, set in Farm settings), whoever is looking. The database
 * and the code stay English.
 */
class SetFarmLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(app(Farm::class)->language());

        return $next($request);
    }
}
