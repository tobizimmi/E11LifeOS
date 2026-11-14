<?php

namespace App\Http\Middleware;

use App\Support\Security\ApiConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sensitive areas (health, emergency folder) answer the app only after the password was confirmed
 * for the token within the last 15 minutes – the app counterpart of the web's "password.confirm".
 * Answers are never cached.
 */
class RequireApiConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token || ! ApiConfirmation::isConfirmed($token)) {
            return response()->json(['success' => false, 'error' => __('Please confirm your password to open this area.'), 'confirmation_required' => true], 423)
                ->header('Cache-Control', 'no-store');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
