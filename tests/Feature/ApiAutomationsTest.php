<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for automations.
 */
class ApiAutomationsTest extends TestCase
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

    public function test_templates_toggle_log_and_delete(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $index = $this->getJson('/api/v1/automations', $headers)->assertOk()->json('data');
        $this->assertNotEmpty($index['templates']);
        $this->assertNotEmpty($index['modes']);
        $this->assertSame([], $index['automations']);

        $template = $index['templates'][0]['key'];
        $created = $this->postJson("/api/v1/automations/templates/{$template}", [], $headers)->assertCreated()->json('data');
        $this->assertNotSame('', $created['when']);
        $this->getJson('/api/v1/automations', $headers)->assertJsonCount(1, 'data.automations');

        $flipped = $this->postJson("/api/v1/automations/{$created['id']}/toggle", [], $headers)->assertOk()->json('data.active');
        $this->assertNotSame($created['active'], $flipped);
        $this->getJson("/api/v1/automations/{$created['id']}/log", $headers)->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/automations/templates/gibt-es-nicht', [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/automations/{$created['id']}", [], $headers)->assertOk();
        $this->getJson('/api/v1/automations', $headers)->assertJsonCount(0, 'data.automations');
    }

    public function test_modes_and_packages(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $mode = $this->getJson('/api/v1/automations', $headers)->json('data.modes.0.key');

        $this->postJson("/api/v1/automations/modes/{$mode}", ['active' => true], $headers)->assertOk();
        $this->assertTrue($this->getJson('/api/v1/automations', $headers)->json('data.modes.0.active'));
        $this->postJson('/api/v1/automations/modes/unbekannt', ['active' => true], $headers)->assertNotFound();

        $package = $this->getJson('/api/v1/automations', $headers)->json('data.packages.0.key');
        $this->postJson("/api/v1/automations/packages/{$package}", [], $headers)->assertCreated();
    }

    public function test_automations_of_other_households_are_not_reachable(): void
    {
        [$user] = $this->memberWithHousehold();
        $stranger = $this->memberWithHousehold()[0];
        $template = $this->getJson('/api/v1/automations', $this->headersFor($user))->json('data.templates.0.key');
        $id = $this->postJson("/api/v1/automations/templates/{$template}", [], $this->headersFor($user))->json('data.id');

        $this->postJson("/api/v1/automations/{$id}/toggle", [], $this->headersFor($stranger))->assertNotFound();
    }

    public function test_block_editor_catalog_save_edit_and_test_run(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $catalog = $this->getJson('/api/v1/automations/catalog', $headers)->assertOk()->assertJsonStructure(['data' => ['triggers', 'conditions', 'actions']])->json('data');
        $types = array_column($catalog['triggers'], 'type');
        $this->assertContains('time', $types);
        $this->assertContains('manual', $types);
        $push = collect($catalog['actions'])->firstWhere('type', 'push');
        $this->assertSame('select', collect($push['fields'])->firstWhere('key', 'to')['kind']);

        $id = $this->postJson('/api/v1/automations', [
            'name' => 'Abends erinnern',
            'trigger' => ['type' => 'time', 'at' => '19:00', 'days' => [1, 2, 3]],
            'conditions' => [['type' => 'weekdays', 'days' => [1, 2, 3]]],
            'actions' => [['type' => 'push', 'to' => 'me', 'title' => 'Hallo', 'body' => 'Test', 'urgent' => false]],
        ], $headers)->assertCreated()->assertJsonPath('data.trigger.at', '19:00')->assertJsonPath('data.active', true)->json('data.id');

        $this->postJson('/api/v1/automations', ['name' => 'X', 'trigger' => ['type' => 'time'], 'actions' => []], $headers)->assertStatus(422);
        $this->postJson('/api/v1/automations', ['name' => 'X', 'trigger' => ['type' => 'nope'], 'actions' => [['type' => 'push']]], $headers)->assertStatus(422);
        $this->postJson('/api/v1/automations', ['name' => 'X', 'trigger' => ['type' => 'time', 'at' => '25:99'], 'actions' => [['type' => 'push']]], $headers)->assertStatus(422);

        $this->putJson("/api/v1/automations/{$id}", ['name' => 'Abends', 'trigger' => ['type' => 'manual'], 'actions' => [['type' => 'delay', 'minutes' => 5]]], $headers)->assertOk()->assertJsonPath('data.name', 'Abends')->assertJsonPath('data.trigger.type', 'manual');
        $this->getJson("/api/v1/automations/{$id}", $headers)->assertOk()->assertJsonPath('data.actions.0.minutes', 5);
        $this->postJson('/api/v1/automations/test', ['name' => 'Probe', 'trigger' => ['type' => 'manual'], 'actions' => [['type' => 'push', 'title' => 'Hi']]], $headers)->assertOk()->assertJsonStructure(['data' => ['log']]);

        $stranger = $this->memberWithHousehold()[0];
        $this->getJson("/api/v1/automations/{$id}", $this->headersFor($stranger))->assertNotFound();
    }
}
