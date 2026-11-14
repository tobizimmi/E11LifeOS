<?php

namespace Tests\Feature;

use App\Models\EmergencyEntry;
use App\Models\HealthProfile;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Health and the emergency folder in the app: only after the password was confirmed for the token.
 */
class ApiSensitiveAreasTest extends TestCase
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

    public function test_the_areas_stay_closed_until_the_password_is_confirmed(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/health', $headers)->assertStatus(423)->assertJsonPath('confirmation_required', true);
        $this->getJson('/api/v1/emergency', $headers)->assertStatus(423);
        $this->postJson('/api/v1/health/profiles', ['name' => 'Ich'], $headers)->assertStatus(423);

        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'wrong'], $headers)->assertStatus(422);
        $this->getJson('/api/v1/health', $headers)->assertStatus(423);
        $this->assertTrue(SecurityEvent::where('user_id', $user->id)->where('event', 'password_confirmation_failed')->exists());

        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk()->assertJsonPath('data.expires_in', 900);
        $response = $this->getJson('/api/v1/health', $headers)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->getJson('/api/v1/emergency', $headers)->assertOk();
        $this->assertTrue(SecurityEvent::where('user_id', $user->id)->where('event', 'sensitive_area')->get()->contains(fn (SecurityEvent $event) => $event->details === 'health'));

        // The confirmation expires after 15 minutes and can be ended early.
        $this->travel(16)->minutes();
        $this->getJson('/api/v1/health', $headers)->assertStatus(423);

        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();
        $this->getJson('/api/v1/health', $headers)->assertOk();
        $this->deleteJson('/api/v1/auth/confirm-password', [], $headers)->assertOk();
        $this->getJson('/api/v1/health', $headers)->assertStatus(423);
    }

    public function test_wrong_passwords_are_limited(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/confirm-password', ['password' => 'nope'], $headers)->assertStatus(422);
        }

        // Even the right password waits until the minute is over.
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertStatus(429);
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();
    }

    public function test_a_confirmation_belongs_to_one_token_only(): void
    {
        [$user] = $this->memberWithHousehold();
        $first = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $first)->assertOk();

        $second = $this->headersFor($user);
        $this->getJson('/api/v1/health', $second)->assertStatus(423);
        $this->getJson('/api/v1/health', $first)->assertOk();
    }

    public function test_health_profiles_and_entries(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $profile = $this->postJson('/api/v1/health/profiles', ['name' => ' Ich ', 'birth_date' => '1990-05-01', 'sex' => 'f', 'blood_type' => 'A+', 'allergies' => 'Nüsse', 'doctors' => ''], $headers)
            ->assertCreated()->assertJsonPath('data.name', 'Ich')->assertJsonPath('data.blood_type', 'A+')->assertJsonPath('data.doctors', null)->json('data');
        $id = $profile['id'];
        $this->postJson('/api/v1/health/profiles', ['name' => 'X', 'blood_type' => 'Z+'], $headers)->assertStatus(422);

        $this->putJson("/api/v1/health/profiles/{$id}", ['name' => 'Ich', 'allergies' => 'Nüsse, Pollen'], $headers)->assertOk()->assertJsonPath('data.allergies', 'Nüsse, Pollen');

        $data = $this->postJson("/api/v1/health/profiles/{$id}/entries", ['kind' => 'medication', 'title' => 'Ibuprofen', 'dosage' => '400 mg', 'times' => '08:00, 20:00, 08:00'], $headers)->assertCreated()->json('data');
        $this->assertSame(['08:00', '20:00'], $data['entries'][0]['times']);
        $this->postJson("/api/v1/health/profiles/{$id}/entries", ['kind' => 'appointment', 'title' => 'Zahnarzt'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/health/profiles/{$id}/entries", ['kind' => 'medication', 'title' => 'X', 'times' => '8 Uhr'], $headers)->assertStatus(422);

        $data = $this->postJson("/api/v1/health/profiles/{$id}/entries", ['kind' => 'checkup', 'title' => 'Vorsorge', 'done_on' => today()->subMonths(11)->toDateString(), 'interval_months' => 12], $headers)->assertCreated()->json('data');
        $checkup = collect($data['entries'])->firstWhere('title', 'Vorsorge');
        $this->assertSame(today()->subMonths(11)->addMonthsNoOverflow(12)->toDateString(), $checkup['next_due_on']);

        $done = $this->postJson("/api/v1/health/profiles/{$id}/entries/{$checkup['id']}/done", [], $headers)->assertOk()->json('data');
        $this->assertSame(today()->toDateString(), collect($done['entries'])->firstWhere('id', $checkup['id'])['done_on']);
        $this->postJson("/api/v1/health/profiles/{$id}/entries/{$checkup['id']}/toggle", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/health/profiles/{$id}/entries/{$checkup['id']}", [], $headers)->assertOk()->assertJsonCount(1, 'data.entries');

        $this->getJson('/api/v1/health', $headers)->assertOk()->assertJsonCount(1, 'data.profiles');
        $this->deleteJson("/api/v1/health/profiles/{$id}", [], $headers)->assertOk();
        $this->assertNull(HealthProfile::find($id));
    }

    public function test_health_data_of_another_member_is_out_of_reach(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $foreign = HealthProfile::create(['user_id' => $partner->id, 'name' => 'Partner']);
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $this->getJson('/api/v1/health', $headers)->assertOk()->assertJsonCount(0, 'data.profiles');
        $this->putJson("/api/v1/health/profiles/{$foreign->id}", ['name' => 'X'], $headers)->assertNotFound();
        $this->postJson("/api/v1/health/profiles/{$foreign->id}/entries", ['kind' => 'checkup', 'title' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/health/profiles/{$foreign->id}", [], $headers)->assertNotFound();
    }

    public function test_the_emergency_folder_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $index = $this->getJson('/api/v1/emergency', $headers)->assertOk()->json('data');
        $this->assertSame('documents', $index['sections'][0]['key']);
        $this->assertContains('passport', array_column($index['sections'][0]['presets'], 'key'));

        $id = $this->postJson('/api/v1/emergency/entries', ['section' => 'documents', 'kind' => 'passport', 'title' => 'Reisepass', 'details' => 'Nr. 123', 'location' => 'Schrank', 'expires_on' => today()->addDays(20)->toDateString(), 'remind_days' => 60], $headers)
            ->assertCreated()->assertJsonPath('data.expires_soon', true)->assertJsonPath('data.expired', false)->json('data.id');
        $this->postJson('/api/v1/emergency/entries', ['section' => 'nope', 'title' => 'X'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/emergency/entries', ['section' => 'documents', 'kind' => 'unknown_kind', 'title' => 'X'], $headers)->assertStatus(422);

        $this->putJson("/api/v1/emergency/entries/{$id}", ['title' => 'Reisepass', 'expires_on' => today()->subDay()->toDateString()], $headers)->assertOk()->assertJsonPath('data.expired', true);
        $this->assertSame(1, count($this->getJson('/api/v1/emergency', $headers)->json('data.entries')));

        $foreign = EmergencyEntry::create(['user_id' => $partner->id, 'section' => 'documents', 'kind' => 'custom', 'title' => 'Fremd', 'remind_days' => 60, 'position' => 1]);
        $this->putJson("/api/v1/emergency/entries/{$foreign->id}", ['title' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/emergency/entries/{$foreign->id}", [], $headers)->assertNotFound();

        $this->deleteJson("/api/v1/emergency/entries/{$id}", [], $headers)->assertOk();
        $this->assertNull(EmergencyEntry::find($id));
    }
}
