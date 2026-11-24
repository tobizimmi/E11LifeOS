<?php

namespace Tests\Feature;

use App\Enums\NotificationCategory;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Support\NotificationTopics;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Notification settings per topic and push to the native app (APNs).
 */
class ApiNotificationSettingsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function configureApns(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        config(['services.apns' => ['key_id' => 'KEY123', 'team_id' => 'TEAM123', 'bundle_id' => 'de.e11lifeos.app', 'key' => $pem, 'key_path' => null]]);
        Cache::forget('apns:jwt');
    }

    public function test_topics_are_read_from_the_link(): void
    {
        $this->assertSame('shopping', NotificationTopics::fromUrl(route('shopping-list.index')));
        $this->assertSame('portfolio', NotificationTopics::fromUrl(route('portfolio.index')));
        $this->assertSame('mail', NotificationTopics::fromUrl(route('notifications.index'), NotificationCategory::Mail));
        $this->assertSame('other', NotificationTopics::fromUrl(route('dashboard')));
        $this->assertSame('other', NotificationTopics::fromUrl(null));
    }

    public function test_settings_are_saved_per_topic(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/notification-settings', $headers)->assertOk()->assertJsonPath('data.topics.0.key', 'mail')->assertJsonPath('data.topics.0.level', 'all')->assertJsonPath('data.server_ready', false);
        $this->putJson('/api/v1/notification-settings', ['topics' => ['shopping' => 'bell', 'finance' => 'off', 'emergency' => 'off'], 'quiet_from' => 22, 'quiet_until' => 7, 'bundle' => false], $headers)->assertOk();
        $user->refresh();
        $this->assertSame('bell', NotificationTopics::level($user, 'shopping'));
        $this->assertSame('off', NotificationTopics::level($user, 'finance'));
        $this->assertSame('bell', NotificationTopics::level($user, 'emergency'));
        $this->assertSame([22, 7, false], [$user->push_quiet_from, $user->push_quiet_until, $user->push_bundle]);
        $this->putJson('/api/v1/notification-settings', ['topics' => ['shopping' => 'loud']], $headers)->assertStatus(422);
        $this->putJson('/api/v1/notification-settings', ['quiet_from' => null], $headers)->assertOk();
        $this->assertNull($user->refresh()->push_quiet_from);
    }

    public function test_the_choice_decides_between_push_bell_and_nothing(): void
    {
        $this->configureApns();
        Http::fake(['api.push.apple.com/*' => Http::response('', 200)]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/push/devices', ['token' => str_repeat('ab', 32), 'name' => 'iPhone'], $headers)->assertCreated()->assertJsonPath('data.server_ready', true)->assertJsonCount(1, 'data.devices');
        $webPush = app(WebPush::class);

        $this->assertSame(1, $webPush->send($user, 'Milch fehlt', 'Bitte kaufen', route('shopping-list.index')));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/3/device/'.str_repeat('ab', 32)) && $request->hasHeader('authorization') && $request['aps']['alert']['title'] === 'Milch fehlt');
        $this->assertNotNull(DevicePushToken::first()->last_success_at);

        NotificationTopics::save($user, ['shopping' => 'bell']);
        Http::fake();
        $this->assertSame(0, $webPush->send($user->refresh(), 'Noch Milch', '', route('shopping-list.index')));
        Http::assertNothingSent();
        $this->assertSame(2, $user->notifications()->count());

        NotificationTopics::save($user, ['shopping' => 'off']);
        $webPush->send($user->refresh(), 'Still', '', route('shopping-list.index'));
        $this->assertSame(2, $user->notifications()->count());
    }

    public function test_devices_apple_rejects_are_removed(): void
    {
        $this->configureApns();
        Http::fake(['api.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);
        [$user] = $this->memberWithHousehold();
        DevicePushToken::create(['user_id' => $user->id, 'token' => str_repeat('cd', 32)]);

        app(WebPush::class)->send($user, 'Test', 'x');
        $this->assertSame(0, DevicePushToken::count());
    }

    public function test_a_device_belongs_to_one_member_and_can_be_removed(): void
    {
        [$first] = $this->memberWithHousehold();
        [$second] = $this->memberWithHousehold();
        $token = str_repeat('ef', 32);
        $this->postJson('/api/v1/push/devices', ['token' => $token], $this->headersFor($first))->assertCreated();
        $this->postJson('/api/v1/push/devices', ['token' => $token], $this->headersFor($second))->assertCreated();
        $this->assertSame($second->id, DevicePushToken::sole()->user_id);
        $this->postJson('/api/v1/push/devices', ['token' => 'zu kurz!'], $this->headersFor($second))->assertStatus(422);
        $this->deleteJson('/api/v1/push/devices/'.DevicePushToken::sole()->id, [], $this->headersFor($first))->assertOk();
        $this->assertSame(1, DevicePushToken::count());
        $this->deleteJson('/api/v1/push/devices/'.DevicePushToken::sole()->id, [], $this->headersFor($second))->assertOk();
        $this->assertSame(0, DevicePushToken::count());
    }
}
