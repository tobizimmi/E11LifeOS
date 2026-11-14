<?php

namespace Tests\Feature;

use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Portfolio;
use App\Models\PriceAlert;
use App\Models\SavingsPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for split costs, contract costs, price alerts and savings plans.
 */
class ApiMoneyModulesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_costs_are_split_balanced_and_settled(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $group = $this->postJson('/api/v1/splits', ['title' => 'Skiurlaub', 'icon' => '🎿', 'people' => ['Anna', ' Ben ', $user->name]], $headers)->assertCreated()->json('data');
        $this->assertCount(3, $group['members']);
        $id = $group['id'];
        [$me, $anna, $ben] = array_column($group['members'], 'id');
        $this->assertSame($me, $group['me']);

        // 90 € paid by me, split equally between the three: the others owe me 30 € each.
        $data = $this->postJson("/api/v1/splits/{$id}/expenses", ['title' => 'Hütte', 'amount' => 90, 'paid_by' => $me, 'spent_on' => today()->toDateString()], $headers)->assertCreated()->json('data');
        $this->assertEquals(90, $data['total']);
        $this->assertEquals(60, collect($data['members'])->firstWhere('id', $me)['balance']);
        $this->assertEquals(-30, collect($data['members'])->firstWhere('id', $anna)['balance']);
        $this->assertCount(2, $data['settlements']);
        $expenseId = $data['expenses'][0]['id'];

        // By amounts that do not add up: rejected.
        $this->postJson("/api/v1/splits/{$id}/expenses", ['title' => 'Falsch', 'amount' => 10, 'paid_by' => $me, 'spent_on' => today()->toDateString(), 'split_mode' => 'amounts', 'shares' => [['member' => $me, 'value' => 3], ['member' => $anna, 'value' => 3]]], $headers)->assertStatus(422);
        $this->postJson("/api/v1/splits/{$id}/expenses", ['title' => 'Fremd', 'amount' => 10, 'paid_by' => 999999, 'spent_on' => today()->toDateString()], $headers)->assertStatus(422);

        // Only Anna and me take part now: she owes 45 €.
        $data = $this->putJson("/api/v1/splits/{$id}/expenses/{$expenseId}", ['title' => 'Hütte', 'amount' => 90, 'paid_by' => $me, 'spent_on' => today()->toDateString(), 'shares' => [['member' => $me], ['member' => $anna]]], $headers)->assertOk()->json('data');
        $this->assertEquals(-45, collect($data['members'])->firstWhere('id', $anna)['balance']);
        $this->assertEquals(0, collect($data['members'])->firstWhere('id', $ben)['balance']);

        $data = $this->postJson("/api/v1/splits/{$id}/settle", ['from' => $anna, 'to' => $me, 'amount' => 45], $headers)->assertCreated()->json('data');
        $this->assertEquals(0, collect($data['members'])->firstWhere('id', $anna)['balance']);
        $this->assertSame([], $data['settlements']);
        $this->assertEquals(90, $data['total']);
        $this->postJson("/api/v1/splits/{$id}/settle", ['from' => $me, 'to' => $me, 'amount' => 5], $headers)->assertStatus(422);

        // Ben has no expenses and can go; Anna cannot.
        $this->deleteJson("/api/v1/splits/{$id}/members/{$ben}", [], $headers)->assertOk()->assertJsonCount(2, 'data.members');
        $this->deleteJson("/api/v1/splits/{$id}/members/{$anna}", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/splits/{$id}/members", ['name' => 'Cem'], $headers)->assertCreated()->assertJsonCount(3, 'data.members');

        $list = $this->getJson('/api/v1/splits', $headers)->assertOk()->json('data');
        $this->assertCount(1, $list['groups']);
        $this->assertEquals(90, $list['groups'][0]['total']);
        $this->assertEquals(0, $list['groups'][0]['my_balance']);

        $this->postJson("/api/v1/splits/{$id}/archive", [], $headers)->assertOk()->assertJsonPath('data.archived', true);
        $this->deleteJson("/api/v1/splits/{$id}/expenses/{$expenseId}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/splits/{$id}", [], $headers)->assertOk();
        $this->assertNull(ExpenseGroup::find($id));
    }

    public function test_split_groups_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $foreign = ExpenseGroup::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id]);
        $member = $foreign->members()->create(['name' => 'Fremd', 'position' => 0]);

        $this->getJson('/api/v1/splits', $headers)->assertOk()->assertJsonCount(0, 'data.groups');
        $this->getJson("/api/v1/splits/{$foreign->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/splits/{$foreign->id}/expenses", ['title' => 'X', 'amount' => 1, 'paid_by' => $member->id, 'spent_on' => today()->toDateString()], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/splits/{$foreign->id}", [], $headers)->assertNotFound();
    }

    public function test_contracts_are_listed_with_their_monthly_cost(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/contracts', $headers)->assertOk()->assertJsonPath('data.contracts', []);

        $id = $this->postJson('/api/v1/contracts', ['title' => 'Strom', 'provider' => 'Stadtwerke', 'monthly' => 80], $headers)->assertCreated()->assertJsonPath('data.title', 'Strom')->json('data.id');
        $this->postJson('/api/v1/contracts', ['monthly' => 5], $headers)->assertStatus(422);

        $data = $this->getJson('/api/v1/contracts', $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['contracts']);
        $this->assertSame($id, $data['contracts'][0]['id']);
        $this->assertEquals(80, $data['contracts'][0]['monthly']);
        $this->assertEquals(80, $data['monthly']);
        $this->assertEquals(960, $data['yearly']);
        $this->assertSame('Stadtwerke', $data['contracts'][0]['provider']);

        [$other] = $this->memberWithHousehold();
        $this->getJson('/api/v1/contracts', $this->headersFor($other))->assertOk()->assertJsonPath('data.contracts', []);
        $this->assertNotNull(Item::find($id));
    }

    public function test_price_alerts_are_created_rearmed_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/alerts', ['symbol' => 'sap.de', 'condition' => 'below', 'target_price' => 100], $headers)
            ->assertCreated()->assertJsonPath('data.symbol', 'SAP.DE')->assertJsonPath('data.active', true)->json('data.id');
        $this->postJson('/api/v1/alerts', ['symbol' => 'SAP.DE', 'condition' => 'sideways', 'target_price' => 100], $headers)->assertStatus(422);
        $this->postJson('/api/v1/alerts', ['symbol' => 'SAP.DE', 'condition' => 'above', 'target_price' => 0], $headers)->assertStatus(422);

        PriceAlert::find($id)->update(['triggered_at' => now(), 'active' => false]);
        $this->getJson('/api/v1/alerts', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.active', false);
        $this->postJson("/api/v1/alerts/{$id}/rearm", [], $headers)->assertOk()->assertJsonPath('data.active', true)->assertJsonPath('data.triggered_at', null);

        [$other, $otherHousehold] = $this->memberWithHousehold();
        $otherHousehold->setAddon('portfolio', true);
        $otherHeaders = $this->headersFor($other);
        $this->getJson('/api/v1/alerts', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/alerts/{$id}", [], $otherHeaders)->assertNotFound();

        $this->deleteJson("/api/v1/alerts/{$id}", [], $headers)->assertOk();
        $this->assertNull(PriceAlert::find($id));
    }

    public function test_savings_plans_are_created_changed_paused_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Depot']);
        $foreign = Portfolio::factory()->create();

        $id = $this->postJson('/api/v1/savings-plans', ['portfolio_id' => $portfolio->id, 'symbol' => 'vwce.de', 'amount' => 150, 'frequency' => 'monthly', 'day_of_month' => 1], $headers)
            ->assertCreated()->assertJsonPath('data.symbol', 'VWCE.DE')->assertJsonPath('data.active', true)->assertJsonPath('data.per_month', 150)->json('data.id');
        $this->postJson('/api/v1/savings-plans', ['portfolio_id' => $foreign->id, 'symbol' => 'X', 'amount' => 50, 'frequency' => 'monthly', 'day_of_month' => 1], $headers)->assertNotFound();
        $this->postJson('/api/v1/savings-plans', ['portfolio_id' => $portfolio->id, 'symbol' => 'X', 'amount' => 50, 'frequency' => 'daily', 'day_of_month' => 31], $headers)->assertStatus(422);

        $this->putJson("/api/v1/savings-plans/{$id}", ['portfolio_id' => $portfolio->id, 'symbol' => 'VWCE.DE', 'amount' => 300, 'frequency' => 'quarterly', 'day_of_month' => 15], $headers)
            ->assertOk()->assertJsonPath('data.per_month', 100)->assertJsonPath('data.day_of_month', 15);

        $data = $this->getJson('/api/v1/savings-plans', $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['plans']);
        $this->assertEquals(100, $data['monthly']);
        $this->assertSame('Depot', $data['portfolios'][0]['name']);

        $this->postJson("/api/v1/savings-plans/{$id}/toggle", [], $headers)->assertOk()->assertJsonPath('data.active', false);
        $this->assertEquals(0, $this->getJson('/api/v1/savings-plans', $headers)->json('data.monthly'));

        $this->deleteJson("/api/v1/savings-plans/{$id}", [], $headers)->assertOk();
        $this->assertNull(SavingsPlan::find($id));
    }
}
