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

        // A Livewire update request is re-matched by Livewire against the page's original route
        // before this middleware runs, but a component embedded in an unrelated host page (e.g. a
        // widget or modal) needs its own module resolved from the component name instead.
        $module = ModuleCatalog::forRoute($route?->getName()) ?? ModuleCatalog::forComponent($this->componentName($request));

        // Only signed-in routes (session or app token); public share links stay reachable.
        if ($module !== null && $request->user() && array_intersect(['auth', 'auth.token'], $route?->gatherMiddleware() ?? []) !== []) {
            abort_unless(ModuleAccess::allows($request->user(), $module), 404);
        }

        return $next($request);
    }

    /**
     * The Livewire component name of an update request's first component, read from its snapshot
     * (same extraction as RecordSlowRequests); null outside Livewire update requests.
     */
    private function componentName(Request $request): ?string
    {
        $snapshot = $request->input('components.0.snapshot');

        return is_string($snapshot) ? data_get(json_decode($snapshot, true), 'memo.name') : null;
    }
}
