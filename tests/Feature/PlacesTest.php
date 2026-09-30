<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Place;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PlacesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_place_is_saved_visited_rated_and_filtered(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('places.index')->call('create')->set('place.name', 'Trattoria Roma')->set('place.cuisine', 'Italienisch')
            ->set('place.city', 'München')->set('place.address', 'Leopoldstr. 1')->set('place.price_level', '2')->set('place.recommended_by', 'Anna')
            ->call('save')->assertHasNoErrors()->assertSee('Trattoria Roma')->assertSee('Italienisch · €€ · München')->assertSee('Tipp von Anna')->assertSee('Will ich hin');

        $place = Place::sole();
        $this->assertStringContainsString('query=Trattoria%20Roma%20Leopoldstr.%201%20M%C3%BCnchen', $place->mapsUrl());

        Volt::test('places.index')->call('markVisited', $place->id)->call('rate', $place->id, 5);
        $place->refresh();
        $this->assertTrue($place->visited);
        $this->assertSame(5, $place->rating);
        $this->assertSame(1, $place->visit_count);

        Place::factory()->create(['household_id' => $household->id, 'name' => 'Café Kranzler', 'category' => 'cafe', 'city' => 'Berlin']);
        Volt::test('places.index')->call('filterBy', 'status', 'favourites')->assertSee('Trattoria Roma')->assertDontSee('Café Kranzler')
            ->call('filterBy', 'status', 'favourites')->call('filterBy', 'city', 'Berlin')->assertSee('Café Kranzler')->assertDontSee('Trattoria Roma')
            ->call('suggest')->assertSet('suggestedId', Place::where('name', 'Café Kranzler')->value('id'));
    }

    public function test_places_of_a_trip_are_shown_on_the_trip(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'title' => 'Rom']);
        Place::factory()->create(['household_id' => $household->id, 'name' => 'Pantheon', 'category' => 'sight', 'trip_id' => $trip->id]);

        $this->actingAs($user)->get(route('trips.show', $trip))->assertOk()->assertSee('Orte für diese Reise')->assertSee('Pantheon');
    }

    public function test_private_places_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        Place::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Geheimtipp', 'visibility' => Item::VISIBILITY_PRIVATE]);
        $shared = Place::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Zur Linde']);

        $this->actingAs($partner);
        $this->get(route('places.index'))->assertOk()->assertSee('Zur Linde')->assertDontSee('Geheimtipp');
        Volt::test('places.index')->call('edit', Place::where('name', 'Geheimtipp')->value('id'))->assertNotFound();
    }
}
