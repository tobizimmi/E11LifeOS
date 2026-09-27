<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Support\Finance\FinanceReceiptAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceReceiptsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-20 12:00'));
    }

    public function test_a_receipt_photo_is_read_by_the_ai_and_booked_as_cash_expense(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        config(['services.anthropic.key' => 'test-key']);
        $this->mock(FinanceReceiptAi::class, fn (MockInterface $ai) => $ai->shouldReceive('read')->once()->andReturn([
            'booked_on' => '2026-09-19', 'counterparty' => 'Wochenmarkt', 'purpose' => 'Obst und Gemüse', 'amount' => 18.4, 'category' => 'groceries',
        ]));

        Volt::test('finance.index')
            ->assertSee('Beleg oder Barausgabe erfassen')
            ->call('openManual')
            ->set('receiptPhoto', UploadedFile::fake()->image('bon.jpg'))
            ->call('readReceiptPhoto')
            ->assertSet('manual.counterparty', 'Wochenmarkt')
            ->assertSet('manual.amount', '18,40')
            ->call('saveManual')
            ->assertHasNoErrors()
            ->assertSet('manualOpen', false)
            ->assertSee('Ausgabe gespeichert.');

        $transaction = Transaction::sole();
        $this->assertSame([-18.4, 'groceries', true, '2026-09-19', 'Bargeld', $user->id], [
            $transaction->amount, $transaction->category, $transaction->category_locked, $transaction->booked_on->toDateString(), $transaction->account->name, $transaction->user_id,
        ]);
    }

    public function test_an_existing_bank_booking_of_the_same_purchase_is_offered_instead_of_a_duplicate(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Giro']);
        $booking = Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'other', 'amount' => -42.5, 'booked_on' => '2026-09-18', 'counterparty' => 'KARTENZAHLUNG', 'purpose' => 'POS 123']);

        $component = Volt::test('finance.index')->call('openManual')
            ->set('manual.counterparty', 'Baumarkt')->set('manual.amount', '42,50')->set('manual.booked_on', '2026-09-17')->set('manual.category', 'shopping')
            ->call('saveManual')
            ->assertSet('matchingTransactionId', $booking->id)
            ->assertSee('Diese Ausgabe ist wahrscheinlich schon gebucht:');
        $this->assertSame(1, Transaction::count());

        $component->call('saveManual', 'assign')->assertHasNoErrors();
        $this->assertSame(1, Transaction::count());
        $this->assertSame(['shopping', true, 'POS 123 · Baumarkt'], [$booking->fresh()->category, $booking->fresh()->category_locked, $booking->fresh()->purpose]);

        Volt::test('finance.index')->call('openManual')
            ->set('manual.counterparty', 'Baumarkt')->set('manual.amount', '42,50')->set('manual.booked_on', '2026-09-17')->set('manual.account', (string) $account->id)
            ->call('saveManual')->call('saveManual', 'new');
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());
    }

    public function test_invalid_input_and_foreign_accounts_are_rejected(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $foreign = BankAccount::factory()->create(['user_id' => $other->id, 'household_id' => $otherHousehold->id]);
        $this->actingAs($user);

        Volt::test('finance.index')->call('openManual')->set('manual.amount', 'abc')->call('saveManual')
            ->assertHasErrors(['manual.amount', 'manual.counterparty']);

        Volt::test('finance.index')->call('openManual')
            ->set('manual.counterparty', 'X')->set('manual.amount', '5')->set('manual.account', (string) $foreign->id)
            ->call('saveManual')->assertNotFound();
        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }
}
