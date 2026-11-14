<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Security\ApiConfirmation;
use App\Support\Security\SecurityLog;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends ApiController
{
    /**
     * Signs in with e-mail (field "email" or, like the old app, "username") and password.
     */
    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => $request->input('email', $request->input('username'))]);
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            SecurityLog::record($user, 'login_failed');

            return response()->json(['success' => false, 'error' => __('auth.failed')], 422);
        }

        $needsSecondFactor = $user->hasSecondFactor() || config('security.require_two_factor');

        if ($needsSecondFactor && ! $user->hasTwoFactor()) {
            return response()->json(['success' => false, 'setup_required' => true, 'error' => __('Please set up two-factor authentication in the browser first.')], 403);
        }

        // With two-factor authentication the app has to send the code of the authenticator app too.
        if ($user->hasTwoFactor() && ! TwoFactor::verify($user, (string) ($data['code'] ?? ''))) {
            if (filled($data['code'] ?? null)) {
                SecurityLog::record($user, 'two_factor_failed');
            }

            return response()->json(['success' => false, 'two_factor_required' => true, 'error' => blank($data['code'] ?? null) ? __('Enter the 6-digit code from your authenticator app.') : __('The code is not valid.')], 422);
        }

        $token = ApiToken::issue($user, $data['device_name'] ?? 'App');
        SecurityLog::login($user, $request, 'api_login');

        return $this->ok(['token' => $token, 'user' => $this->present($user)]);
    }

    public function me(): JsonResponse
    {
        return $this->ok(['user' => $this->present($this->user())]);
    }

    /**
     * Confirms the password for sensitive areas (health, emergency folder) for 15 minutes.
     */
    public function confirmPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);
        $token = $request->attributes->get('api_token');
        abort_unless($token instanceof ApiToken, 401);

        // Only wrong passwords count: five of them per minute lock the check for a minute.
        $limiterKey = 'confirm-password:'.$this->user()->id;
        abort_if(RateLimiter::tooManyAttempts($limiterKey, 5), 429, __('Too many attempts. Please wait a moment.'));

        if (! Hash::check($data['password'], $this->user()->password)) {
            RateLimiter::hit($limiterKey, 60);
            SecurityLog::record($this->user(), 'password_confirmation_failed');

            return response()->json(['success' => false, 'error' => __('The password is incorrect.')], 422);
        }

        RateLimiter::clear($limiterKey);
        ApiConfirmation::confirm($token);

        return $this->ok(['expires_in' => ApiConfirmation::SECONDS]);
    }

    /**
     * Ends the confirmation early (the app calls this when it leaves a sensitive area).
     */
    public function forgetConfirmation(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        if ($token instanceof ApiToken) {
            ApiConfirmation::forget($token);
        }

        return $this->ok();
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        if ($token instanceof ApiToken) {
            ApiConfirmation::forget($token);
            $token->delete();
        }

        return $this->ok();
    }

    /**
     * @return array{id: int, username: string, name: string, email: string, role: string, portfolio_enabled: bool}
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->name,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'user',
            'portfolio_enabled' => $user->canUseAddon('portfolio'),
        ];
    }
}
