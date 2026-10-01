<?php

namespace Tests\Feature;

use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripParticipant;
use App\Models\TripStop;
use App\Support\Life\ExpenseSplitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class GroupTripTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function trip(int $householdId): Trip
    {
        $trip = Trip::factory()->create(['household_id' => $householdId, 'title' => 'Vereinsausflug Bodensee', 'destination' => 'Lindau', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-11', 'travelers' => 'Anna, Ben']);
        TripBooking::create(['trip_id' => $trip->id, 'kind' => 'train', 'title' => 'Zug nach Lindau', 'starts_at' => '2026-10-10 07:45', 'reference' => 'ABC123']);

        return $trip;
    }

    public function test_participants_get_personal_links_and_whatsapp_messages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = $this->trip($household->id);
        $this->actingAs($user);

        Volt::test('trips.group', ['trip' => $trip->id])->call('importTravelers')->call('addParticipants')
            ->set('names', 'Carla; anna')->call('addParticipants')
            ->assertSee('Anna')->assertSee('Carla')->assertSee('Noch keine Rückmeldung');
        $this->assertSame(['Anna', 'Ben', 'Carla'], $trip->participants()->pluck('name')->all());

        $anna = $trip->participants()->where('name', 'Anna')->sole();
        Volt::test('trips.group', ['trip' => $trip->id])->call('edit', $anna->id)->set('participant.phone', '0171 1234567')
            ->set('participant.personal_info', 'Zimmer 12 mit Ben')->call('saveParticipant')->assertHasNoErrors()
            ->assertSee('https://wa.me/491711234567?text=', false)
            ->assertSee('Zug nach Lindau');
        $this->assertSame('491711234567', $anna->fresh()->whatsappNumber());
    }

    public function test_a_participant_replies_on_the_personal_page_and_sees_their_costs(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = $this->trip($household->id);
        $anna = TripParticipant::create(['trip_id' => $trip->id, 'name' => 'Anna', 'share_token' => 'annatoken1234567890', 'personal_info' => 'Zimmer 12']);
        $group = ExpenseGroup::factory()->create(['household_id' => $household->id, 'trip_id' => $trip->id]);
        $a = $group->members()->create(['name' => 'Anna']);
        $b = $group->members()->create(['name' => 'Ben']);
        $expense = $group->expenses()->create(['paid_by' => $b->id, 'title' => 'Hotel', 'amount' => 200, 'spent_on' => today(), 'split_mode' => 'equal']);
        ExpenseSplitter::saveShares($expense, [$a->id => 1, $b->id => 1]);

        $this->get(route('trips.participant', 'annatoken1234567890'))->assertOk()
            ->assertSee('Hallo Anna')->assertSee('Zimmer 12')->assertSee('Du schuldest noch 100,00 €')
            ->assertSee('Zug nach Lindau')->assertDontSee('ABC123');

        $this->post(route('trips.participant.reply', 'annatoken1234567890'), ['status' => 'confirmed', 'reply' => 'Komme mit dem Rad'])
            ->assertRedirect(route('trips.participant', 'annatoken1234567890'));
        $this->assertSame('confirmed', $anna->fresh()->status);
        $this->assertSame('Komme mit dem Rad', $anna->fresh()->reply);

        $this->get(route('trips.participant', 'wrongtoken123'))->assertNotFound();
    }

    public function test_the_trip_folder_needs_link_and_password(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = $this->trip($household->id);
        $this->actingAs($user);

        Volt::test('trips.group', ['trip' => $trip->id])->set('folder.meeting_point', 'Vereinsheim 7:30')->set('folder.share_references', true)->call('saveFolder')
            ->set('password', 'kurz')->call('createFolderLink')->assertHasErrors(['password'])
            ->set('password', 'Bodensee2026')->call('createFolderLink')->assertHasNoErrors();

        $token = $trip->fresh()->share_token;
        $this->assertNotNull($token);
        auth()->logout();

        $this->get(route('trips.folder', $token))->assertOk()->assertSee('Passwort')->assertDontSee('Vereinsheim');
        $this->post(route('trips.folder.unlock', $token), ['password' => 'falsch'])->assertSessionHasErrors('password');
        $this->post(route('trips.folder.unlock', $token), ['password' => 'Bodensee2026'])->assertRedirect(route('trips.folder', $token));
        $this->get(route('trips.folder', $token))->assertOk()->assertSee('Vereinsheim 7:30')->assertSee('ABC123');

        $trip->fresh()->update(['share_expires_at' => now()->subDay()]);
        $this->get(route('trips.folder', $token))->assertNotFound();
    }

    public function test_the_trip_folder_shows_the_day_plan_ideas_pages_and_links(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = $this->trip($household->id);
        TripStop::create(['trip_id' => $trip->id, 'day' => '2026-10-10', 'time' => '10:00', 'kind' => 'sight', 'title' => 'Hafen Lindau', 'location' => 'Seepromenade', 'notes' => 'Treffpunkt am Löwen']);
        TripStop::create(['trip_id' => $trip->id, 'kind' => 'food', 'title' => 'Eis am See']);
        $page = Item::factory()->note()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Packtipps', 'content' => ['body' => "Bitte **Regenjacke** und [[Proviant]] mitnehmen\n:::callout {type=\"tip\"}\nHinweis\n:::"]]);
        $private = Item::factory()->note()->privateTo($user)->create(['household_id' => $household->id, 'title' => 'Geheime Notiz']);
        $trip->update(['share_token' => 'foldertoken1234567890']);
        $this->actingAs($user);

        Volt::test('trips.group', ['trip' => $trip->id])
            ->set('pageSearch', 'Pack')->assertSee('Packtipps')
            ->call('addPage', $page->id)
            ->set('newLink.url', 'kein-link')->call('addLink')->assertHasErrors(['newLink.url'])
            ->set('newLink.title', 'Hotel')->set('newLink.url', 'https://hotel.example')->call('addLink')->assertHasNoErrors()
            ->set('folderShows.ideas', false);

        Volt::test('trips.group', ['trip' => $trip->id])->call('addPage', $private->id)->assertNotFound();

        $this->get(route('trips.folder', 'foldertoken1234567890'))->assertOk()
            ->assertSee('Tagesplan')->assertSee('Hafen Lindau')->assertSee('10:00')->assertDontSee('Treffpunkt am Löwen')
            ->assertDontSee('Eis am See')
            ->assertSee('<strong>Regenjacke</strong>', false)->assertSee('Proviant mitnehmen')->assertDontSee(':::')
            ->assertDontSee('Geheime Notiz')
            ->assertSee('https://hotel.example');

        Volt::test('trips.group', ['trip' => $trip->id])->set('folderShows.notes', true)->set('folderShows.ideas', true);
        $this->get(route('trips.folder', 'foldertoken1234567890'))->assertSee('Treffpunkt am Löwen')->assertSee('Weitere Ideen')->assertSee('Eis am See');
    }
}
