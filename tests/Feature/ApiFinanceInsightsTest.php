<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceBudget;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AiException;
use App\Support\Finance\FinanceAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Report, AI analyst, tax year and budget pots for the mobile app.
 */
class ApiFinanceInsightsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function booking(User $user, int $householdId, string $counterparty, float $amount, string $date): Transaction
    {
        $account = BankAccount::firstOrCreate(['user_id' => $user->id, 'household_id' => $householdId, 'name' => 'Giro'], ['balance' => 0]);

        return Transaction::create(['user_id' => $user->id, 'household_id' => $householdId, 'bank_account_id' => $account->id, 'booked_on' => $date, 'counterparty' => $counterparty, 'purpose' => $counterparty, 'amount' => $amount, 'category' => 'other', 'import_hash' => md5($counterparty.$date.$amount)]);
    }

    public function test_the_report_and_the_analyst(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $this->booking($user, $household->id, 'Arbeitgeber', 3000, now()->subDays(10)->toDateString());
        $this->booking($user, $household->id, 'Rewe', -80, now()->subDays(5)->toDateString());
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/finance/report?period=3m', $headers)->assertOk()->assertJsonPath('data.report.count', 2)->assertJsonPath('data.report.income', 3000)->assertJsonPath('data.report.largest.0.counterparty', 'Rewe');

        $this->mock(FinanceAi::class, fn (MockInterface $ai) => $ai->shouldReceive('advise')->once()->andReturn(['summary' => 'Solide.', 'strengths' => ['Sparquote'], 'recommendations' => []]));
        $this->postJson('/api/v1/finance/advice', ['period' => '3m'], $headers)->assertOk()->assertJsonPath('data.summary', 'Solide.');
    }

    public function test_the_analyst_reports_failures_and_empty_periods(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/finance/advice', ['period' => '3m'], $headers)->assertUnprocessable()->assertJsonValidationErrors('period');

        $this->booking($user, $household->id, 'Rewe', -80, now()->subDays(5)->toDateString());
        $this->mock(FinanceAi::class, fn (MockInterface $ai) => $ai->shouldReceive('advise')->andThrow(new AiException('KI nicht erreichbar')));
        $this->postJson('/api/v1/finance/advice', ['period' => '3m'], $headers)->assertUnprocessable()->assertJsonValidationErrors('ai');
    }

    public function test_the_tax_year_tags_bookings_and_takes_suggestions(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $gift = $this->booking($user, $household->id, 'Deutsches Rotes Kreuz Spende', -120, '2025-06-10');
        $this->booking($user, $household->id, 'Rewe', -50, '2025-06-11');
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/finance/tax?year=2025', $headers)->assertOk()->assertJsonPath('data.year', 2025)->assertJsonStructure(['data' => ['deductions', 'credits', 'effect', 'categories', 'tagged', 'suggestions', 'years']]);

        $this->putJson("/api/v1/finance/bookings/{$gift->id}/tax", ['category' => 'donations'], $headers)->assertOk()->assertJsonPath('data.tax_category', 'donations');
        $this->putJson("/api/v1/finance/bookings/{$gift->id}/tax", ['category' => 'donations', 'amount' => 500], $headers)->assertUnprocessable();
        $this->putJson("/api/v1/finance/bookings/{$gift->id}/tax", ['category' => 'donations', 'amount' => 100], $headers)->assertOk()->assertJsonPath('data.tax_amount', 100);
        $this->getJson('/api/v1/finance/tax?year=2025', $headers)->assertJsonCount(1, 'data.tagged');
        $this->getJson('/api/v1/finance/tax/search?year=2025&q=rewe', $headers)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/finance/tax/accept?year=2025', [], $headers)->assertOk()->assertJsonStructure(['data' => ['accepted']]);
        $this->putJson('/api/v1/finance/tax/settings', ['rate' => 35, 'children' => 2], $headers)->assertOk();
        $this->assertSame(2, (int) $user->fresh()->tax_children);
        $this->putJson("/api/v1/finance/bookings/{$gift->id}/tax", ['category' => null], $headers)->assertOk()->assertJsonPath('data.tax_category', null);
    }

    public function test_a_budget_becomes_a_pot(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $headers = $this->headersFor($user);
        FinanceBudget::create(['user_id' => $user->id, 'household_id' => $household->id, 'category' => 'groceries', 'monthly_limit' => 400]);

        $this->getJson('/api/v1/budgets', $headers)->assertOk()->assertJsonPath('data.0.rollover', false);
        $this->postJson('/api/v1/budgets/groceries/rollover', [], $headers)->assertOk()->assertJsonPath('data.rollover', true);
        $this->getJson('/api/v1/budgets', $headers)->assertJsonPath('data.0.rollover', true);
        $this->postJson('/api/v1/budgets/groceries/rollover', [], $headers)->assertJsonPath('data.rollover', false);
        $this->postJson('/api/v1/budgets/travel/rollover', [], $headers)->assertNotFound();
    }
}
