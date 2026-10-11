<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\CollectionView;
use App\Models\Item;
use App\Support\NoteMarkdown;
use App\Support\TaskPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PageBlocksTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_callouts_toggles_and_highlights_are_rendered_safely(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $body = implode("\n", [
            ':::callout {type="warning"}',
            '',
            'Achtung: ==Frist== am **Freitag**',
            '',
            '- [ ] Unterlagen <script>alert(1)</script>',
            '',
            ':::',
            '',
            ':::toggle {title="Mehr <b>Infos</b>"}',
            '',
            'Versteckter Text',
            '',
            ':::',
            '',
            '```',
            ':::callout {type="tip"}',
            'a == b == c',
            '```',
        ]);
        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'content' => ['body' => $body]]);

        $html = NoteMarkdown::toHtml($page, $user, interactiveTasks: true);

        $this->assertStringContainsString('<div class="callout callout-warning" role="note"><span class="callout-icon" aria-hidden="true">⚠️</span>', $html);
        $this->assertStringContainsString('Achtung: <mark>Frist</mark> am <strong>Freitag</strong>', $html);
        $this->assertStringContainsString('wire:click.prevent="toggleTask(0)"', $html);
        $this->assertStringContainsString('<summary>Mehr &lt;b&gt;Infos&lt;/b&gt;</summary>', $html);
        $this->assertStringContainsString(':::callout {type=&quot;tip&quot;}', $html);
        $this->assertStringContainsString('a == b == c', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertSame(str_replace('- [ ]', '- [x]', $body), NoteMarkdown::toggleTask($body, 0));
    }

    public function test_tasks_calendar_and_subpages_can_be_embedded_in_a_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Projekt', 'content' => ['body' => ":::embed {type=\"tasks\"} :::\n\n:::embed {type=\"calendar\"} :::\n\n:::embed {type=\"subpages\"} :::"]]);
        Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Kapitel 1', 'parent_id' => $page->id]);
        $task = TaskPages::createOnPage($page, $user, 'Angebot einholen morgen');

        Volt::test('pages.index', ['item' => $page->id])
            ->assertSee('Aufgaben dieser Seite')->assertSee('Angebot einholen')->assertSeeHtml('togglePageTask('.$task->id.')')
            ->assertSee('Nächste 14 Tage')->assertSee('Kapitel 1')
            ->call('togglePageTask', $task->id);

        $this->assertTrue($task->fresh()->done);
    }

    public function test_a_collection_can_be_embedded_in_a_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $collection = Collection::factory()->for($household)->create(['name' => 'Verträge', 'icon' => '📑']);
        $status = $collection->properties()->create(['name' => 'Status', 'type' => CollectionProperty::TYPE_SELECT, 'options' => [['id' => 'open', 'name' => 'Offen', 'color' => 'blue'], ['id' => 'done', 'name' => 'Erledigt', 'color' => 'green']], 'position' => 0]);
        $open = Item::factory()->for($collection)->for($household)->create(['type' => Item::TYPE_ENTRY, 'title' => 'Stromvertrag']);
        $open->setPropertyValue($status, 'open');
        $open->save();
        $closed = Item::factory()->for($collection)->for($household)->create(['type' => Item::TYPE_ENTRY, 'title' => 'Gasvertrag']);
        $closed->setPropertyValue($status, 'done');
        $closed->save();

        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'content' => ['body' => ':::embed {type="collection" collection="'.$collection->id.'"} :::']]);

        $html = NoteMarkdown::toHtml($page, $user);

        $this->assertStringContainsString('Verträge', $html);
        $this->assertStringContainsString('Stromvertrag', $html);
        $this->assertStringContainsString('Gasvertrag', $html);
        $this->assertStringContainsString(route('collections.show', ['collection' => $collection->id, 'entry' => $open->id]), $html);
        $this->assertStringContainsString(route('collections.show', $collection->id), $html);

        // A saved view's filter and sort are applied the same way they are in the full collection.
        $view = CollectionView::create(['collection_id' => $collection->id, 'name' => 'Offene', 'settings' => [
            'filters' => [['property' => (string) $status->id, 'operator' => 'is', 'value' => 'open']],
        ]]);
        $filteredPage = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'content' => ['body' => ':::embed {type="collection" collection="'.$collection->id.'" view="'.$view->id.'"} :::']]);

        $filteredHtml = NoteMarkdown::toHtml($filteredPage, $user);

        $this->assertStringContainsString('Stromvertrag', $filteredHtml);
        $this->assertStringNotContainsString('Gasvertrag', $filteredHtml);

        // A collection the viewer cannot see degrades gracefully instead of leaking data or erroring.
        [$stranger, $strangerHousehold] = $this->memberWithHousehold();
        $foreignCollection = Collection::factory()->for($strangerHousehold)->create(['name' => 'Geheim']);
        $foreignPage = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'content' => ['body' => ':::embed {type="collection" collection="'.$foreignCollection->id.'"} :::']]);

        $foreignHtml = NoteMarkdown::toHtml($foreignPage, $user);

        $this->assertStringContainsString('Diese Sammlung ist nicht mehr verfügbar.', $foreignHtml);
        $this->assertStringNotContainsString('Geheim', $foreignHtml);
    }
}
