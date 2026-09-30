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
use Illuminate\Support\Facades\Http;
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
        Http::fake(['*' => Http::response([])]);
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

    public function test_places_are_found_on_the_map_and_added_to_a_day_with_their_position(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                ['name' => 'Parlament', 'display_name' => 'Parlament, Kossuth Lajos tér 1-3, Budapest, Ungarn', 'lat' => '47.5071', 'lon' => '19.0457', 'category' => 'tourism', 'type' => 'attraction'],
            ]),
            'nominatim.openstreetmap.org/reverse*' => Http::response(['name' => 'Frici Papa', 'display_name' => 'Frici Papa, Király utca 55, Budapest', 'lat' => '47.5010', 'lon' => '19.0620', 'category' => 'amenity', 'type' => 'restaurant']),
            'overpass-api.de/*' => Http::response(['elements' => [
                ['lat' => 47.4968, 'lon' => 19.0511, 'tags' => ['name' => 'Vörösmarty tér', 'tourism' => 'attraction']],
                ['lat' => 47.4979, 'lon' => 19.0402, 'tags' => ['amenity' => 'bench']],
            ]]),
        ]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Budapest', 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-24']);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'map');

        $results = $page->instance()->searchPlaces('Parlament', [47.4, 18.9, 47.6, 19.2]);
        $this->assertSame(['Parlament', 'Kossuth Lajos tér 1-3, Budapest, Ungarn', 'sight'], [$results[0]['name'], $results[0]['address'], $results[0]['kind']]);
        $this->assertSame('Frici Papa', $page->instance()->placeAt(47.501, 19.062)['name']);
        $this->assertSame(['Vörösmarty tér'], array_column($page->instance()->placesNearby([47.49, 19.03, 47.51, 19.07]), 'name'));
        $this->assertSame([], $page->instance()->placesNearby([40.0, 5.0, 50.0, 20.0]));

        $page->call('addPlace', $results[0], '2026-07-23')->assertHasNoErrors()
            ->call('addPlace', ['name' => 'Frici Papa', 'address' => '', 'lat' => 47.501, 'lng' => 19.062, 'kind' => 'food'], '2026-07-23')
            ->call('addPlace', ['name' => 'Therme', 'lat' => 47.518, 'lng' => 19.082], 'ideas');

        $groups = TripItinerary::mapGroups(TripItinerary::build($trip->fresh()));
        $this->assertSame(['ideas', '2026-07-22', '2026-07-23', '2026-07-24'], array_column($groups, 'key'));
        $this->assertSame(['Parlament', 'Frici Papa'], array_column($groups[2]['pins'], 'title'));
        $this->assertSame([1, 2], array_column($groups[2]['pins'], 'n'));
        $this->assertSame(TripItinerary::DAY_COLORS[1], $groups[2]['color']);
        $this->assertSame(['Therme'], array_column($groups[0]['pins'], 'title'));
        $this->assertSame(47.5071, TripStop::where('title', 'Parlament')->sole()->lat);

        $page->call('addPlace', ['name' => '', 'lat' => 100, 'lng' => 19], 'ideas')->assertHasErrors(['place.name', 'place.lat']);
    }

    public function test_a_typed_address_gets_its_map_position_and_a_changed_address_a_new_one(): void
    {
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::sequence()
            ->push([['name' => 'Fischerbastei', 'display_name' => 'Fischerbastei, Budapest', 'lat' => '47.5021', 'lon' => '19.0348']])
            ->push([['name' => 'Burg', 'display_name' => 'Burgpalast, Budapest', 'lat' => '47.4962', 'lon' => '19.0396']]),
        ]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-24']);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])
            ->call('openStop', '2026-07-22')->set('stop.title', 'Fischerbastei')->set('stop.location', 'Fischerbastei, Budapest')->call('saveStop')->assertHasNoErrors();

        $stop = TripStop::sole();
        $this->assertSame([47.5021, 19.0348], [$stop->lat, $stop->lng]);

        $page->call('editStop', $stop->id)->set('stop.location', 'Burgpalast, Budapest')->call('saveStop');
        $this->assertSame(47.4962, $stop->fresh()->lat);

        $page->call('editStop', $stop->id)->set('stop.location', '')->call('saveStop');
        $this->assertNull($stop->fresh()->lat);
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
