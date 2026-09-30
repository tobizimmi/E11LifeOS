<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripStop;
use App\Support\Life\TripItinerary;
use App\Support\Life\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripItineraryTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_the_day_by_day_plan_combines_entries_bookings_and_ideas(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        $trip->bookings()->create(['kind' => 'hotel', 'title' => 'Hotel Arts', 'starts_at' => '2026-10-10 15:00', 'ends_at' => '2026-10-12 11:00']);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'plan')
            ->call('openStop', '2026-10-11')
            ->set('stop.kind', 'sight')->set('stop.title', 'Sagrada Família')->set('stop.time', '09:30')->set('stop.location', 'Carrer de Mallorca 401, Barcelona')->set('stop.cost', '26')->set('stop.notes', 'Tickets vorab')
            ->call('saveStop')->assertHasNoErrors()
            ->call('openStop', '2026-10-11')->set('stop.kind', 'food')->set('stop.title', 'Tapas')->set('stop.location', 'El Born, Barcelona')
            ->call('saveStop')->assertHasNoErrors()
            ->set('idea', 'Park Güell')->call('addIdea')
            ->assertSee('Sagrada Família')->assertSee('Park Güell')->assertSee('Check-in');

        $this->assertStringNotContainsString('Tickets vorab', (string) DB::table('trip_stops')->where('title', 'Sagrada Família')->value('notes'));

        $plan = TripItinerary::build($trip->fresh());
        $this->assertCount(3, $plan['days']);
        $this->assertSame(['Check-in'], array_column($plan['days'][0]['bookings'], 'label'));
        $this->assertSame(['Check-out'], array_column($plan['days'][2]['bookings'], 'label'));
        $this->assertSame(['Sagrada Família', 'Tapas'], $plan['days'][1]['stops']->pluck('title')->all());
        $this->assertSame(['Park Güell'], $plan['ideas']->pluck('title')->all());
        $this->assertStringContainsString('google.com/maps/dir/', (string) $plan['days'][1]['route']);
        $this->assertStringContainsString('origin=Carrer de Mallorca 401, Barcelona&destination=El Born, Barcelona', urldecode((string) $plan['days'][1]['route']));
        $this->assertSame(3, $plan['planned_days']);
        $this->assertSame(26.0, TripPlanner::overview($trip->fresh(), $user)['costs']);

        // Drag & drop: the idea goes to the first place of day 2, the rest moves down.
        $idea = TripStop::where('title', 'Park Güell')->sole();
        $page->call('moveStop', $idea->id, '2026-10-11', 0);
        $this->assertSame(['Park Güell', 'Sagrada Família', 'Tapas'], TripItinerary::build($trip->fresh())['days'][1]['stops']->pluck('title')->all());

        // …and back to the ideas.
        $page->call('moveStop', $idea->id, 'ideas', 0);
        $this->assertNull($idea->fresh()->day);
    }

    public function test_entries_can_be_edited_ticked_off_and_deleted_only_on_visible_trips(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        $stop = TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-10-10', 'title' => 'Strand']);
        [, $otherHousehold] = $this->memberWithHousehold();
        $foreignStop = TripStop::factory()->create(['trip_id' => Trip::factory()->create(['household_id' => $otherHousehold->id])->id]);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])
            ->call('editStop', $stop->id)->assertSet('stop.title', 'Strand')
            ->set('stop.title', 'Strand Barceloneta')->set('stop.time', '25:00')->call('saveStop')->assertHasErrors('stop.time')
            ->set('stop.time', '16:00')->call('saveStop')->assertHasNoErrors()
            ->call('toggleStopDone', $stop->id);

        $this->assertSame(['Strand Barceloneta', '16:00', true], [$stop->fresh()->title, $stop->fresh()->time, $stop->fresh()->done]);

        $page->call('editStop', $foreignStop->id)->assertNotFound();

        Volt::test('trips.show', ['trip' => $trip])->call('deleteStop', $stop->id);
        $this->assertModelMissing($stop);
    }

    public function test_a_cover_photo_is_stored_and_only_shown_to_people_who_see_the_trip(): void
    {
        Storage::fake('local');
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);

        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip])
            ->set('cover', UploadedFile::fake()->image('strand.jpg', 2400, 1600))
            ->assertHasNoErrors()
            ->call('setColor', 'sunset');

        $trip->refresh();
        $this->assertSame('sunset', $trip->color);
        Storage::disk('local')->assertExists($trip->cover_path);
        $this->get($trip->coverUrl())->assertOk();

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get($trip->coverUrl())->assertNotFound();

        $this->actingAs($user);
        $path = $trip->cover_path;
        Volt::test('trips.show', ['trip' => $trip])->call('removeCover');
        Storage::disk('local')->assertMissing($path);
    }
}
