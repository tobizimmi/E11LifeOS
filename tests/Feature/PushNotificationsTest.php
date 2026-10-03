<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\PortfolioSetting;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Support\Portfolio\Notifier;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\Concerns\DecryptsWebPush;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use CreatesHouseholdMembers, DecryptsWebPush, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $keys = WebPush::generateKeys();
        config([
            'services.webpush.public_key' => $keys['publicKey'],
            'services.webpush.private_key' => $keys['privateKey'],
            'services.webpush.subject' => 'mailto:test@example.com',
        ]);
        Http::preventStrayRequests();
    }

    public function test_a_device_can_be_registered_and_removed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $this->actingAs($partner)->postJson(route('push.subscribe'), ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => 'key', 'auth' => 'secret']])->assertCreated();
        // The same browser, now used by someone else, moves to that person.
        $this->actingAs($user)->postJson(route('push.subscribe'), ['endpoint' => self::ENDPOINT])->assertCreated();

        $this->assertSame([$user->id], PushSubscription::pluck('user_id')->all());

        $this->actingAs($partner)->deleteJson(route('push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame(1, PushSubscription::count());
        $this->actingAs($user)->deleteJson(route('push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_only_known_push_services_are_accepted_as_endpoints(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        foreach (['http://fcm.googleapis.com/fcm/send/x', 'https://127.0.0.1/push', 'https://evil.example/fcm.googleapis.com'] as $endpoint) {
            $this->postJson(route('push.subscribe'), ['endpoint' => $endpoint])->assertUnprocessable();
        }

        $this->postJson(route('push.subscribe'), ['endpoint' => 'https://web.push.apple.com/QGuQyavXutnMH'])->assertCreated();
    }

    public function test_a_push_carries_the_encrypted_text_with_a_valid_vapid_signature_and_drops_expired_devices(): void
    {
        [$user] = $this->memberWithHousehold();
        [$device, $keys] = $this->deviceWithKeys($user, self::ENDPOINT);
        PushSubscription::factory()->for($user)->create(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/gone']);

        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);

        $this->assertSame(1, app(WebPush::class)->send($user, 'Hallo', 'Text', 'https://example.test/x'));

        Http::assertSent(function (Request $request) use ($keys) {
            if ($request->url() !== self::ENDPOINT) {
                return false;
            }

            preg_match('/^vapid t=([^,]+), k=(.+)$/', $request->header('Authorization')[0], $match);
            [$header, $claims, $signature] = explode('.', $match[1]);
            $shown = json_decode($this->decryptPush($request->body(), $keys), true)['messages'][0];

            return $request->header('Content-Encoding')[0] === 'aes128gcm'
                && $request->header('Urgency')[0] === 'normal'
                && [$shown['title'], $shown['body'], $shown['url']] === ['Hallo', 'Text', 'https://example.test/x']
                && $match[2] === config('services.webpush.public_key')
                && json_decode(WebPush::base64UrlDecode($claims), true)['aud'] === 'https://fcm.googleapis.com'
                && $this->signatureIsValid($header.'.'.$claims, WebPush::base64UrlDecode($signature), WebPush::base64UrlDecode($match[2]));
        });

        // The expired device is gone; the text arrived with the push, so nothing waits for the service worker.
        $this->assertSame([self::ENDPOINT], PushSubscription::pluck('endpoint')->all());
        $this->assertNotNull($device->fresh()->last_success_at);
        $this->assertNotNull(PushMessage::where('title', 'Hallo')->sole()->delivered_at);
    }

    public function test_the_encryption_matches_the_rfc_8291_example(): void
    {
        $body = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            WebPush::base64UrlDecode('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            WebPush::base64UrlDecode('BTBZMqHH6r4Tts7J_aSIgg'),
            ['publicKey' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', 'privateKey' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'],
            WebPush::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::base64Url($body));
    }

    public function test_reminders_go_out_with_high_priority_and_devices_without_keys_fetch_the_text(): void
    {
        [$user] = $this->memberWithHousehold();
        PushSubscription::factory()->for($user)->create(['endpoint' => self::ENDPOINT, 'public_key' => null, 'auth_token' => null]);
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

        app(WebPush::class)->send($user, '💊 Ibuprofen', 'Jetzt', urgent: true);

        Http::assertSent(fn (Request $request) => $request->body() === '' && $request->header('Urgency')[0] === 'high' && ! $request->hasHeader('Content-Encoding'));
        $this->actingAs($user)->getJson(route('push.pending'))->assertJsonPath('messages.0.title', '💊 Ibuprofen');
    }

    public function test_the_profile_lists_each_device_with_its_push_service_and_last_delivery(): void
    {
        [$user] = $this->memberWithHousehold();
        $iphone = PushSubscription::factory()->for($user)->create([
            'endpoint' => 'https://web.push.apple.com/QGuQyavXutnMH',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        ]);
        $old = PushSubscription::factory()->for($user)->create(['endpoint' => self::ENDPOINT]);
        Http::fake([
            'web.push.apple.com/*' => Http::response('', 201),
            'fcm.googleapis.com/*' => Http::response(['reason' => 'BadJwtToken'], 403),
        ]);
        $this->actingAs($user);

        Volt::test('profile.push-notifications-form')
            ->call('sendTest')->assertSee('An 1 Gerät gesendet.')
            ->assertSee('iPhone · Safari')->assertSee('über Apple')->assertSee('Text verschlüsselt im Push')->assertSee('Zuletzt zugestellt')
            ->assertSee('Letzter Fehler: HTTP 403: BadJwtToken')
            ->call('removeDevice', $old->id);

        $this->assertSame([$iphone->id], PushSubscription::pluck('id')->all());
    }

    public function test_all_notifications_can_go_to_pushover_as_second_channel_without_doubling_portfolio_ones(): void
    {
        [$user] = $this->memberWithHousehold();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
        $this->actingAs($user);

        Volt::test('profile.push-notifications-form')->set('pushoverAll', true)->call('savePushover')->assertHasErrors('pushoverAll');
        PortfolioSetting::create(['user_id' => $user->id, 'pushover_user_key' => 'u-key', 'pushover_app_token' => 'a-token']);
        Volt::test('profile.push-notifications-form')->set('pushoverAll', true)->call('savePushover')->assertHasNoErrors();
        $user->refresh();

        app(WebPush::class)->send($user, '⏰ Zahnarzt', 'Jetzt fällig', urgent: true);
        app(Notifier::class)->send($user, '📈 Kursalarm', 'Apple über 200 $');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['user'] === 'u-key' && $request['title'] === '⏰ Zahnarzt' && $request['priority'] === 1);
        Http::assertSent(fn (Request $request) => $request['title'] === '📈 Kursalarm' && $request['priority'] === 0);
    }

    public function test_the_service_worker_gets_each_message_once_and_only_its_own(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        PushMessage::create(['user_id' => $user->id, 'title' => 'Für mich', 'body' => 'Text', 'url' => null]);
        PushMessage::create(['user_id' => $partner->id, 'title' => 'Für Partner', 'body' => 'Text']);

        $this->actingAs($user)->getJson(route('push.pending'))
            ->assertOk()
            ->assertJsonPath('messages.0.title', 'Für mich')
            ->assertJsonPath('messages.0.url', route('dashboard'))
            ->assertJsonCount(1, 'messages');

        $this->actingAs($user)->getJson(route('push.pending'))->assertJsonCount(0, 'messages');
    }

    public function test_daily_reminders_are_pushed_to_devices_even_without_reminder_emails(): void
    {
        Notification::fake();
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        [$user, $household] = $this->memberWithHousehold();
        PushSubscription::factory()->for($user)->create(['endpoint' => self::ENDPOINT]);
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->todo()->create(['household_id' => $household->id, 'title' => 'Müll rausbringen', 'created_by' => $user->id]);
        $task->setPropertyValue($tasks->systemProperty('due'), now()->toDateString());
        $task->save();

        $this->artisan('reminders:send')->expectsOutput('Reminders sent: 0, pushed: 1')->assertSuccessful();

        Notification::assertNothingSent();
        $message = PushMessage::sole();
        $this->assertSame('1 Aufgabe ist fällig', $message->title);
        $this->assertSame('• Müll rausbringen', $message->body);
        $this->assertSame(route('tasks.index'), $message->url);
    }

    public function test_the_profile_explains_when_push_is_not_set_up(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user)->get(route('profile'))->assertSee('Push-Benachrichtigungen')->assertSee('pushSettings');

        config(['services.webpush.public_key' => null]);
        $this->actingAs($user)->get(route('profile'))->assertSee('VAPID')->assertDontSee('pushSettings');
    }

    /**
     * Verifies an ES256 (raw r || s) signature with the raw P-256 public key.
     */
    private function signatureIsValid(string $data, string $signature, string $publicKey): bool
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$publicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
        $integer = function (string $bytes) {
            $bytes = ltrim($bytes, "\x00");
            $bytes = ord($bytes[0]) > 0x7F ? "\x00".$bytes : $bytes;

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $sequence = $integer(substr($signature, 0, 32)).$integer(substr($signature, 32));

        return strlen($signature) === 64 && openssl_verify($data, "\x30".chr(strlen($sequence)).$sequence, $pem, OPENSSL_ALGO_SHA256) === 1;
    }
}
