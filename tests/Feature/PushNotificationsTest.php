<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

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

    public function test_a_push_wakes_every_device_with_a_valid_vapid_signature_and_drops_expired_ones(): void
    {
        [$user] = $this->memberWithHousehold();
        PushSubscription::factory()->for($user)->create(['endpoint' => self::ENDPOINT]);
        PushSubscription::factory()->for($user)->create(['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/gone']);

        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);

        $this->assertSame(1, app(WebPush::class)->send($user, 'Hallo', 'Text', 'https://example.test/x'));

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::ENDPOINT) {
                return false;
            }

            preg_match('/^vapid t=([^,]+), k=(.+)$/', $request->header('Authorization')[0], $match);
            [$header, $claims, $signature] = explode('.', $match[1]);

            return $request->body() === ''
                && $match[2] === config('services.webpush.public_key')
                && json_decode(WebPush::base64UrlDecode($claims), true)['aud'] === 'https://fcm.googleapis.com'
                && $this->signatureIsValid($header.'.'.$claims, WebPush::base64UrlDecode($signature), WebPush::base64UrlDecode($match[2]));
        });

        $this->assertSame([self::ENDPOINT], PushSubscription::pluck('endpoint')->all());
        $this->assertDatabaseHas('push_messages', ['user_id' => $user->id, 'title' => 'Hallo', 'delivered_at' => null]);
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
        $this->assertSame(route('collections.show', $tasks), $message->url);
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
