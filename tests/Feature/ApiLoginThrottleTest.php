<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * The app sign-in is limited per account, not per address.
 */
class ApiLoginThrottleTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_one_account_is_limited_but_others_on_the_same_address_are_not(): void
    {
        RateLimiter::clear('login|nobody@example.com');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(429);

        [$user] = $this->memberWithHousehold();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->assertOk();
    }
}
