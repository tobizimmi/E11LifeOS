<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\SubscriptionWatch;
use App\Models\Transaction;
use App\Support\Finance\SubscriptionAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SubscriptionAlertsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_new_and_more_expensive_subscriptions_are_notified_after_the_first_seed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);

        $book = fn (string $date, float $amount, string $name) => Transaction::factory()->create([
            'bank_account_id' => $account->id, 'booked_on' => $date, 'amount' => $amount, 'counterparty' => $name, 'purpose' => null, 'category' => 'subscriptions',
        ]);

        foreach (['07', '08', '09'] as $month) {
            $book("2026-$month-05", -12.99, 'Netflix');
        }

        // First run only seeds the state – no notifications for existing subscriptions.
        $first = SubscriptionAlerts::checkUser($user, $household->id);
        $this->assertTrue($first['seeded']);
        $this->assertSame(0, $first['new']);
        $this->assertSame(1, SubscriptionWatch::where('user_id', $user->id)->count());
        $this->assertSame(0, $user->fresh()->notifications()->count());

        // Netflix gets more expensive → one notification.
        $this->travelTo(Carbon::parse('2026-10-06 10:00'));
        $book('2026-10-05', -15.99, 'Netflix');
        $second = SubscriptionAlerts::checkUser($user, $household->id);
        $this->assertSame(1, $second['increased']);
        $this->assertSame(0, $second['new']);
        $this->assertSame(15.99, SubscriptionWatch::where('user_id', $user->id)->sole()->amount);
        $this->assertSame(1, $user->fresh()->notifications()->count());

        // A brand new recurring payee → one more notification, no duplicate for Netflix.
        foreach (['08', '09', '10'] as $month) {
            $book("2026-$month-11", -9.99, 'Spotify');
        }
        $third = SubscriptionAlerts::checkUser($user, $household->id);
        $this->assertSame(1, $third['new']);
        $this->assertSame(0, $third['increased']);
        $this->assertSame(2, $user->fresh()->notifications()->count());
        $this->assertSame(2, SubscriptionWatch::where('user_id', $user->id)->count());
    }

    public function test_command_runs_without_error(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => today()->toDateString(), 'amount' => -9.99, 'counterparty' => 'Spotify', 'category' => 'subscriptions']);

        $this->artisan('finance:watch-subscriptions')->expectsOutputToContain('Subscription notifications sent:')->assertSuccessful();
    }
}
