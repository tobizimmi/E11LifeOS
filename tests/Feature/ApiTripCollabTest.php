<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Weather, group comments/suggestions and day sorting of a trip for the mobile app.
 */
class ApiTripCollabTest extends TestCase
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

    public function test_comments_and_suggestions_are_handled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-05']);
        $headers = $this->headersFor($user);

        $this->postJson("/api/v1/trips/{$trip->id}/feedback", ['text' => 'Wir brauchen Tickets'], $headers)->assertCreated()->assertJsonPath('data.text', 'Wir brauchen Tickets');
        $suggestion = TripFeedback::create(['trip_id' => $trip->id, 'author_name' => 'Gast', 'type' => TripFeedback::SUGGESTION, 'title' => 'Museum', 'status' => TripFeedback::PENDING]);

        $this->getJson("/api/v1/trips/{$trip->id}/feedback", $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/trip-feedback/{$suggestion->id}/approve", ['day' => '2026-12-02'], $headers)->assertOk();
        $this->assertSame('Museum', TripStop::where('trip_id', $trip->id)->firstOrFail()->title);
        $this->postJson("/api/v1/trip-feedback/{$suggestion->id}/decline", [], $headers)->assertUnprocessable();

        $comment = TripFeedback::where('type', TripFeedback::COMMENT)->firstOrFail();
        $this->deleteJson("/api/v1/trip-feedback/{$comment->id}", [], $headers)->assertOk();
        $this->assertNull(TripFeedback::find($comment->id));
    }

    public function test_weather_is_empty_outside_the_forecast_range_and_days_can_be_sorted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => now()->addDays(60)->toDateString(), 'ends_on' => now()->addDays(64)->toDateString()]);
        $headers = $this->headersFor($user);

        $this->getJson("/api/v1/trips/{$trip->id}/weather", $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/trips/{$trip->id}/days/2026-12-01/optimize", [], $headers)->assertOk()->assertJsonPath('data.sorted', 0);
        $this->postJson("/api/v1/trips/{$trip->id}/days/kaputt/optimize", [], $headers)->assertUnprocessable();

        [$other] = $this->memberWithHousehold();
        $this->getJson("/api/v1/trips/{$trip->id}/feedback", $this->headersFor($other))->assertNotFound();
    }

    public function test_routes_travel_modes_and_times_of_a_day(): void
    {
        Http::fake(['routing.openstreetmap.de/*' => Http::response(['routes' => [[
            'duration' => 1500, 'distance' => 2100,
            'legs' => [['duration' => 600, 'distance' => 800], ['duration' => 900, 'distance' => 1300]],
            'geometry' => ['coordinates' => [[19.0402, 47.4979], [19.0511, 47.4968], [19.0620, 47.5010]]],
        ]]])]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-23']);
        $stops = collect([['Burg', 47.4979, 19.0402], ['Platz', 47.4968, 19.0511], ['Therme', 47.5010, 19.0620]])
            ->map(fn (array $stop, int $index) => TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-07-22', 'kind' => 'sight', 'title' => $stop[0], 'time' => null, 'end_time' => null, 'lat' => $stop[1], 'lng' => $stop[2], 'position' => $index + 1]));
        $headers = $this->headersFor($user);

        $this->getJson("/api/v1/trips/{$trip->id}/routes", $headers)->assertOk()->assertJsonPath('data.travel_mode', 'foot')->assertJsonCount(0, 'data.days')->assertJsonPath('data.pending.0', '2026-07-22');
        $this->getJson("/api/v1/trips/{$trip->id}/routes?fetch=1", $headers)->assertOk()
            ->assertJsonPath('data.days.0.duration', 1500)->assertJsonPath('data.days.0.legs.0.duration', 600)->assertJsonPath('data.days.0.legs.1.distance', 1300);

        $this->postJson("/api/v1/trips/{$trip->id}/days/2026-07-22/schedule", [], $headers)->assertOk()->assertJsonPath('data.scheduled', 3);
        $this->assertSame('09:00', substr((string) $stops[0]->fresh()->time, 0, 5));
        $this->assertNotNull($stops[2]->fresh()->time);

        $this->putJson("/api/v1/trips/{$trip->id}/travel-mode", ['mode' => 'car'], $headers)->assertOk();
        $this->assertSame('car', $trip->fresh()->travel_mode);
        $this->putJson("/api/v1/trips/{$trip->id}/travel-mode", ['mode' => 'plane'], $headers)->assertUnprocessable();
        $this->putJson("/api/v1/trip-stops/{$stops[1]->id}/travel-mode", ['mode' => 'bike'], $headers)->assertOk()->assertJsonPath('data.travel_mode', 'bike');
        $this->putJson("/api/v1/trip-stops/{$stops[1]->id}/travel-mode", ['mode' => 'car'], $headers)->assertOk()->assertJsonPath('data.travel_mode', null);
    }
}
