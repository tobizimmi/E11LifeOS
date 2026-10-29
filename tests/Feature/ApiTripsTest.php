<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripPackingItem;
use App\Models\TripStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Read-only trips API that lets the app show a trip (itinerary, places, packing, bookings) offline.
 */
class ApiTripsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_place_names_in_foreign_scripts_are_transliterated_to_latin(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);
        TripStop::factory()->create(['trip_id' => $trip->id, 'title' => 'Χαμουζάς', 'location' => 'Δήμος Ζακύνθου']);

        $stop = $this->getJson("/api/v1/trips/{$trip->id}", $headers)->assertOk()->json('data.stops.0');

        $this->assertDoesNotMatchRegularExpression('/\p{Greek}/u', (string) $stop['title']);
        $this->assertDoesNotMatchRegularExpression('/\p{Greek}/u', (string) ($stop['location'] ?? ''));
    }

    public function test_trips_are_listed_and_shown_with_their_itinerary(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Rom']);
        TripStop::factory()->create(['trip_id' => $trip->id, 'title' => 'Kolosseum', 'day' => '2026-05-01']);

        $this->getJson('/api/v1/trips', $headers)->assertOk()->assertJsonPath('data.0.title', 'Rom');
        $this->getJson("/api/v1/trips/{$trip->id}", $headers)->assertOk()
            ->assertJsonPath('data.title', 'Rom')
            ->assertJsonPath('data.stops.0.title', 'Kolosseum');
    }

    public function test_trips_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $foreign = Trip::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id]);

        $this->getJson("/api/v1/trips/{$foreign->id}", $headers)->assertNotFound();
        $this->getJson('/api/v1/trips', $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_itinerary_packing_and_bookings_can_be_edited(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);

        $stopId = $this->postJson("/api/v1/trips/{$trip->id}/stops", ['title' => 'Louvre', 'day' => '2026-05-02'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Louvre')->json('data.id');
        $this->putJson("/api/v1/trip-stops/{$stopId}", ['done' => true], $headers)->assertOk()->assertJsonPath('data.done', true);

        $packId = $this->postJson("/api/v1/trips/{$trip->id}/packing", ['label' => 'Pass'], $headers)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/trip-packing/{$packId}", ['packed' => true], $headers)->assertOk()->assertJsonPath('data.packed', true);

        $bookingId = $this->postJson("/api/v1/trips/{$trip->id}/bookings", ['title' => 'Hotel', 'kind' => 'hotel'], $headers)->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/trip-stops/{$stopId}", [], $headers)->assertOk();
        $this->assertNull(TripStop::find($stopId));
        $this->assertNotNull(TripPackingItem::find($packId));
        $this->assertNotNull(TripBooking::find($bookingId));
    }

    public function test_editing_a_foreign_trips_items_is_blocked(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $foreign = Trip::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id]);
        $stop = TripStop::factory()->create(['trip_id' => $foreign->id]);

        $this->postJson("/api/v1/trips/{$foreign->id}/stops", ['title' => 'X'], $headers)->assertNotFound();
        $this->putJson("/api/v1/trip-stops/{$stop->id}", ['title' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/trip-stops/{$stop->id}", [], $headers)->assertNotFound();
    }
}
