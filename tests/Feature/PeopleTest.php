<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\GiftIdea;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Notifications\LifeReminder;
use App\Support\CalendarEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_birthdays_are_shown_reminded_and_in_the_calendar(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['reminder_emails' => true]);
        $this->actingAs($user);

        Volt::test('people.index')
            ->call('add')
            ->set('contact.name', 'Oma Erna')->set('contact.relation', 'Oma')->set('contact.birthday', '1946-10-05')
            ->set('contact.anniversary', '1970-06-12')->set('contact.anniversary_label', 'Hochzeitstag')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Nächste Geburtstage')
            ->assertSee('wird 80')
            ->assertSee('in 7 Tagen');

        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => str_contains($notification->events[0]['title'], 'Oma Erna wird 80'));

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-10-01'), Carbon::parse('2027-06-30'), [CalendarEvents::LIFE]);
        $this->assertSame(['2026-10-05', '2027-06-12'], array_column($events, 'date'));
        $this->assertSame('💍 Hochzeitstag · Oma Erna (57)', $events[1]['title']);

        $this->assertSame('2027-02-28', Contact::nextOccurrence(Carbon::parse('2000-02-29'), Carbon::parse('2027-01-01'))->toDateString());
    }

    public function test_gift_ideas_go_on_the_shopping_list_and_private_people_stay_hidden(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $person = Contact::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Lena']);
        Contact::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Geheim', 'visibility' => Item::VISIBILITY_PRIVATE]);
        $this->actingAs($user);

        $page = Volt::test('people.index', ['personId' => $person->id])
            ->set('gift.title', 'Kochbuch')->set('gift.price', '25')->set('gift.occasion', 'Geburtstag')
            ->call('addGift')
            ->assertHasNoErrors()
            ->assertSee('Kochbuch');

        $gift = GiftIdea::sole();
        $page->call('giftToShopping', $gift->id);
        $entry = ShoppingListEntry::sole();
        $this->assertSame(['Kochbuch', 'Geschenk für Lena', 25.0], [$entry->label, $entry->note, (float) $entry->price]);

        $page->call('setGiftStatus', $gift->id, 'given')->call('contacted', $person->id);
        $this->assertSame(['given', '2026-09-28'], [$gift->fresh()->status, $gift->fresh()->given_on->toDateString()]);
        $this->assertSame('2026-09-28', $person->fresh()->last_contact_on->toDateString());

        $this->actingAs($partner);
        Volt::test('people.index')->assertSee('Lena')->assertDontSee('Geheim');
        Volt::test('people.index')->call('setGiftStatus', $gift->id, 'stolen')->assertNotFound();
    }
}
