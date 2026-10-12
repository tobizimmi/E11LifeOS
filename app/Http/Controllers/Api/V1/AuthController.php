<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Security\SecurityLog;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('api_token')?->delete();

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
