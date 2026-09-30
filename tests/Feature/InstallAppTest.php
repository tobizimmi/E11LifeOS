<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_installation_gets_push_keys_and_an_invite_for_the_first_account(): void
    {
        config(['services.webpush.public_key' => '', 'services.webpush.private_key' => '']);

        $this->artisan('app:install')->assertSuccessful();

        $invite = HouseholdInvite::query()->sole();
        $this->assertNull($invite->household_id);
        $this->assertTrue($invite->expires_at->isFuture());
        $this->assertNotSame('', (string) AppSetting::where('key', 'vapid_private_key')->value('value'));
        $this->assertNotEmpty(config('services.webpush.public_key'));
    }

    public function test_running_it_again_keeps_push_keys_and_creates_no_invite_once_accounts_exist(): void
    {
        config(['services.webpush.public_key' => 'bestehend', 'services.webpush.private_key' => 'bestehend']);
        User::factory()->create(['is_admin' => true]);

        $this->artisan('app:install')->assertSuccessful();

        $this->assertSame(0, HouseholdInvite::query()->count());
        $this->assertFalse(AppSetting::where('key', 'vapid_public_key')->exists());
        $this->assertSame('bestehend', config('services.webpush.public_key'));
    }

    public function test_it_stops_without_an_app_key(): void
    {
        config(['app.key' => '']);

        $this->artisan('app:install')->assertFailed();
    }
}
