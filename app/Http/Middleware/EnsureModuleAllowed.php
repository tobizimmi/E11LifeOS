<?php

namespace App\Http\Middleware;

use App\Support\Modules\ModuleAccess;
use App\Support\Modules\ModuleCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages of an area the administrator locked for the household or the account (or that is not
 * licensed) answer 404. Public share links (without sign-in) are not affected.
 */
class EnsureModuleAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $module = ModuleCatalog::forRoute($route?->getName());

        // Only signed-in routes (session or app token); public share links stay reachable.
        if ($module !== null && $request->user() && array_intersect(['auth', 'auth.token'], $route->gatherMiddleware()) !== []) {
            abort_unless(ModuleAccess::allows($request->user(), $module), 404);
        }

        return $next($request);
    }
}
