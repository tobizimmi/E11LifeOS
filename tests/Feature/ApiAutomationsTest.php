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
}
