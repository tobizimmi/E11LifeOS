<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the admin requires two-factor authentication, signed-in members without it are sent
 * to their profile to set it up (the profile, Livewire requests and signing out still work).
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('security.require_two_factor') || ! $user || $user->hasTwoFactor() || $request->is('api/*')
            || $request->routeIs('profile', 'logout', 'verification.*', 'password.*', 'two-factor.*') || $request->is('livewire/*', '*/livewire/*')) {
            return $next($request);
        }

        return redirect()->route('profile')->with('two-factor-required', true);
    }
}
