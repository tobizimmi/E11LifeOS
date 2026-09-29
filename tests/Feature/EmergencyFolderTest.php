<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\EmergencyEntry;
use App\Models\TrustedPerson;
use App\Notifications\EmergencyAccessUpdate;
use App\Notifications\LifeReminder;
use App\Notifications\TrustedPersonInvitation;
use App\Support\CalendarEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class EmergencyFolderTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_entries_are_stored_encrypted_and_count_towards_the_checklist(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Girokonto', 'iban' => 'DE02120300000000202051']);

        Volt::test('emergency.index', ['section' => 'directives'])
            ->assertSee('Vorsorgevollmacht')
            ->call('add', 'directives', 'power_of_attorney')
            ->assertSet('entry.title', 'Vorsorgevollmacht')
            ->set('entry.details', 'Bevollmächtigt: Anna Muster')
            ->set('entry.location', 'Ordner „Vorsorge“ im Arbeitszimmer')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Ordner „Vorsorge“ im Arbeitszimmer');

        $entry = EmergencyEntry::sole();
        $this->assertSame('Bevollmächtigt: Anna Muster', $entry->details);
        $this->assertStringNotContainsString('Anna Muster', (string) DB::table('emergency_entries')->value('details'));

        Volt::test('emergency.index')
            ->assertSee('1 von 28 Punkten')
            ->assertSee('Girokonto')
            ->assertSee('DE02120300000000202051');

        $this->get(route('emergency.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->addMemberTo($household));
        Volt::test('emergency.index')->call('edit', $entry->id)->assertNotFound();
    }

    public function test_expiring_documents_are_reminded_and_shown_in_the_calendar(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['reminder_emails' => true]);
        EmergencyEntry::create(['user_id' => $user->id, 'section' => 'documents', 'kind' => 'passport', 'title' => 'Reisepass', 'expires_on' => '2026-11-27', 'remind_days' => 60]);

        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => str_contains($notification->events[0]['title'], 'Reisepass läuft ab'));
        $this->assertStringContainsString('Reisepass', $user->notifications()->first()->data['body']);

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), [CalendarEvents::LIFE]);
        $this->assertSame(['2026-11-27'], array_column($events, 'date'));

        $this->actingAs($user);
        Volt::test('emergency.index')->assertSee('Bald erneuern')->assertSee('gültig bis 27.11.2026');
    }

    public function test_a_trusted_person_gets_access_only_after_the_waiting_period(): void
    {
        Notification::fake();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        EmergencyEntry::create(['user_id' => $user->id, 'section' => 'wishes', 'kind' => 'pets_care', 'title' => 'Versorgung der Haustiere', 'details' => 'Kater Max geht zu Oma']);

        Volt::test('emergency.index', ['section' => 'access'])
            ->set('person.name', 'Anna Muster')->set('person.email', 'Anna@Example.com')->set('person.wait_days', '3')
            ->call('addPerson')
            ->assertHasNoErrors()
            ->assertSee('Anna Muster');

        $url = null;
        Notification::assertSentTo(new AnonymousNotifiable, TrustedPersonInvitation::class, function (TrustedPersonInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $notification->url;

            return $notifiable->routes['mail'] === 'anna@example.com';
        });
        $token = basename((string) $url);
        $person = TrustedPerson::sole();
        $this->assertNotSame($token, $person->token_hash);

        auth()->logout();
        $page = Volt::test('emergency.access', ['token' => $token])
            ->assertSee('Notfallordner von')
            ->assertDontSee('Kater Max geht zu Oma')
            ->set('email', 'someone@example.com')->call('requestAccess')->assertHasErrors('email')
            ->set('email', 'anna@example.com')->call('requestAccess')
            ->assertSee('Zugriff angefragt');
        Notification::assertSentTo($user, EmergencyAccessUpdate::class, fn (EmergencyAccessUpdate $notification) => $notification->event === EmergencyAccessUpdate::REQUESTED);

        $this->artisan('emergency:grant-access');
        $this->assertFalse($person->fresh()->hasAccess());

        $this->travel(3)->days();
        $this->artisan('emergency:grant-access');
        $this->assertTrue($person->fresh()->hasAccess());

        Volt::test('emergency.access', ['token' => $token])->assertSee('Kater Max geht zu Oma');
        $this->get(route('emergency.shared-pdf', ['token' => $token]))->assertOk();
        $this->assertNotNull($person->fresh()->last_viewed_at);

        $this->actingAs($user);
        Volt::test('emergency.index', ['section' => 'access'])->call('decline', $person->id);
        auth()->logout();
        Volt::test('emergency.access', ['token' => $token])->assertDontSee('Kater Max geht zu Oma');
        $this->get(route('emergency.shared-pdf', ['token' => $token]))->assertNotFound();
        $this->get(route('emergency.access', ['token' => str_repeat('a', 48)]))->assertNotFound();
    }
}
