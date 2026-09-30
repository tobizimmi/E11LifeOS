<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\Passkeys\AuthenticationOptions;
use App\Support\Security\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * Sign-in with a passkey (Face ID, fingerprint, security key): either instead of the password,
 * or as the second step after the password for members who use passkeys or two-factor codes.
 */
class PasskeyLoginController extends Controller
{
    public function options(AuthenticationOptions $options): JsonResponse
    {
        $pending = $this->pendingUser();
        $credentialIds = $pending ? $pending->passkeys->map(fn (Passkey $passkey) => $passkey->data->publicKeyCredentialId)->all() : [];

        return response()->json(json_decode($options->execute($credentialIds), true));
    }

    public function login(Request $request, FindPasskeyToAuthenticateAction $findPasskey): JsonResponse
    {
        $request->validate(['credential' => ['required', 'string', 'max:20000']]);
        $key = 'passkey-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['message' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key), 'minutes' => ceil(RateLimiter::availableIn($key) / 60)])], 429);
        }

        $options = Session::pull('passkey-authentication-options');
        $passkey = $options ? rescue(fn () => $findPasskey->execute($request->input('credential'), $options), null, false) : null;
        $user = $passkey?->authenticatable;
        $pending = $this->pendingUser();

        if (! $user instanceof User || ($pending && $pending->id !== $user->id)) {
            RateLimiter::hit($key);
            SecurityLog::record($pending, 'two_factor_failed', $request, 'passkey');

            return response()->json(['message' => __('This passkey could not be verified. Please try again or sign in with your password.')], 422);
        }

        RateLimiter::clear($key);
        $remember = $pending ? (bool) session('login.remember') : $request->boolean('remember');
        Auth::login($user, $remember);
        session()->forget(['login.id', 'login.remember', 'login.expires']);
        Session::regenerate();
        SecurityLog::login($user, $request, 'login_passkey');

        return response()->json(['redirect' => redirect()->intended(route('dashboard'))->getTargetUrl()]);
    }

    /**
     * The member who entered the right password and still has to confirm the second step.
     */
    private function pendingUser(): ?User
    {
        if (! session('login.id') || session('login.expires', 0) < now()->timestamp) {
            return null;
        }

        return User::find(session('login.id'));
    }
}
