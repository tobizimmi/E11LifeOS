<?php

namespace Tests\Feature;

use App\Jobs\LoadTripPlaces;
use App\Models\Trip;
use App\Models\TripPlace;
use App\Models\TripStop;
use App\Support\Life\MapHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripPlacesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function fakeMapServices(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([['name' => 'Budapest', 'display_name' => 'Budapest, Ungarn', 'lat' => '47.4979', 'lon' => '19.0402', 'boundingbox' => ['47.35', '47.61', '18.92', '19.33']]]),
            'overpass-api.de/*' => Http::response(['elements' => [
                ['type' => 'way', 'id' => 1, 'center' => ['lat' => 47.5071, 'lon' => 19.0457], 'tags' => ['name' => 'Országház', 'name:de' => 'Parlament', 'tourism' => 'attraction', 'historic' => 'building', 'opening_hours' => 'Mo-Su 08:00-18:00', 'website' => 'https://parlament.hu']],
                ['type' => 'node', 'id' => 2, 'lat' => 47.4968, 'lon' => 19.0511, 'tags' => ['name' => 'Café Gerbeaud', 'amenity' => 'cafe', 'cuisine' => 'coffee_shop;cake', 'addr:street' => 'Vörösmarty tér', 'addr:housenumber' => '7']],
                ['type' => 'node', 'id' => 3, 'lat' => 47.5186, 'lon' => 19.0823, 'tags' => ['name' => 'Széchenyi fürdő', 'leisure' => 'water_park']],
                ['type' => 'node', 'id' => 4, 'lat' => 47.50, 'lon' => 19.05, 'tags' => ['amenity' => 'cafe']],
            ]]),
            'photon.komoot.io/*' => Http::response(['features' => [
                ['geometry' => ['coordinates' => [19.0457, 47.5071]], 'properties' => ['name' => 'Parlament', 'city' => 'Budapest', 'osm_key' => 'tourism', 'osm_value' => 'attraction']],
                ['geometry' => ['coordinates' => [19.0350, 47.4960]], 'properties' => ['name' => 'Burgpalast Buda', 'city' => 'Budapest', 'osm_key' => 'historic', 'osm_value' => 'castle']],
            ]]),
        ]);
    }

    public function test_the_places_of_the_trip_area_are_loaded_in_the_background_and_found_at_once(): void
    {
        $this->fakeMapServices();
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Budapest', 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-24']);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'map');

        $trip->refresh();
        $this->assertNotNull($trip->places_loaded_at);
        $this->assertSame([47.35, 18.92, 47.61, 19.33], $trip->map_area);
        $this->assertSame(['Café Gerbeaud', 'Parlament', 'Széchenyi fürdő'], $trip->places()->orderBy('name')->pluck('name')->all());
        $cafe = TripPlace::where('name', 'Café Gerbeaud')->sole();
        $this->assertSame(['food', 'Vörösmarty tér 7', 'coffee shop, cake'], [$cafe->kind, $cafe->address, $cafe->info['cuisine']]);
        $this->assertSame('activity', TripPlace::where('name', 'Széchenyi fürdő')->value('kind'));
        $page->call('$refresh')->assertSee('3 Orte im Reisegebiet sind für die Suche bereit.');

        // The loaded places answer first and without any request; online results follow without duplicates.
        Http::fake();
        $local = $page->instance()->searchPlaces('parla', [47.45, 19.0, 47.55, 19.1], false);
        $this->assertSame([['Parlament', 'Mo-Su 08:00-18:00']], array_map(fn ($place) => [$place['name'], $place['info']['opening_hours']], $local));
        Http::assertNothingSent();

        $this->fakeMapServices();
        $all = $page->instance()->searchPlaces('Parlament', [47.45, 19.0, 47.55, 19.1]);
        $this->assertSame(['Parlament', 'Burgpalast Buda'], array_column($all, 'name'));

        // Places nearby come from the database inside the trip area.
        Http::fake();
        $this->assertSame(['Café Gerbeaud'], array_column($page->instance()->placesNearby([47.49, 19.04, 47.50, 19.06]), 'name'));
        Http::assertNothingSent();
    }

    public function test_the_trip_area_can_be_chosen_on_the_map_and_a_new_destination_loads_again(): void
    {
        Queue::fake();
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Budapest', 'places_loaded_at' => now(), 'map_area' => [47.35, 18.92, 47.61, 19.33]]);

        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip])->call('setMapArea', [46.80, 17.50, 47.10, 18.20])->assertDispatched('toast');
        $this->assertSame([46.8, 17.5, 47.1, 18.2], $trip->fresh()->map_area);
        $this->assertNull($trip->fresh()->places_loaded_at);
        Queue::assertPushed(LoadTripPlaces::class, fn (LoadTripPlaces $job) => $job->tripId === $trip->id);

        Volt::test('trips.show', ['trip' => $trip])->call('setMapArea', [47.1, 17.5, 46.8, 18.2])->assertStatus(422);

        Cache::flush();
        Volt::test('trips.show', ['trip' => $trip])->set('details.destination', 'Plattensee')->call('saveDetails');
        $this->assertNull($trip->fresh()->map_area);
        Queue::assertPushed(LoadTripPlaces::class, 2);
    }

    public function test_the_area_follows_the_planned_places_and_is_kept_to_a_sensible_size(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
        $trip = Trip::factory()->create(['destination' => 'Unbekannt']);
        TripStop::factory()->create(['trip_id' => $trip->id, 'lat' => 47.50, 'lng' => 19.04]);
        TripStop::factory()->create(['trip_id' => $trip->id, 'lat' => 47.51, 'lng' => 19.05]);

        $this->assertSame([47.465, 19.005, 47.545, 19.085], LoadTripPlaces::areaFor($trip));

        $trip->map_area = [30.0, -10.0, 60.0, 30.0];
        $this->assertSame([44.25, 9.25, 45.75, 10.75], LoadTripPlaces::areaFor($trip));
    }

    public function test_the_address_of_a_map_service_is_remembered(): void
    {
        config(['services.maps.remember_dns' => true]);
        Cache::put('map-dns:photon.komoot.io', '192.0.2.10');
        Http::fake();

        MapHttp::to('https://photon.komoot.io/api/')->get('https://photon.komoot.io/api/');

        Http::assertSent(fn ($request) => $request->url() === 'https://photon.komoot.io/api/');
        $this->assertSame('192.0.2.10', MapHttp::address('photon.komoot.io'));
        $this->assertNull(MapHttp::address('127.0.0.1'));
        $this->assertNull(MapHttp::address('fritz.box'));
        $this->assertNull(MapHttp::address('nas'));
    }
}
