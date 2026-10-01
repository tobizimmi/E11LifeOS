<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripStop;
use App\Support\Life\TripItinerary;
use App\Support\Life\TripPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

        // Dragging the hint row of an empty day sends no entry and changes nothing.
        $page->call('moveStop', null, '2026-10-11', null)->assertOk()->assertHasNoErrors();
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
        Queue::fake(); // The places of the trip area have their own test (TripPlacesTest).
        Http::fake([
            'photon.komoot.io/*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [19.0457, 47.5071]], 'properties' => ['name' => 'Parlament', 'street' => 'Kossuth Lajos tér', 'housenumber' => '1-3', 'city' => 'Budapest', 'country' => 'Ungarn', 'osm_key' => 'tourism', 'osm_value' => 'attraction']],
                ['geometry' => ['coordinates' => [19.0457, 47.5072]], 'properties' => ['name' => 'Parlament', 'city' => 'Budapest', 'osm_key' => 'tourism', 'osm_value' => 'attraction']],
            ]]),
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
        $this->assertCount(1, $results);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'photon.komoot.io/api/') && $request['lat'] == 47.5);
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

    public function test_entries_without_an_address_are_found_at_the_trip_destination_and_failures_are_retried(): void
    {
        Queue::fake(); // The places of the trip area are not part of this test.
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::sequence()
            ->push('', 503)
            ->push([['name' => 'Park Güell', 'display_name' => 'Park Güell, Barcelona', 'lat' => '41.4145', 'lon' => '2.1527']])
            ->push([['name' => 'Park Güell', 'display_name' => 'Park Güell, Barcelona', 'lat' => '41.4145', 'lon' => '2.1527']]),
        ]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Barcelona', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        TripStop::factory()->create(['trip_id' => $trip->id, 'day' => null, 'title' => 'Park Güell', 'location' => null]);
        Cache::put('places:center:'.md5('Barcelona'), ['lat' => 41.39, 'lng' => 2.17]);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'map')->assertSee('1 Eintrag hat noch keinen Ort auf der Karte.')
            ->call('locateMissing')->assertDispatched('toast', message: 'Die Ortssuche (OpenStreetMap) ist gerade nicht erreichbar. Bitte versuche es später noch einmal.');
        $this->assertNull(TripStop::sole()->lat);

        // The failure was not remembered: the next try asks again and finds the place.
        $page->call('locateMissing');
        $this->assertSame(41.4145, TripStop::sole()->lat);

        $page->set('idea', 'Park Güell')->call('addIdea');
        $this->assertSame(2, TripStop::whereNotNull('lat')->count());
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'q=Park Güell, Barcelona'));
    }

    public function test_the_route_of_a_day_shows_the_way_between_the_places_and_follows_the_travel_mode(): void
    {
        Queue::fake();
        Http::fake(['routing.openstreetmap.de/*' => Http::response(['routes' => [[
            'duration' => 1500, 'distance' => 2100,
            'legs' => [['duration' => 600, 'distance' => 800], ['duration' => 900, 'distance' => 1300]],
            'geometry' => ['coordinates' => [[19.0402, 47.4979], [19.0450, 47.5000], [19.0511, 47.4968], [19.0620, 47.5010]]],
        ]]])]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-23']);
        foreach ([['Burg', 47.4979, 19.0402], ['Vörösmarty tér', 47.4968, 19.0511], ['Frici Papa', 47.5010, 19.0620]] as $index => [$title, $lat, $lng]) {
            TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-07-22', 'title' => $title, 'lat' => $lat, 'lng' => $lng, 'position' => $index + 1]);
        }

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'plan')->assertSee('Wegzeiten werden berechnet');
        Http::assertNothingSent();

        $page->call('loadRoutes')->assertSee('🚶 10 Min. · 800 m')->assertSee('🚶 15 Min. · 1,3 km')->assertSee('🚶 25 Min. · 2,1 km')->assertDontSee('Wegzeiten werden berechnet');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'routed-foot/route/v1/driving/19.0402,47.4979;19.0511,47.4968;19.062,47.501'));

        $groups = collect(Volt::test('trips.show', ['trip' => $trip])->set('tab', 'map')->viewData('mapGroups'))->keyBy('key');
        $this->assertCount(4, $groups['2026-07-22']['line']);
        $this->assertSame([null, '🚶 10 Min. · 800 m', '🚶 15 Min. · 1,3 km'], array_column($groups['2026-07-22']['pins'], 'leg'));

        $page->call('setTravelMode', 'car')->call('loadRoutes')->assertSee('🚗 25 Min. · 2,1 km');
        $this->assertSame('car', $trip->fresh()->travel_mode);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'routed-car/'));
        $page->call('setTravelMode', 'plane')->assertStatus(422);
    }

    public function test_each_way_can_have_its_own_travel_mode(): void
    {
        Queue::fake();
        Http::fake(['routing.openstreetmap.de/routed-foot/*' => Http::response(['routes' => [['duration' => 600, 'distance' => 800, 'legs' => [['duration' => 600, 'distance' => 800]], 'geometry' => ['coordinates' => [[19.0402, 47.4979], [19.0511, 47.4968]]]]]]),
            'routing.openstreetmap.de/routed-car/*' => Http::response(['routes' => [['duration' => 300, 'distance' => 2500, 'legs' => [['duration' => 300, 'distance' => 2500]], 'geometry' => ['coordinates' => [[19.0511, 47.4968], [19.0620, 47.5010]]]]]]),
        ]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-22', 'travel_mode' => 'foot']);
        $stops = collect([['Burg', 47.4979, 19.0402], ['Platz', 47.4968, 19.0511], ['Therme', 47.5010, 19.0620]])
            ->map(fn ($stop, $index) => TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-07-22', 'title' => $stop[0], 'lat' => $stop[1], 'lng' => $stop[2], 'position' => $index + 1]));

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'plan')
            ->call('cycleTravelMode', $stops[2]->id)->call('cycleTravelMode', $stops[2]->id);
        $this->assertSame('car', $stops[2]->fresh()->travel_mode);

        $page->call('loadRoutes')->assertSee('🚶 10 Min. · 800 m')->assertSee('🚗 5 Min. · 2,5 km')->assertSee('🧭 15 Min. · 3,3 km');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'routed-car/route/v1/driving/19.0511,47.4968;19.062,47.501'));

        // Back to the trip's mode: stored as "as set for the trip".
        $page->call('cycleTravelMode', $stops[2]->id);
        $this->assertNull($stops[2]->fresh()->travel_mode);

        $page->call('editStop', $stops[1]->id)->set('stop.travel_mode', 'bike')->call('saveStop')->assertHasNoErrors();
        $this->assertSame('bike', $stops[1]->fresh()->travel_mode);
        $page->call('editStop', $stops[1]->id)->set('stop.travel_mode', 'plane')->call('saveStop')->assertHasErrors('stop.travel_mode');
    }

    public function test_a_day_is_sorted_by_distance_and_its_times_follow_the_ways_between_the_places(): void
    {
        Http::fake(['routing.openstreetmap.de/*' => Http::response([], 503)]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-23']);
        // Planned zig-zag along one street: hotel (start), far end, middle, near.
        foreach ([['Hotel', 'accommodation', 47.5000], ['Weit', 'sight', 47.5300], ['Mitte', 'food', 47.5150], ['Nah', 'sight', 47.5050]] as $index => [$title, $kind, $lat]) {
            TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-07-22', 'kind' => $kind, 'title' => $title, 'time' => null, 'end_time' => null, 'lat' => $lat, 'lng' => 19.0400, 'position' => $index + 1]);
        }
        TripStop::factory()->create(['trip_id' => $trip->id, 'day' => '2026-07-22', 'kind' => 'note', 'title' => 'Ohne Ort', 'time' => null, 'end_time' => null, 'lat' => null, 'lng' => null, 'position' => 0]);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'plan')->call('optimizeDay', '2026-07-22');
        $order = fn () => TripItinerary::build($trip->fresh())['days'][0]['stops']->pluck('title')->all();
        $this->assertSame(['Hotel', 'Nah', 'Mitte', 'Weit', 'Ohne Ort'], $order());

        // Without a route the way is estimated (on foot about 4 km/h with detours).
        $page->call('scheduleDay', '2026-07-22');
        $times = TripStop::orderBy('position')->get()->mapWithKeys(fn (TripStop $stop) => [$stop->title => [$stop->time, $stop->end_time]])->all();
        $this->assertSame(['09:00', null], $times['Hotel']);
        $this->assertSame(['09:15', '10:45'], $times['Nah']);
        $this->assertSame(['11:10', '12:10'], $times['Mitte']);
        $this->assertSame(['12:45', '14:15'], $times['Weit']);
        $this->assertSame(['14:15', null], $times['Ohne Ort']);

        $page->call('scheduleDay', 'kein-tag')->assertStatus(422);
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
