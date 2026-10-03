<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://push.example.com/send/'.Str::random(40),
            'public_key' => WebPush::generateKeys()['publicKey'],
            'auth_token' => WebPush::base64Url(random_bytes(16)),
        ];
    }
}
