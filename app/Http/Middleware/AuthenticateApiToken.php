<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "auth.token": signs the request in with a bearer token from the api_tokens table;
 * tokens unused for too long (security.api_token_idle_days) are deleted instead.
 * Shortcut tokens only reach the few routes that add something.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ? ApiToken::findByPlain($request->bearerToken()) : null;

        if ($token?->isExpired()) {
            $token->delete();
            $token = null;
        }

        if (! $token?->user) {
            return response()->json(['success' => false, 'error' => __('Please sign in again.')], 401);
        }

        if (! $token->allowsRoute($request->route()?->getName())) {
            return response()->json(['success' => false, 'error' => __('This shortcut can only add things.')], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        Auth::setUser($token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
