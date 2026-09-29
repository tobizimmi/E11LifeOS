<?php

namespace Tests\Feature;

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Notifications\LifeReminder;
use App\Support\CalendarEvents;
use App\Support\Life\EmergencyFolder;
use App\Support\Life\HealthPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_an_emergency_card_is_kept_encrypted_and_shown_in_the_emergency_folder(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('health.index')
            ->assertSet('isCreating', true)
            ->set('person.name', 'Tobi')->set('person.birth_date', '1986-05-10')->set('person.sex', 'm')->set('person.blood_type', 'A+')->set('person.allergies', 'Penicillin')
            ->call('saveProfile')
            ->assertHasNoErrors()
            ->assertSee('Notfallkarte')
            ->assertSee('Penicillin')
            ->set('tab', 'medications')
            ->set('entry.title', 'Ibuprofen')->set('entry.dosage', '400 mg')->set('entry.times', '08:00, 20:00')
            ->call('addEntry')
            ->assertHasNoErrors()
            ->set('entry.title', 'Falsch')->set('entry.times', '8 Uhr')->call('addEntry')->assertHasErrors('entry.times');

        $profile = HealthProfile::sole();
        $this->assertSame('Penicillin', $profile->allergies);
        $this->assertStringNotContainsString('Penicillin', (string) DB::table('health_profiles')->value('allergies'));
        $this->assertSame(['08:00', '20:00'], HealthEntry::sole()->times);

        $health = collect(EmergencyFolder::overview($user))->firstWhere('key', 'health');
        $this->assertStringContainsString('Blutgruppe A+', $health['rows'][0]['detail']);
        $this->assertStringContainsString('Ibuprofen 400 mg', $health['rows'][0]['detail']);

        $this->actingAs($this->addMemberTo($user->currentHousehold()));
        Volt::test('health.index', ['profileId' => $profile->id])->assertDontSee('Penicillin');
    }

    public function test_check_ups_follow_age_and_sex_and_are_reminded(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['reminder_emails' => true]);
        $father = HealthProfile::factory()->create(['user_id' => $user->id, 'name' => 'Papa', 'birth_date' => '1960-01-01', 'sex' => 'm']);
        $baby = HealthProfile::factory()->create(['user_id' => $user->id, 'name' => 'Mia', 'birth_date' => '2026-07-01', 'sex' => 'f']);

        $fatherKeys = array_column(HealthPlan::suggestions($father), 'key');
        $this->assertContains('prostate', $fatherKeys);
        $this->assertContains('shingles', $fatherKeys);
        $this->assertNotContains('mammography', $fatherKeys);
        $babyPlan = collect(HealthPlan::suggestions($baby))->keyBy('key');
        $this->assertSame(['dentist', 'u4', 'u5', 'u6'], $babyPlan->keys()->all());
        $this->assertSame('2026-10-01', $babyPlan['u4']['due_on']);

        $this->actingAs($user);
        $page = Volt::test('health.index', ['profileId' => $baby->id, 'tab' => 'prevention'])->assertSee('U4')->call('adopt', 'u4');
        $this->assertSame('2026-10-01', HealthEntry::where('key', 'u4')->sole()->next_due_on->toDateString());
        $page->assertDontSee('Zur Planung hinzufügen: U4');

        $dentist = HealthEntry::factory()->create(['health_profile_id' => $father->id, 'title' => 'Zahnarzt', 'interval_months' => 6, 'next_due_on' => '2026-10-12']);
        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => str_contains($notification->events[0]['title'], 'Zahnarzt · Papa'));

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [CalendarEvents::LIFE]);
        $this->assertSame(['2026-10-01', '2026-10-12'], array_column($events, 'date'));

        Volt::test('health.index', ['profileId' => $father->id, 'tab' => 'prevention'])->call('done', $dentist->id);
        $this->assertSame('2027-03-28', $dentist->fresh()->next_due_on->toDateString());
    }

    public function test_medications_are_reminded_at_their_times(): void
    {
        [$user] = $this->memberWithHousehold();
        $profile = HealthProfile::factory()->create(['user_id' => $user->id, 'name' => 'Tobi']);
        HealthEntry::factory()->medication(['10:00', '22:00'])->create(['health_profile_id' => $profile->id]);
        HealthEntry::factory()->medication(['10:00'])->create(['health_profile_id' => $profile->id, 'title' => 'Pausiert', 'active' => false]);

        $this->artisan('health:medication-reminders')->expectsOutput('Medication reminders: 1')->assertSuccessful();
        $this->assertSame('💊 Zeit für Ibuprofen', $user->notifications()->sole()->data['title']);

        $this->travelTo(Carbon::parse('2026-09-28 10:01'));
        $this->artisan('health:medication-reminders')->expectsOutput('Medication reminders: 0');
    }
}
