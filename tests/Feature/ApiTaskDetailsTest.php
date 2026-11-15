<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Task details for the mobile app: subtasks, notes, time, repeat, assignee, category, tags.
 */
class ApiTaskDetailsTest extends TestCase
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

    public function test_subtasks_details_and_list_summary(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/tasks', ['text' => 'Umzug planen'], $headers)->assertCreated()->json('data.id');

        $detail = $this->postJson("/api/v1/tasks/{$id}/subtasks", ['text' => 'Kartons kaufen'], $headers)->assertCreated()->json('data');
        $this->assertSame('Kartons kaufen', $detail['subtasks'][0]['text']);
        $this->postJson("/api/v1/tasks/{$id}/subtasks", ['text' => 'Transporter mieten'], $headers)->assertCreated();
        $sid = $detail['subtasks'][0]['id'];
        $this->postJson("/api/v1/tasks/{$id}/subtasks/{$sid}/toggle", [], $headers)->assertOk()->assertJsonPath('data.subtasks.0.done', true);

        $list = $this->getJson('/api/v1/tasks', $headers)->assertOk()->json('data');
        $this->assertSame(['total' => 2, 'done' => 1], $list[0]['subtasks']);

        $this->putJson("/api/v1/tasks/{$id}/details", ['notes' => 'Vorher Keller leeren', 'time' => '09:30', 'repeat' => 'weekly', 'tags' => ['umzug', 'wichtig'], 'assignee' => $user->id], $headers)
            ->assertOk()
            ->assertJsonPath('data.notes', 'Vorher Keller leeren')
            ->assertJsonPath('data.time', '09:30')
            ->assertJsonPath('data.repeat', 'weekly')
            ->assertJsonPath('data.assignee.id', $user->id)
            ->assertJsonPath('data.tags', ['umzug', 'wichtig']);
        // A time without a date makes the task due.
        $this->assertNotNull($this->getJson("/api/v1/tasks/{$id}", $headers)->json('data.due'));
        $this->assertNotEmpty($this->getJson("/api/v1/tasks/{$id}", $headers)->json('data.options.repeat'));

        $this->putJson("/api/v1/tasks/{$id}/details", ['time' => '25:99'], $headers)->assertStatus(422);
        $this->putJson("/api/v1/tasks/{$id}/details", ['assignee' => 999999], $headers)->assertStatus(422);

        $this->deleteJson("/api/v1/tasks/{$id}/subtasks/{$sid}", [], $headers)->assertOk()->assertJsonCount(1, 'data.subtasks');
    }

    public function test_tasks_of_other_households_are_not_reachable(): void
    {
        [$user] = $this->memberWithHousehold();
        [$stranger, $household] = $this->memberWithHousehold();
        $foreign = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_TODO, 'collection_id' => Collection::tasksFor($household->id)->id, 'title' => 'Fremd', 'created_by' => $stranger->id]);

        $this->getJson("/api/v1/tasks/{$foreign->id}", $this->headersFor($user))->assertNotFound();
    }
}
