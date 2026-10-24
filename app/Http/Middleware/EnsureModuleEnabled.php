<?php

namespace App\Http\Middleware;

use App\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `module:<code>`: a module the farm has not switched on does not exist for it (404),
 * pages and API alike.
 */
class EnsureModuleEnabled
{
    public function __construct(private ModuleRegistry $modules) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        abort_unless($this->modules->isEnabled($code), 404);

        return $next($request);
    }
}
