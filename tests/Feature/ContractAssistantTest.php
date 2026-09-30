<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ContractAssistantTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function contracts(string $name = 'Verträge'): Collection
    {
        Volt::test('collections.index')->set('name', $name)->set('template', 'contracts')->call('create');

        return Collection::where('name', $name)->sole();
    }

    private function entry(Collection $collection, string $title, array $values = []): Item
    {
        $entry = Item::create(['household_id' => $collection->household_id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => $title, 'visibility' => $collection->visibility, 'created_by' => auth()->id()]);

        foreach ($values as $name => $value) {
            $entry->setPropertyValue($collection->properties->firstWhere('name', $name), $value);
        }

        $entry->save();

        return $entry;
    }

    public function test_a_task_is_created_once_when_the_cancellation_deadline_comes_close(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $phone = $this->entry($contracts, 'Handyvertrag', ['Kündigen bis' => '2026-10-20']);
        $this->entry($contracts, 'Stromvertrag', ['Kündigen bis' => '2026-12-31']);
        $this->entry($contracts, 'Alter Vertrag', ['Kündigen bis' => '2026-09-01']);

        $this->artisan('collections:deadline-tasks')->expectsOutput('Deadline tasks created: 1')->assertSuccessful();
        $this->artisan('collections:deadline-tasks')->expectsOutput('Deadline tasks created: 0');

        $tasksCollection = Collection::tasksFor($household->id);
        $task = Item::where('collection_id', $tasksCollection->id)->sole();
        $this->assertSame('Kündigen bis: Handyvertrag', $task->title);
        $this->assertSame('2026-10-20', $task->propertyValue($tasksCollection->systemProperty('due')));
        $this->assertSame($user->id, (int) $task->propertyValue($tasksCollection->systemProperty('assignee')));
        $this->assertStringContainsString(route('items.show', $phone), $task->body());

        // A new deadline (contract renewed) creates a new task.
        $phone->refresh()->setPropertyValue($contracts->properties->firstWhere('name', 'Kündigen bis'), '2026-10-24');
        $phone->save();
        $this->artisan('collections:deadline-tasks')->expectsOutput('Deadline tasks created: 1');
    }

    public function test_tasks_from_private_contracts_stay_private_and_the_option_can_be_switched_off(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $contracts = $this->contracts();
        $contracts->update(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
        $this->entry($contracts, 'Geheim', ['Kündigen bis' => '2026-10-01']);

        $this->artisan('collections:deadline-tasks')->expectsOutput('Deadline tasks created: 1');
        $this->assertSame(Item::VISIBILITY_PRIVATE, Item::where('type', Item::TYPE_TODO)->sole()->visibility);

        $cancelBy = $contracts->properties->firstWhere('name', 'Kündigen bis');
        Volt::test('collections.show', ['collection' => $contracts->id])->call('togglePropertyTask', $cancelBy->id);
        $this->assertFalse($cancelBy->fresh()->createsTask());
        $this->assertSame(30, $cancelBy->fresh()->remindDays());

        $this->entry($contracts, 'Noch einer', ['Kündigen bis' => '2026-10-02']);
        $this->artisan('collections:deadline-tasks')->expectsOutput('Deadline tasks created: 0');

        // Changing the reminder keeps the task option.
        Volt::test('collections.show', ['collection' => $contracts->id])->call('togglePropertyTask', $cancelBy->id)->call('setPropertyReminder', $cancelBy->id, '14');
        $this->assertTrue($cancelBy->fresh()->createsTask());
        $this->assertSame(14, $cancelBy->fresh()->remindDays());
    }

    public function test_a_cancellation_letter_can_be_written_and_downloaded(): void
    {
        [$user] = $this->memberWithHousehold();
        $user->update(['name' => 'Tobi Muster']);
        $this->actingAs($user);
        $contracts = $this->contracts();
        $entry = $this->entry($contracts, 'Handyvertrag', ['Anbieter' => 'Funk AG', 'Kundennummer' => 'K-4711', 'Endet am' => '2026-12-31']);

        $component = Volt::test('collections.show', ['collection' => $contracts->id])
            ->call('openEntry', $entry->id)
            ->assertSee('Kündigungsschreiben verfassen')
            ->call('openCancellationLetter');

        $letter = $component->get('letterText');
        $this->assertStringContainsString('Funk AG', $letter);
        $this->assertStringContainsString('Kundennummer: K-4711', $letter);
        $this->assertStringContainsString('zum 31. Dezember 2026', $letter);
        $this->assertStringContainsString('Tobi Muster', $letter);

        $component->call('downloadCancellationLetter')->assertFileDownloaded('kundigung-handyvertrag.pdf');
    }

    public function test_other_collections_have_no_cancellation_letter(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Projekte')->set('template', 'projects')->call('create');
        $projects = Collection::where('name', 'Projekte')->sole();
        $entry = $this->entry($projects, 'Garten');

        Volt::test('collections.show', ['collection' => $projects->id])
            ->call('openEntry', $entry->id)
            ->assertDontSee('Kündigungsschreiben verfassen')
            ->call('openCancellationLetter')
            ->assertNotFound();
    }
}
