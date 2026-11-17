<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
