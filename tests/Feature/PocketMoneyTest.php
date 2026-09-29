<?php

namespace Tests\Feature;

use App\Models\Kid;
use App\Models\KidChore;
use App\Models\KidChoreCompletion;
use App\Models\KidTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PocketMoneyTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday.
        $this->travelTo(Carbon::parse('2026-09-29 16:00'));
    }

    public function test_chores_are_ticked_off_confirmed_and_credited(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($user);

        $page = Volt::test('kids.index')
            ->call('editKid')->set('kid.name', 'Mia')->set('kid.weekly_allowance', '3,50')->set('kid.goal_title', 'Lego')->set('kid.goal_amount', '40')
            ->call('saveKid')->assertHasNoErrors()
            ->call('editChore')->set('chore.title', 'Spülmaschine ausräumen')->set('chore.reward', '0,50')->set('chore.frequency', 'daily')
            ->call('saveChore')->assertHasNoErrors()
            ->assertSee('Spülmaschine ausräumen');

        $mia = Kid::sole();
        $chore = KidChore::sole();
        $page->call('done', $mia->id, $chore->id)->assertSee('Bitte bestätigen');
        $this->assertSame('⭐ Mia hat „Spülmaschine ausräumen“ erledigt', $partner->notifications()->sole()->data['title']);
        $this->assertFalse($chore->isOpenFor($mia, today()));
        Volt::test('kids.index')->call('done', $mia->id, $chore->id)->assertStatus(422);

        $page->call('approve', KidChoreCompletion::sole()->id);
        $this->assertSame(0.5, $mia->balance());

        $page->call('openMoney', $mia->id, 'spend')->set('money.amount', '0,20')->set('money.label', 'Kaugummi')->call('saveMoney')->assertHasNoErrors();
        $this->assertSame(0.3, $mia->balance());

        $this->travelTo(Carbon::parse('2026-09-30 08:00'));
        $this->assertTrue($chore->isOpenFor($mia, today()));

        $mia->update(['auto_approve' => true]);
        $page->call('done', $mia->id, $chore->id);
        $this->assertSame(0.8, $mia->fresh()->balance());
    }

    public function test_the_weekly_allowance_is_booked_once_on_its_weekday(): void
    {
        [, $household] = $this->memberWithHousehold();
        $kid = Kid::factory()->create(['household_id' => $household->id, 'weekly_allowance' => 5, 'allowance_day' => 6]);

        $this->artisan('kids:allowance')->expectsOutput('Pocket money booked: 0');
        $this->travelTo(Carbon::parse('2026-10-03 06:10'));
        $this->artisan('kids:allowance')->expectsOutput('Pocket money booked: 1');
        $this->artisan('kids:allowance')->expectsOutput('Pocket money booked: 0');
        $this->assertSame(['allowance', 5.0], [KidTransaction::sole()->kind, $kid->balance()]);
    }

    public function test_kids_of_other_households_are_not_reachable(): void
    {
        [$user] = $this->memberWithHousehold();
        $foreign = Kid::factory()->create();
        $this->actingAs($user);

        Volt::test('kids.index')->assertDontSee($foreign->name)->call('openMoney', $foreign->id, 'bonus')->assertNotFound();
    }
}
