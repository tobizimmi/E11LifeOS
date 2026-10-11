<?php

namespace App\Http\Middleware;

use App\Support\Security\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes in the security log that health data, the emergency folder or online banking was opened.
 */
class LogSensitiveAccess
{
    /**
     * Sensitive area per Livewire component, for an update request whose matched route does not
     * carry the "sensitive.log:…" parameter – e.g. the component is embedded in another page's
     * route (see EnsureModuleAllowed for the same fallback situation).
     *
     * @var array<string, string>
     */
    private const COMPONENT_AREAS = [
        'emergency.index' => 'emergency',
        'health.index' => 'health',
        'portfolio.banking' => 'banking',
    ];

    public function handle(Request $request, Closure $next, ?string $area = null): Response
    {
        $area ??= self::COMPONENT_AREAS[$this->componentName($request) ?? ''] ?? null;

        $response = $next($request);

        if ($area !== null && $request->user() && $response->isSuccessful()) {
            SecurityLog::sensitiveArea($request->user(), $area);
        }

        return $response;
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
