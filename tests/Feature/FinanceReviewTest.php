<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use App\Support\Finance\TransactionReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceReviewTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_transfer_between_own_accounts_is_detected_and_excluded_once_confirmed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Giro']);
        $savings = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Tagesgeld']);

        $out = Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -500, 'booked_on' => now()->startOfMonth(), 'counterparty' => 'Sparen', 'category' => 'other']);
        $in = Transaction::factory()->create(['bank_account_id' => $savings->id, 'amount' => 500, 'booked_on' => now()->startOfMonth()->addDay(), 'counterparty' => 'Sparen', 'category' => 'other']);

        $pairs = app(TransactionReview::class)->transfers($user, $household->id);
        $this->assertCount(1, $pairs);

        Volt::test('finance.index')->set('tab', 'report')->call('confirmTransfer', $out->id, $in->id);

        $this->assertSame('transfer', $out->fresh()->category);
        $this->assertSame('transfer', $in->fresh()->category);

        [$from, $to] = FinancePeriod::range('6m');
        $report = FinanceReport::build(FinancePeriod::query($user, $household->id, '6m')->get(), $from, $to);
        $this->assertSame(0.0, $report['income']);
        $this->assertSame(0.0, $report['expenses']);
    }

    public function test_a_duplicate_booking_is_detected_and_excluded_once_confirmed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Giro']);

        $first = Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -42.50, 'booked_on' => now()->startOfMonth(), 'counterparty' => 'REWE', 'purpose' => 'Einkauf', 'category' => 'groceries']);
        $second = Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -42.50, 'booked_on' => now()->startOfMonth()->addDay(), 'counterparty' => 'REWE', 'purpose' => 'Einkauf', 'category' => 'groceries']);

        $pairs = app(TransactionReview::class)->duplicates($user, $household->id);
        $this->assertCount(1, $pairs);

        Volt::test('finance.index')->set('tab', 'report')->call('confirmDuplicate', $pairs[0]['keep']->id, $pairs[0]['duplicate']->id);

        $this->assertNotNull($second->fresh()->duplicate_of_id);
        $this->assertNull($first->fresh()->duplicate_of_id);

        [$from, $to] = FinancePeriod::range('6m');
        $report = FinanceReport::build(FinancePeriod::query($user, $household->id, '6m')->get(), $from, $to);
        $this->assertSame(42.50, $report['expenses']); // only one of the two 42.50 bookings counts
    }

    public function test_recurring_payments_far_apart_are_not_flagged_as_duplicates(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -9.99, 'booked_on' => now()->subMonth(), 'counterparty' => 'Spotify', 'purpose' => 'Abo', 'category' => 'wants']);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -9.99, 'booked_on' => now(), 'counterparty' => 'Spotify', 'purpose' => 'Abo', 'category' => 'wants']);

        $this->assertCount(0, app(TransactionReview::class)->duplicates($user, $household->id));
    }

    public function test_dismissing_a_transfer_suggestion_stops_it_from_reappearing(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $savings = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $out = Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -300, 'booked_on' => now()->startOfMonth(), 'category' => 'other']);
        $in = Transaction::factory()->create(['bank_account_id' => $savings->id, 'amount' => 300, 'booked_on' => now()->startOfMonth(), 'category' => 'other']);

        Volt::test('finance.index')->set('tab', 'report')->call('ignoreTransfer', $out->id, $in->id);

        $this->assertNotNull($out->fresh()->review_ignored_at);
        $this->assertCount(0, app(TransactionReview::class)->transfers($user, $household->id));
    }
}
