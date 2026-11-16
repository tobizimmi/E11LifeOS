<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripPackingItem;
use App\Models\TripStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Creating and editing trips, packing templates and ticking off stops for the mobile app.
 */
class ApiTripExtrasTest extends TestCase
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

    public function test_a_trip_is_created_edited_packed_and_deleted(): void
    {
        Queue::fake();
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/trips/templates', $headers)->assertOk()->assertJsonFragment(['key' => 'basics']);
        $this->postJson('/api/v1/trips', ['title' => 'Rom', 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-01'], $headers)->assertUnprocessable();

        $id = $this->postJson('/api/v1/trips', ['title' => 'Rom', 'destination' => 'Rom, Italien', 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-05', 'template' => 'beach'], $headers)->assertCreated()->json('data.id');
        $this->assertGreaterThan(10, TripPackingItem::where('trip_id', $id)->count());

        $this->putJson("/api/v1/trips/{$id}", ['title' => 'Rom Städtetrip', 'destination' => 'Rom, Italien', 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-06', 'budget' => 800, 'private' => true], $headers)->assertOk();
        $trip = Trip::findOrFail($id);
        $this->assertSame('Rom Städtetrip', $trip->title);
        $this->assertSame('private', $trip->visibility);

        TripPackingItem::where('trip_id', $id)->update(['packed' => true]);
        $this->postJson("/api/v1/trips/{$id}/unpack", [], $headers)->assertOk();
        $this->assertSame(0, TripPackingItem::where('trip_id', $id)->where('packed', true)->count());
        $before = TripPackingItem::where('trip_id', $id)->count();
        $this->postJson("/api/v1/trips/{$id}/template", ['template' => 'basics'], $headers)->assertOk();
        $this->assertGreaterThanOrEqual($before, TripPackingItem::where('trip_id', $id)->count());

        $stop = TripStop::factory()->create(['trip_id' => $id, 'title' => 'Kolosseum']);
        $this->postJson("/api/v1/trip-stops/{$stop->id}/done", [], $headers)->assertOk()->assertJsonPath('data.done', true);

        $this->deleteJson("/api/v1/trips/{$id}", [], $headers)->assertOk();
        $this->assertNull(Trip::find($id));
    }

    public function test_foreign_trips_cannot_be_touched(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        [$other] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $owner->id]);
        $headers = $this->headersFor($other);

        $this->putJson("/api/v1/trips/{$trip->id}", ['title' => 'x', 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-02'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/trips/{$trip->id}", [], $headers)->assertNotFound();
    }
}
