<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Notifications\TaskReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HouseholdModulesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 10:00'));
    }

    private function createFromTemplate(string $template, string $name): Collection
    {
        Volt::test('collections.index')->set('name', $name)->set('template', $template)->call('create');

        return Collection::where('name', $name)->sole();
    }

    private function entry(Collection $collection, string $title, array $values = []): Item
    {
        $entry = Item::create(['household_id' => $collection->household_id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => $title, 'visibility' => $collection->visibility]);

        foreach ($values as $name => $value) {
            $entry->setPropertyValue($collection->properties->firstWhere('name', $name), $value);
        }

        $entry->save();

        return $entry;
    }

    public function test_every_module_template_creates_its_properties(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $expected = [
            'contracts' => ['Kategorie', 'Anbieter', 'Monatliche Kosten (€)', 'Kundennummer', 'Kündigungsfrist', 'Endet am', 'Kündigen bis', 'Website'],
            'budget' => ['Betrag (€)', 'Art', 'Kategorie', 'Datum', 'Bezahlt von'],
            'pantry' => ['Art', 'Menge', 'Einheit', 'Mindestbestand', 'Ort', 'Haltbar bis'],
            'bookmarks' => ['Status', 'Link'],
            'journal' => ['Datum', 'Stimmung'],
            'habits' => ['Datum', 'Sport', 'Lesen', 'Meditation', 'Genug getrunken'],
        ];

        foreach ($expected as $template => $names) {
            $collection = $this->createFromTemplate($template, 'Modul '.$template);
            $this->assertSame($names, $collection->properties->sortBy('position')->pluck('name')->all(), $template);
        }

        $this->assertSame(30, Collection::where('name', 'Modul contracts')->sole()->properties->firstWhere('name', 'Kündigen bis')->remindDays());
        $this->assertSame(3, Collection::where('name', 'Modul pantry')->sole()->properties->firstWhere('name', 'Haltbar bis')->remindDays());
        $this->assertTrue(Collection::where('name', 'Modul journal')->sole()->isPrivate());
        $this->assertFalse(Collection::where('name', 'Modul habits')->sole()->isPrivate());
    }

    public function test_date_properties_can_get_an_email_reminder(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $collection = $this->createFromTemplate('projects', 'Projekte');
        $due = $collection->properties->firstWhere('type', CollectionProperty::TYPE_DATE);

        Volt::test('collections.show', ['collection' => $collection->id])->call('setPropertyReminder', $due->id, '7');
        $this->assertSame(7, $due->fresh()->remindDays());

        Volt::test('collections.show', ['collection' => $collection->id])->call('setPropertyReminder', $due->id, '');
        $this->assertNull($due->fresh()->remindDays());
    }

    public function test_reminder_emails_include_contract_deadlines_and_expiring_food(): void
    {
        Notification::fake();
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $user->update(['reminder_emails' => true]);
        $partner->update(['reminder_emails' => true]);
        $this->actingAs($user);

        $contracts = $this->createFromTemplate('contracts', 'Verträge');
        $pantry = $this->createFromTemplate('pantry', 'Vorräte');
        $this->entry($contracts, 'Handyvertrag', ['Kündigen bis' => '2026-10-25']);
        $this->entry($contracts, 'Stromvertrag', ['Kündigen bis' => '2026-11-30']);
        $this->entry($pantry, 'Joghurt', ['Haltbar bis' => '2026-09-28']);
        $this->entry($pantry, 'Milch', ['Haltbar bis' => '2026-09-25']);

        // Someone else's private contracts stay out of other people's mails.
        $private = $this->createFromTemplate('contracts', 'Meine Verträge');
        $private->update(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
        $this->entry($private, 'Geheim', ['Kündigen bis' => '2026-10-25'])->update(['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);

        $this->artisan('reminders:send')->expectsOutput('Reminders sent: 2, pushed: 0')->assertSuccessful();

        Notification::assertSentTo($user, TaskReminder::class, function (TaskReminder $notification) use ($user) {
            $lines = implode("\n", $notification->toMail($user)->introLines);

            return $notification->dates->map(fn ($row) => $row['entry']->title)->all() === ['Milch', 'Joghurt', 'Handyvertrag', 'Geheim']
                && str_contains($lines, 'Handyvertrag – Kündigen bis: 25. Oktober 2026')
                && str_contains($lines, 'Milch – Haltbar bis: heute');
        });
        Notification::assertSentTo($partner, TaskReminder::class, fn (TaskReminder $notification) => $notification->dates->map(fn ($row) => $row['entry']->title)->all() === ['Milch', 'Joghurt', 'Handyvertrag']);
    }

    public function test_tables_add_up_numbers_and_count_ticked_boxes(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $budget = $this->createFromTemplate('budget', 'Haushaltsbuch');
        $habits = $this->createFromTemplate('habits', 'Gewohnheiten');
        $this->entry($budget, 'Wocheneinkauf', ['Betrag (€)' => 84.5, 'Art' => 'o1']);
        $this->entry($budget, 'Tanken', ['Betrag (€)' => 60, 'Art' => 'o1']);
        $this->entry($budget, 'Gehalt', ['Betrag (€)' => 2500, 'Art' => 'o2']);
        $this->entry($habits, 'Montag', ['Sport' => true, 'Lesen' => true]);
        $this->entry($habits, 'Dienstag', ['Lesen' => true]);

        Volt::test('collections.show', ['collection' => $budget->id, 'viewMode' => 'table'])
            ->assertSee('3 Einträge')
            ->assertSee('Σ 2.644,5');
        Volt::test('collections.show', ['collection' => $budget->id, 'viewMode' => 'board'])
            ->assertSee('Σ 144,5')
            ->assertSee('Σ 2.500');
        Volt::test('collections.show', ['collection' => $habits->id, 'viewMode' => 'table'])
            ->assertSeeInOrder(['1 / 2', '2 / 2', '0 / 2']);
    }

    public function test_an_entry_can_be_put_on_the_shopping_list(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $pantry = $this->createFromTemplate('pantry', 'Vorräte');
        $rice = $this->entry($pantry, 'Reis');

        Volt::test('collections.show', ['collection' => $pantry->id])->call('addToShoppingList', $rice->id)->assertDispatched('toast');

        $this->assertSame(['Reis'], ShoppingListEntry::pluck('label')->all());
    }
}
