<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\User;
use App\Support\InAppNotifications;
use App\Support\ShoppingLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class LifeApiTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-29 09:00'));
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_tasks_are_listed_added_and_ticked_off(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->postJson('/api/v1/tasks', ['text' => 'Müll rausbringen morgen'], $headers)->assertCreated()
            ->assertJsonPath('data.title', 'Müll rausbringen')->assertJsonPath('data.due', '2026-09-30');
        $this->postJson('/api/v1/tasks', ['text' => 'Steuer heute'], $headers)->assertCreated();

        $this->getJson('/api/v1/tasks?filter=today', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Steuer');
        $this->getJson('/api/v1/tasks?filter=upcoming', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Müll rausbringen');

        $task = Item::where('title', 'Steuer')->sole();
        $this->postJson("/api/v1/tasks/{$task->id}/toggle", [], $headers)->assertOk()->assertJsonPath('data.done', true);
        $this->getJson('/api/v1/tasks', $headers)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/calendar?from=2026-09-29&to=2026-10-05', $headers)->assertOk()->assertJsonPath('data.0.title', 'Müll rausbringen');
        $this->getJson('/api/v1/calendar?from=2026-01-01&to=2026-12-31', $headers)->assertStatus(422);

        // Tasks of another household stay out of reach.
        [, $otherHousehold] = $this->memberWithHousehold();
        $foreign = Item::factory()->todo()->create(['household_id' => $otherHousehold->id, 'collection_id' => Collection::tasksFor($otherHousehold->id)->id]);
        $this->postJson("/api/v1/tasks/{$foreign->id}/toggle", [], $headers)->assertNotFound();
        $this->assertFalse($foreign->fresh()->done);
    }

    public function test_shopping_list_notifications_and_net_worth(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $foreignEntry = ShoppingLists::add(ShoppingList::defaultFor($otherHousehold->id), 'Fremd', null, $other->id);
        InAppNotifications::record($user, 'Rechnung fällig', 'Strom', '/finance');
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $id = $this->postJson('/api/v1/shopping', ['label' => 'Milch', 'quantity' => '2 l'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/shopping/{$id}/toggle", [], $headers)->assertOk()->assertJsonPath('data.checked', true);
        $this->getJson('/api/v1/shopping', $headers)->assertOk()->assertJsonPath('data.items.0.label', 'Milch')->assertJsonCount(1, 'data.items');
        $this->postJson("/api/v1/shopping/{$foreignEntry->id}/toggle", [], $headers)->assertNotFound();
        $this->assertFalse((bool) ShoppingListEntry::find($foreignEntry->id)->checked);

        $this->getJson('/api/v1/notifications', $headers)->assertOk()->assertJsonPath('data.unread', 1)->assertJsonPath('data.items.0.title', 'Rechnung fällig');
        $this->postJson('/api/v1/notifications/read', [], $headers)->assertOk();
        $this->assertSame(0, InAppNotifications::unreadCount($user));

        $this->getJson('/api/v1/net-worth', $headers)->assertOk()->assertJsonPath('data.net', 0);
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_a_dictated_line_adds_several_items_to_the_shopping_list(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->postJson('/api/v1/shopping/quick', ['text' => '2 Liter Milch, Butter und 6 Eier'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.added', 3);

        $items = collect($this->getJson('/api/v1/shopping', $headers)->assertOk()->json('data.items'));
        $this->assertEqualsCanonicalizing(['Milch', 'Butter', 'Eier'], $items->pluck('label')->all());
        $this->assertSame('2 Liter', $items->firstWhere('label', 'Milch')['quantity']);

        // The same item again combines instead of adding a duplicate.
        $this->postJson('/api/v1/shopping/quick', ['text' => 'Butter'], $headers)->assertCreated();
        $this->getJson('/api/v1/shopping', $headers)->assertJsonCount(3, 'data.items');

        $this->postJson('/api/v1/shopping/quick', ['text' => ''], $headers)->assertStatus(422);
    }

    public function test_the_token_in_url_quick_add_works_for_shopping_task_and_inbox(): void
    {
        [$user] = $this->memberWithHousehold();
        $token = $this->tokenFor($user);

        // Shopping: a dictated line adds the items.
        $this->getJson('/api/v1/quick/shopping?token='.$token.'&text='.urlencode('2 Liter Milch und Butter'))
            ->assertCreated()->assertJsonPath('data.added', 2);
        $this->assertSame(2, ShoppingListEntry::count());

        // Task: natural language becomes a to-do.
        $this->getJson('/api/v1/quick/task?token='.$token.'&text='.urlencode('Müll rausbringen'))
            ->assertCreated()->assertJsonPath('data.message', 'Task added: Müll rausbringen');

        // Inbox: the universal capture returns a message.
        $this->getJson('/api/v1/quick/inbox?token='.$token.'&text='.urlencode('Idee notieren'))->assertCreated();

        // A wrong token is rejected and an unknown type is a 404.
        $this->getJson('/api/v1/quick/shopping?token=nope&text=Brot')->assertUnauthorized();
        $this->getJson('/api/v1/quick/calendar?token='.$token.'&text=Brot')->assertNotFound();
    }
}
