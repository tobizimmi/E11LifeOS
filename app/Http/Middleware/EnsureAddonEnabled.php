<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "addon:recipes": 404 unless the user's household has the addon switched on.
 */
class EnsureAddonEnabled
{
    public function handle(Request $request, Closure $next, string $addon): Response
    {
        abort_unless((bool) $request->user()?->currentHousehold()?->hasAddon($addon), 404);

        return $next($request);
    }
}
