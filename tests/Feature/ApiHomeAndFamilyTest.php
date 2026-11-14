<?php

namespace Tests\Feature;

use App\Models\HomeTask;
use App\Models\Kid;
use App\Models\Meter;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for vehicles, the home (maintenance, meters) and kids & pocket money.
 */
class ApiHomeAndFamilyTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        // Within one test the previous request's user stays set on the guard, and the login throttle
        // would then count against that user's API requests – start each sign-in as a guest.
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_vehicles_with_log_entries_and_costs(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/vehicles', ['name' => 'Golf', 'plate' => 'm-ab 123', 'fuel' => 'petrol', 'odometer_start' => 50000], $headers)
            ->assertCreated()->assertJsonPath('data.plate', 'M-AB 123')->assertJsonPath('data.electric', false)->json('data.id');
        $this->postJson('/api/v1/vehicles', ['name' => 'X', 'fuel' => 'steam'], $headers)->assertStatus(422);

        $data = $this->postJson("/api/v1/vehicles/{$id}/logs", ['kind' => 'fuel', 'date' => today()->toDateString(), 'odometer' => 50600, 'amount' => 80, 'quantity' => 45], $headers)->assertCreated()->json('data');
        $this->assertCount(1, $data['logs']);
        $this->assertEquals(80, $data['costs_12m']);
        $this->assertSame(50600, $data['odometer']);
        $logId = $data['logs'][0]['id'];

        // A trip needs a distance and a purpose and carries no amount.
        $this->postJson("/api/v1/vehicles/{$id}/logs", ['kind' => 'trip', 'date' => today()->toDateString()], $headers)->assertStatus(422);
        $trip = $this->postJson("/api/v1/vehicles/{$id}/logs", ['kind' => 'trip', 'date' => today()->toDateString(), 'distance_km' => 40, 'purpose' => 'business', 'amount' => 99], $headers)->assertCreated()->json('data');
        $this->assertNull(collect($trip['logs'])->firstWhere('kind', 'trip')['amount']);

        $due = today()->addMonths(2)->toDateString();
        $this->putJson("/api/v1/vehicles/{$id}", ['inspection_due' => $due], $headers)->assertOk()->assertJsonPath('data.inspection_due', $due)->assertJsonPath('data.name', 'Golf');

        $this->getJson('/api/v1/vehicles', $headers)->assertOk()->assertJsonCount(1, 'data.vehicles');
        $this->deleteJson("/api/v1/vehicles/{$id}/logs/{$logId}", [], $headers)->assertOk()->assertJsonCount(1, 'data.logs');

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->getJson("/api/v1/vehicles/{$id}", $otherHeaders)->assertNotFound();
        $this->postJson("/api/v1/vehicles/{$id}/logs", ['kind' => 'other', 'date' => today()->toDateString()], $otherHeaders)->assertNotFound();

        $this->deleteJson("/api/v1/vehicles/{$id}", [], $headers)->assertOk();
        $this->assertNull(Vehicle::find($id));
    }

    public function test_home_maintenance_and_meters(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $library = $this->getJson('/api/v1/house', $headers)->assertOk()->json('data.library');
        $this->assertNotEmpty($library);
        $key = $library[0]['key'];

        $data = $this->postJson('/api/v1/house/tasks', ['keys' => [$key, 'unknown']], $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['tasks']);
        $this->assertNotContains($key, array_column($data['library'], 'key'));

        $next = today()->subDays(3)->toDateString();
        $data = $this->postJson('/api/v1/house/tasks', ['title' => 'Rauchmelder testen', 'interval_months' => 6, 'next_due_on' => $next], $headers)->assertOk()->json('data');
        $custom = collect($data['tasks'])->firstWhere('title', 'Rauchmelder testen');
        $this->assertTrue($custom['overdue']);
        $this->postJson('/api/v1/house/tasks', ['title' => 'Ohne Intervall'], $headers)->assertStatus(422);

        $this->postJson("/api/v1/house/tasks/{$custom['id']}/done", [], $headers)->assertOk()
            ->assertJsonPath('data.overdue', false)->assertJsonPath('data.last_done_on', today()->toDateString())->assertJsonPath('data.next_due_on', today()->addMonthsNoOverflow(6)->toDateString());

        $meterId = $this->postJson('/api/v1/house/meters', ['name' => 'Strom', 'kind' => 'electricity', 'price_per_unit' => 0.3], $headers)->assertCreated()->assertJsonPath('data.unit', 'kWh')->json('data.id');
        $this->postJson("/api/v1/house/meters/{$meterId}/readings", ['read_on' => today()->subDays(100)->toDateString(), 'value' => 1000], $headers)->assertCreated();
        $meter = $this->postJson("/api/v1/house/meters/{$meterId}/readings", ['value' => 2000], $headers)->assertCreated()->json('data');
        $this->assertEquals(2000, $meter['latest']);
        $this->assertCount(2, $meter['readings']);
        $this->assertEquals(1000, $meter['periods'][0]['consumption']);
        $this->postJson("/api/v1/house/meters/{$meterId}/readings", ['read_on' => today()->addDay()->toDateString(), 'value' => 1], $headers)->assertStatus(422);

        $this->putJson("/api/v1/house/meters/{$meterId}", ['name' => 'Strom Haus', 'kind' => 'electricity'], $headers)->assertOk()->assertJsonPath('data.name', 'Strom Haus');
        $this->deleteJson("/api/v1/house/meters/{$meterId}/readings/{$meter['readings'][0]['id']}", [], $headers)->assertOk()->assertJsonCount(1, 'data.readings');

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->getJson('/api/v1/house', $otherHeaders)->assertOk()->assertJsonCount(0, 'data.tasks')->assertJsonCount(0, 'data.meters');
        $this->postJson("/api/v1/house/tasks/{$custom['id']}/done", [], $otherHeaders)->assertNotFound();
        $this->postJson("/api/v1/house/meters/{$meterId}/readings", ['value' => 5], $otherHeaders)->assertNotFound();

        $this->deleteJson("/api/v1/house/tasks/{$custom['id']}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/house/meters/{$meterId}", [], $headers)->assertOk();
        $this->assertNull(HomeTask::find($custom['id']));
        $this->assertNull(Meter::find($meterId));
    }

    public function test_kids_chores_and_pocket_money(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $data = $this->postJson('/api/v1/kids', ['name' => 'Mia', 'emoji' => '🦄', 'weekly_allowance' => 5, 'goal_title' => 'Fahrrad', 'goal_amount' => 100], $headers)->assertOk()->json('data');
        $kid = $data['kids'][0];
        $this->assertEquals(0, $kid['balance']);

        $data = $this->postJson('/api/v1/kid-chores', ['title' => 'Zimmer aufräumen', 'reward' => 2, 'frequency' => 'daily'], $headers)->assertOk()->json('data');
        $chore = $data['chores'][0];
        $this->assertSame([$chore['id']], $data['kids'][0]['open_chores']);

        // Without auto approval the chore waits for a parent.
        $data = $this->postJson("/api/v1/kids/{$kid['id']}/chores/{$chore['id']}/done", [], $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['pending']);
        $this->assertEquals(0, $data['kids'][0]['balance']);
        $this->assertSame([], $data['kids'][0]['open_chores']);
        $this->postJson("/api/v1/kids/{$kid['id']}/chores/{$chore['id']}/done", [], $headers)->assertStatus(422);

        $data = $this->postJson("/api/v1/kid-completions/{$data['pending'][0]['id']}/review", ['approve' => true], $headers)->assertOk()->json('data');
        $this->assertSame([], $data['pending']);
        $this->assertEquals(2, $data['kids'][0]['balance']);

        $data = $this->postJson("/api/v1/kids/{$kid['id']}/transactions", ['kind' => 'gift', 'amount' => 20, 'label' => 'Oma'], $headers)->assertOk()->json('data');
        $data = $this->postJson("/api/v1/kids/{$kid['id']}/transactions", ['kind' => 'spend', 'amount' => 7, 'label' => 'Eis'], $headers)->assertOk()->json('data');
        $this->assertEquals(15, $data['kids'][0]['balance']);
        $this->assertEquals(15, $data['kids'][0]['goal_progress']);
        $this->postJson("/api/v1/kids/{$kid['id']}/transactions", ['kind' => 'allowance', 'amount' => 1, 'label' => 'X'], $headers)->assertStatus(422);

        // A booking from a chore cannot be deleted, a manual one can.
        $choreBooking = collect($data['kids'][0]['history'])->firstWhere('kind', 'chore');
        $manual = collect($data['kids'][0]['history'])->firstWhere('kind', 'spend');
        $this->assertFalse($choreBooking['deletable']);
        $this->deleteJson("/api/v1/kid-transactions/{$choreBooking['id']}", [], $headers)->assertNotFound();
        $this->assertEquals(22, $this->deleteJson("/api/v1/kid-transactions/{$manual['id']}", [], $headers)->assertOk()->json('data.kids.0.balance'));

        $this->putJson("/api/v1/kids/{$kid['id']}", ['name' => 'Mia', 'auto_approve' => true], $headers)->assertOk()->assertJsonPath('data.kids.0.auto_approve', true);
        $this->putJson("/api/v1/kid-chores/{$chore['id']}", ['title' => 'Zimmer', 'reward' => 3, 'frequency' => 'once', 'active' => false], $headers)->assertOk()->assertJsonPath('data.chores.0.active', false);

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->getJson('/api/v1/kids', $otherHeaders)->assertOk()->assertJsonCount(0, 'data.kids')->assertJsonCount(0, 'data.chores');
        $this->postJson("/api/v1/kids/{$kid['id']}/transactions", ['kind' => 'gift', 'amount' => 1, 'label' => 'X'], $otherHeaders)->assertNotFound();
        $this->putJson("/api/v1/kid-chores/{$chore['id']}", ['title' => 'X', 'reward' => 1, 'frequency' => 'daily'], $otherHeaders)->assertNotFound();
        $this->postJson('/api/v1/kid-chores', ['title' => 'X', 'reward' => 1, 'frequency' => 'daily', 'kid_id' => $kid['id']], $otherHeaders)->assertStatus(422);

        $this->deleteJson("/api/v1/kid-chores/{$chore['id']}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/kids/{$kid['id']}", [], $headers)->assertOk();
        $this->assertNull(Kid::find($kid['id']));
    }
}
