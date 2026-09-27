<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceBudget;
use App\Models\Transaction;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceBudgetsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_budgets_are_set_shown_and_warned_once_per_level(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 12:00'));
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'groceries', 'amount' => -170, 'booked_on' => '2026-09-05']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'groceries', 'amount' => -50, 'booked_on' => '2026-08-30']);

        Volt::test('finance.index')->set('tab', 'budgets')
            ->call('saveBudget', 'groceries', '200,00')
            ->call('saveBudget', 'salary', '100')->assertNotFound();

        Volt::test('finance.index')->set('tab', 'budgets')->assertSee('170,00 € / 200,00 €')->assertSee('30,00 € übrig (85 %)');
        $this->assertSame(200.0, FinanceBudget::sole()->monthly_limit);

        // 85 % → one warning at 80 %, not repeated; 100 % → second warning.
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->twice()
            ->withArgs(fn (User $to, string $title) => $to->is($user) && str_contains($title, 'Lebensmittel')));
        $this->artisan('finance:check-budgets')->expectsOutputToContain('Budget warnings: 1');
        $this->artisan('finance:check-budgets')->expectsOutputToContain('Budget warnings: 0');
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'groceries', 'amount' => -40, 'booked_on' => '2026-09-19']);
        $this->artisan('finance:check-budgets')->expectsOutputToContain('Budget warnings: 1');
        $this->assertSame([100, '2026-09'], [FinanceBudget::sole()->warned_level, FinanceBudget::sole()->warned_month]);

        // An empty value removes the budget.
        Volt::test('finance.index')->call('saveBudget', 'groceries', '');
        $this->assertSame(0, FinanceBudget::count());
    }
}
