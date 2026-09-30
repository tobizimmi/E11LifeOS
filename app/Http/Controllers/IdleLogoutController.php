<?php

namespace App\Http\Controllers;

use App\Support\Security\SecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs this browser out after the idle time (sent by the page itself). Other devices and their
 * "remember me" stay signed in.
 */
class IdleLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        SecurityLog::record($request->user(), 'idle_logout');
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('You were signed out after :minutes minutes without activity.', ['minutes' => config('security.idle_minutes')]));
    }
}
