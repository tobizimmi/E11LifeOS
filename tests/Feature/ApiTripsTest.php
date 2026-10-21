<?php

namespace Tests\Feature;

use App\Models\Trip;
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

    public function test_trips_are_listed_and_shown_with_their_itinerary(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Rom']);
        TripStop::factory()->create(['trip_id' => $trip->id, 'title' => 'Kolosseum', 'day' => 1]);

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
}
