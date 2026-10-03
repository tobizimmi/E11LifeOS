<?php

namespace App\Http\Controllers;

use App\Support\Security\SecurityLog;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs this browser out after the idle time (sent by the page itself). Other devices and their
 * "remember me" stay signed in, and a browser signed in with "remember me" is never signed out
 * for inactivity (the home-screen app should stay signed in, e.g. for push notifications).
 */
class IdleLogoutController extends Controller
{
    /**
     * Whether the idle timer runs for this browser: switched on and not signed in with "remember me".
     */
    public static function appliesTo(Request $request): bool
    {
        $guard = Auth::guard('web');

        return config('security.idle_minutes') > 0
            && ! ($guard instanceof SessionGuard && filled($request->cookies->get($guard->getRecallerName())));
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (! self::appliesTo($request)) {
            return redirect()->route('dashboard');
        }

        SecurityLog::record($request->user(), 'idle_logout');
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('You were signed out after :minutes minutes without activity.', ['minutes' => config('security.idle_minutes')]));
    }
}
