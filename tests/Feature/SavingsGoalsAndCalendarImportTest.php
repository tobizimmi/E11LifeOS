<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CalendarSubscription;
use App\Models\SavingsGoal;
use App\Support\IcsCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SavingsGoalsAndCalendarImportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nDTSTART:20261005T080000Z\r\nSUMMARY:Teammeeting\\, Raum 2\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEXDATE:20261012T080000Z\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20261010\r\nSUMMARY:Geburtstag Oma\r\nRRULE:FREQ=YEARLY\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20261015T190000\r\nSUMMARY:Abgesagt\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_savings_goals_track_progress_from_an_account_or_by_hand(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Tagesgeld', 'balance' => 4000, 'balance_on' => '2026-09-01']);
        $this->actingAs($user);

        Volt::test('net-worth.index')
            ->set('goal.name', 'Notgroschen')->set('goal.target_amount', '10000')->set('goal.target_date', '2027-09-30')->set('goal.bank_account_id', (string) $account->id)
            ->call('addGoal')
            ->assertHasNoErrors()
            ->set('goal.name', 'Urlaub')->set('goal.target_amount', '2000')->set('goal.saved_amount', '500')
            ->call('addGoal')
            ->assertHasNoErrors();

        Volt::test('net-worth.index')->assertSee('Notgroschen')->assertSee('40 %')->assertSee('500,00 € pro Monat nötig');

        $holiday = SavingsGoal::where('name', 'Urlaub')->sole();
        $this->assertSame(25.0, $holiday->progress());
        Volt::test('net-worth.index')->call('editGoal', $holiday->id)->set('goalSaved', '2000')->call('saveGoal');
        $this->assertSame(100.0, $holiday->fresh()->progress());
    }

    public function test_goals_cannot_use_accounts_or_goals_of_others(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $foreignAccount = BankAccount::factory()->create(['user_id' => $other->id, 'household_id' => $otherHousehold->id]);
        $foreignGoal = SavingsGoal::factory()->create(['user_id' => $other->id]);
        $this->actingAs($user);

        Volt::test('net-worth.index')->set('goal.name', 'X')->set('goal.target_amount', '100')->set('goal.bank_account_id', (string) $foreignAccount->id)->call('addGoal')->assertHasErrors('goal.bank_account_id');
        Volt::test('net-worth.index')->call('deleteGoal', $foreignGoal->id)->assertNotFound();
    }

    public function test_an_external_calendar_is_subscribed_and_its_events_appear_in_the_calendar(): void
    {
        Http::fake(['calendar.example.com/*' => Http::response(self::ICS)]);
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('calendar.index')
            ->set('subscriptionName', 'Arbeit')->set('subscriptionUrl', 'webcal://calendar.example.com/private/basic.ics')
            ->call('addSubscription')
            ->assertHasNoErrors();

        $subscription = CalendarSubscription::sole();
        $this->assertNotSame('webcal://calendar.example.com/private/basic.ics', \DB::table('calendar_subscriptions')->value('url'));

        $events = IcsCalendar::eventsBetween($subscription, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame(
            [['2026-10-05', '10:00', 'Teammeeting, Raum 2'], ['2026-10-19', '10:00', 'Teammeeting, Raum 2'], ['2026-10-10', null, 'Geburtstag Oma']],
            array_map(fn (array $event) => [$event['date'], $event['time'], $event['title']], $events),
        );

        Volt::test('calendar.index', ['anchor' => '2026-10-01'])->set('anchor', '2026-10-01')->assertSee('Geburtstag Oma · Arbeit')->assertDontSee('Abgesagt');
    }

    public function test_calendar_links_to_the_server_itself_are_refused(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('calendar.index')->set('subscriptionName', 'X')->set('subscriptionUrl', 'http://127.0.0.1/secret.ics')->call('addSubscription')->assertHasErrors('subscriptionUrl');
        Volt::test('calendar.index')->set('subscriptionName', 'X')->set('subscriptionUrl', 'file:///etc/passwd')->call('addSubscription')->assertHasErrors('subscriptionUrl');
        $this->assertSame(0, CalendarSubscription::count());
    }
}
