<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\User;
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
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['success' => false, 'error' => __('auth.failed')], 422);
        }

        $token = ApiToken::issue($user, $data['device_name'] ?? 'App');

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
            'portfolio_enabled' => (bool) $user->currentHousehold()?->hasAddon('portfolio'),
        ];
    }
}
