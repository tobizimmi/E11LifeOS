<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripPlace;
use App\Models\TripStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripDiscoverTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_places_are_shown_by_category_can_be_planned_and_ticked_off_as_visited(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Budapest', 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-24', 'places_loaded_at' => now()]);
        $place = fn (string $name, string $category, array $info = []) => TripPlace::factory()->create(['trip_id' => $trip->id, 'name' => $name, 'kind' => in_array($category, ['restaurant', 'cafe'], true) ? 'food' : 'sight', 'category' => $category, 'info' => $info ?: null]);
        $plain = $place('Kleines Lokal', 'restaurant');
        $gerbeaud = $place('Café Gerbeaud', 'cafe', ['cuisine' => 'coffee shop']);
        $costes = $place('Costes', 'restaurant', ['cuisine' => 'french', 'menu' => 'https://costes.hu/menu', 'opening_hours' => 'Tu-Sa 18:30-24:00', 'image' => 'https://example.com/costes.jpg']);
        $parliament = $place('Parlament', 'attraction', ['wikipedia' => 'de:Parlamentsgebäude (Budapest)']);

        $this->actingAs($user);
        $page = Volt::test('trips.show', ['trip' => $trip])->set('tab', 'discover')
            ->assertSee('Restaurants')
            // The best described restaurant comes first, with menu, opening hours and picture.
            ->assertSeeInOrder(['Costes', 'Kleines Lokal'])
            ->assertSee('https://costes.hu/menu')
            ->assertSee('Tu-Sa 18:30-24:00')
            ->assertSee('https://example.com/costes.jpg')
            // Without a menu, a search for it is offered.
            ->assertSee('Speisekarte suchen')
            ->assertDontSee('Café Gerbeaud')
            ->call('planDiscovered', $costes->id)
            ->call('toggleVisited', $plain->id)
            ->assertDontSee('Kleines Lokal')
            ->set('showVisited', true)->assertSee('Kleines Lokal')
            ->set('discoverCategory', 'cafes')->assertSee('Café Gerbeaud')
            ->set('discoverCategory', 'sights')->assertSee('Parlament')
            ->assertSee(route('trips.place-image', [$trip->id, $parliament->id]));

        $this->assertSame(['Costes'], TripStop::where('trip_id', $trip->id)->pluck('title')->all());
        $this->assertSame([$plain->osm_id], $trip->fresh()->visited_places);

        // Visited places survive when the places of the area load again (same OpenStreetMap id).
        TripPlace::whereKey($plain->id)->delete();
        TripPlace::factory()->create(['trip_id' => $trip->id, 'osm_id' => $plain->osm_id, 'name' => 'Kleines Lokal', 'kind' => 'food', 'category' => 'restaurant']);
        $page->set('showVisited', false)->set('discoverCategory', 'restaurants')->assertDontSee('Kleines Lokal');

        // The Wikipedia picture is looked up once and cached.
        Http::fake(['de.wikipedia.org/*' => Http::response(['thumbnail' => ['source' => 'https://upload.wikimedia.org/parlament.jpg']])]);
        $this->get(route('trips.place-image', [$trip->id, $parliament->id]))->assertRedirect('https://upload.wikimedia.org/parlament.jpg');
        $this->get(route('trips.place-image', [$trip->id, $parliament->id]))->assertRedirect('https://upload.wikimedia.org/parlament.jpg');
        Http::assertSentCount(1);

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get(route('trips.place-image', [$trip->id, $parliament->id]))->assertNotFound();
        $this->assertSame($gerbeaud->trip_id, $trip->id);
    }

    public function test_destination_tips_and_the_plan_by_category_with_visited_places(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Budapest, Ungarn', 'starts_on' => '2026-07-22', 'ends_on' => '2026-07-24', 'places_loaded_at' => now()]);
        TripPlace::factory()->create(['trip_id' => $trip->id, 'name' => 'Fischerbastei', 'kind' => 'sight', 'category' => 'attraction']);
        TripPlace::factory()->create(['trip_id' => $trip->id, 'name' => 'Costes', 'kind' => 'food', 'category' => 'restaurant', 'info' => ['cuisine' => 'french']]);
        $stop = fn (string $title, string $kind, ?string $day) => TripStop::create(['trip_id' => $trip->id, 'title' => $title, 'kind' => $kind, 'day' => $day, 'position' => 0, 'created_by' => $user->id]);
        $goulash = $stop('Gulaschhaus', 'food', '2026-07-22');
        $stop('Ruinenbar', 'food', null);
        $castle = $stop('Burgpalast', 'sight', '2026-07-23');
        Http::fake(['de.wikipedia.org/*' => Http::response(['type' => 'standard', 'title' => 'Budapest', 'extract' => 'Budapest ist die Hauptstadt Ungarns.', 'content_urls' => ['desktop' => ['page' => 'https://de.wikipedia.org/wiki/Budapest']]])]);

        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip])
            ->assertSee('Tipps für Budapest, Ungarn')->assertDontSee('Budapest ist die Hauptstadt Ungarns.')
            ->call('openTips')
            ->assertSee('Budapest ist die Hauptstadt Ungarns.')
            ->assertSee('Sehenswert')->assertSee('Fischerbastei')->assertSee('Restaurants &amp; Cafés', false)->assertSee('Costes')
            ->set('tab', 'plan')
            ->call('filterPlan', 'food')
            ->assertSeeHtml('kind-stop-'.$goulash->id)->assertSee('Ruinenbar')->assertDontSeeHtml('kind-stop-'.$castle->id)
            ->assertSee('0 besucht')
            ->call('toggleStopDone', $goulash->id)
            ->assertSee('1 besucht')
            ->call('filterPlan', 'unknown')->assertSet('planKind', null)->assertDontSeeHtml('kind-stop-');

        $this->assertTrue($goulash->fresh()->done);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/page/summary/Budapest'));
    }
}
