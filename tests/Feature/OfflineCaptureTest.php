<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class OfflineCaptureTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_entries_captured_offline_are_created_once(): void
    {
        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->getJson(route('offline.token'))->assertOk()->assertJsonStructure(['token']);

        $this->postJson(route('offline.capture'), ['id' => 'a1', 'kind' => 'inbox', 'text' => 'Idee für den Garten #garten', 'private' => true])->assertCreated();
        $this->postJson(route('offline.capture'), ['id' => 'a1', 'kind' => 'inbox', 'text' => 'Idee für den Garten #garten'])->assertOk()->assertJson(['created' => false]);
        $this->postJson(route('offline.capture'), ['id' => 'b2', 'kind' => 'task', 'text' => 'Reifen wechseln morgen !hoch'])->assertCreated();

        $inbox = Item::ofType(Item::TYPE_INBOX)->sole();
        $this->assertSame(['Idee für den Garten', true, ['garten']], [$inbox->title, $inbox->isPrivate(), $inbox->tags->pluck('name')->all()]);

        $tasks = Collection::tasksFor($household->id);
        $task = Item::ofType(Item::TYPE_TODO)->sole();
        $this->assertSame(['Reifen wechseln', '2026-09-26', 'high'], [$task->title, $task->dueDate(), $task->propertyValue($tasks->systemProperty('priority'))]);
    }

    public function test_invalid_or_unauthenticated_requests_are_rejected(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->postJson(route('offline.capture'), ['id' => 'x', 'kind' => 'inbox', 'text' => 'Hallo'])->assertUnauthorized();

        $this->actingAs($user)->postJson(route('offline.capture'), ['id' => 'x', 'kind' => 'page', 'text' => 'Hallo'])->assertUnprocessable();
        $this->actingAs($user)->postJson(route('offline.capture'), ['id' => 'y', 'kind' => 'task', 'text' => '   '])->assertUnprocessable();
        $this->assertSame(0, Item::count());
    }
}
