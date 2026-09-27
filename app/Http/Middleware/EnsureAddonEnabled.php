<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "addon:recipes": 404 unless the addon is on for the user's household and the
 * owner did not switch it off for this member.
 */
class EnsureAddonEnabled
{
    public function handle(Request $request, Closure $next, string $addon): Response
    {
        abort_unless((bool) $request->user()?->canUseAddon($addon), 404);

        return $next($request);
    }
}
