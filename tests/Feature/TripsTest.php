<?php

namespace Tests\Feature;

use App\Models\EmergencyEntry;
use App\Models\Item;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Notifications\LifeReminder;
use App\Support\CalendarEvents;
use App\Support\Life\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_a_trip_is_planned_with_bookings_budget_and_a_packing_list(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('trips.index')
            ->set('trip.title', 'Mallorca')->set('trip.destination', 'Palma')->set('trip.starts_on', '2026-10-10')->set('trip.ends_on', '2026-10-17')
            ->set('trip.budget', '2.000')->set('trip.template', 'beach')
            ->call('create')
            ->assertHasNoErrors();

        $trip = Trip::sole();
        $this->assertSame([2000.0, 7], [$trip->budget, $trip->nights()]);
        $this->assertTrue($trip->packingItems()->where('label', 'Sonnencreme')->exists());
        $this->assertTrue($trip->packingItems()->where('label', 'Personalausweis oder Reisepass')->exists());

        $page = Volt::test('trips.show', ['trip' => $trip])
            ->set('tab', 'bookings')
            ->set('booking.kind', 'flight')->set('booking.title', 'FRA → PMI LH1234')->set('booking.starts_at', '2026-10-10T07:15')
            ->set('booking.reference', 'X7Y8Z9')->set('booking.cost', '489,90')->set('booking.paid', true)
            ->call('saveBooking')->assertHasNoErrors()
            ->set('booking.kind', 'hotel')->set('booking.title', 'Finca Sol')->set('booking.cost', '1.650')
            ->call('saveBooking')->assertHasNoErrors()
            ->assertSee('Code X7Y8Z9');

        $this->assertStringNotContainsString('X7Y8Z9', (string) DB::table('trip_bookings')->value('reference'));

        $overview = TripPlanner::overview($trip->fresh(), $user);
        $this->assertSame([12, 2139.9, 1650.0, -139.9], [$overview['days_until'], $overview['costs'], $overview['open'], $overview['budget_left']]);

        $page->set('tab', 'packing')->set('template', 'kids')->set('templatePerson', 'Mia')->call('applyTemplate')
            ->set('item.label', 'Schnorchel')->set('item.category', 'other')->call('addItem');
        $mia = $trip->packingItems()->where('person', 'Mia')->where('label', 'Windeln & Feuchttücher')->sole();
        $page->call('togglePacked', $mia->id);
        $this->assertTrue($mia->fresh()->packed);
        $this->assertSame(0, TripPlanner::applyTemplate($trip, 'kids', 'Mia'));
    }

    public function test_expiring_documents_warn_and_trip_dates_are_reminded_and_in_the_calendar(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['reminder_emails' => true]);
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'USA', 'destination' => 'New York', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-19']);
        TripBooking::factory()->create(['trip_id' => $trip->id, 'kind' => 'flight', 'title' => 'FRA → JFK', 'starts_at' => '2026-10-05 10:30']);
        EmergencyEntry::create(['user_id' => $user->id, 'section' => 'identity', 'kind' => 'passport', 'title' => 'Reisepass Tobi', 'expires_on' => '2027-02-01']);
        EmergencyEntry::create(['user_id' => $user->id, 'section' => 'identity', 'kind' => 'id_card', 'title' => 'Ausweis Tobi', 'expires_on' => '2026-10-12']);

        $warnings = collect(TripPlanner::overview($trip, $user)['warnings'])->keyBy('level');
        $this->assertStringContainsString('Ausweis Tobi läuft am 12.10.2026 ab', $warnings['critical']['text']);
        $this->assertStringContainsString('Reisepass Tobi ist nach der Rückkehr weniger als sechs Monate gültig', $warnings['warning']['text']);

        $this->artisan('life:reminders')->assertSuccessful();
        Notification::assertSentTo($user, LifeReminder::class, fn (LifeReminder $notification) => collect($notification->events)->contains(fn (array $event) => str_contains($event['title'], 'Reise nach New York beginnt')));

        $events = CalendarEvents::between($user, $household->id, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), [CalendarEvents::LIFE]);
        $titles = collect($events)->where('date', '2026-10-05')->pluck('title')->all();
        $this->assertContains('✈️ 10:30 FRA → JFK', $titles);
        $this->assertContains('🏠 Rückreise aus New York', collect($events)->where('date', '2026-10-19')->pluck('title')->all());
    }

    public function test_private_trips_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Überraschung', 'visibility' => Item::VISIBILITY_PRIVATE]);

        $this->actingAs($partner);
        Volt::test('trips.index')->assertDontSee('Überraschung');
        Volt::test('trips.show', ['trip' => $trip])->assertNotFound();
    }
}
