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
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $response = $next($request);

        if ($request->user() && $response->isSuccessful()) {
            SecurityLog::sensitiveArea($request->user(), $area);
        }

        return $response;
    }
}
