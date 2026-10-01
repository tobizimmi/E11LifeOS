<?php

namespace Tests\Feature;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripParticipant;
use App\Models\TripStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class TripFeedbackTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_guests_comment_and_suggest_via_the_trip_folder_and_members_approve(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Gardasee', 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12', 'share_token' => 'foldertoken1234567890', 'share_password' => 'Garda2026']);
        $stop = TripStop::create(['trip_id' => $trip->id, 'day' => '2026-10-10', 'kind' => 'sight', 'title' => 'Altstadt Malcesine']);

        // Locked folder: nothing can be sent without the password.
        $this->post(route('trips.folder.feedback', 'foldertoken1234567890'), ['type' => 'comment', 'name' => 'Gast', 'body' => 'Hallo'])->assertForbidden();
        $this->post(route('trips.folder.unlock', 'foldertoken1234567890'), ['password' => 'Garda2026']);

        $this->post(route('trips.folder.feedback', 'foldertoken1234567890'), ['type' => 'comment', 'name' => 'Lena', 'body' => 'Lieber am Nachmittag', 'stop' => $stop->id])
            ->assertRedirect(route('trips.folder', 'foldertoken1234567890').'#feedback');
        $this->post(route('trips.folder.feedback', 'foldertoken1234567890'), ['type' => 'suggestion', 'name' => 'Lena', 'title' => 'Bootsfahrt nach Limone', 'day' => '2026-10-11', 'body' => 'Sehr schön'])->assertSessionHasNoErrors();
        $this->post(route('trips.folder.feedback', 'foldertoken1234567890'), ['type' => 'suggestion', 'name' => 'Lena'])->assertSessionHasErrors('title');

        $this->get(route('trips.folder', 'foldertoken1234567890'))->assertOk()
            ->assertSee('Lieber am Nachmittag')->assertSee('Bootsfahrt nach Limone')->assertSee('wartet auf Freigabe');
        $this->assertSame(2, $user->notifications()->count());

        $suggestion = TripFeedback::where('type', 'suggestion')->sole();
        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'plan')
            ->assertSee('Bootsfahrt nach Limone')->assertSee('Lieber am Nachmittag')
            ->call('approveSuggestion', $suggestion->id);

        $planned = $trip->stops()->where('title', 'Bootsfahrt nach Limone')->sole();
        $this->assertSame('2026-10-11', $planned->day->toDateString());
        $this->assertStringContainsString('Vorgeschlagen von Lena', (string) $planned->notes);
        $this->assertSame(TripFeedback::APPROVED, $suggestion->fresh()->status);

        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'plan')->call('approveSuggestion', $suggestion->id)->assertStatus(422);
    }

    public function test_participants_suggest_with_their_link_and_suggestions_can_be_declined(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        $participant = TripParticipant::create(['trip_id' => $trip->id, 'name' => 'Ben', 'share_token' => 'bentoken1234567890']);

        $this->post(route('trips.participant.feedback', 'bentoken1234567890'), ['type' => 'suggestion', 'title' => 'Pizza am Hafen', 'day' => '2027-01-01'])->assertSessionHasNoErrors();
        $suggestion = TripFeedback::sole();
        $this->assertSame('Ben', $suggestion->author_name);
        $this->assertSame($participant->id, $suggestion->participant_id);
        $this->assertNull($suggestion->day);

        $this->actingAs($user);
        Volt::test('trips.show', ['trip' => $trip->id])->set('tab', 'plan')->call('declineSuggestion', $suggestion->id)
            ->set('feedbackText', 'Klingt gut, aber zu weit')->call('postComment')->assertHasNoErrors();
        $this->assertSame(TripFeedback::DECLINED, $suggestion->fresh()->status);
        $this->assertSame(0, $trip->stops()->count());
        $this->assertSame($user->id, TripFeedback::where('type', 'comment')->sole()->user_id);

        $this->get(route('trips.participant', 'bentoken1234567890'))->assertSee('nicht übernommen')->assertSee('Klingt gut, aber zu weit');
    }

    public function test_members_of_other_households_cannot_moderate(): void
    {
        [, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id]);
        $suggestion = TripFeedback::factory()->suggestion()->create(['trip_id' => $trip->id]);

        $this->actingAs($stranger);
        Volt::test('trips.show', ['trip' => $trip->id])->assertNotFound();
        $this->assertSame(TripFeedback::PENDING, $suggestion->fresh()->status);
    }
}
