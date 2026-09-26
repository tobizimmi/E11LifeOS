<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\Recipe;
use App\Support\AiException;
use App\Support\BrainAi;
use App\Support\BrainSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SecondBrainAiTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);
    }

    public function test_the_search_finds_matching_pages_entries_and_recipes_the_member_may_see(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [, $otherHousehold] = $this->memberWithHousehold();

        $page = Item::factory()->note()->for($household)->create(['title' => 'Autoversicherung', 'content' => ['body' => 'Kündigung bis 30.11.']]);
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Auflauf', 'description' => 'Für die Autofahrt vorbereiten']);
        Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Auto verkaufen (geheim)']);
        Item::factory()->note()->for($otherHousehold)->create(['title' => 'Fremde Autoversicherung']);

        $documents = BrainSearch::documents('Wann endet die Autoversicherung?', $partner, $household->id);
        $keys = array_column($documents, 'key');

        $this->assertSame('item:'.$page->id, $keys[0]);
        $this->assertStringContainsString('Kündigung bis 30.11.', $documents[0]['text']);
        $this->assertNotContains('item:'.Item::where('title', 'Auto verkaufen (geheim)')->value('id'), $keys);
        $this->assertNotContains('item:'.Item::where('title', 'Fremde Autoversicherung')->value('id'), $keys);

        $this->assertContains('recipe:'.$recipe->id, array_column(BrainSearch::documents('Autofahrt Essen', $partner, $household->id), 'key'));
    }

    public function test_a_question_is_answered_with_links_to_the_sources_used(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $tasks->id, 'title' => 'Reifenwechsel buchen']);

        $this->mock(BrainAi::class, fn (MockInterface $ai) => $ai->shouldReceive('answer')
            ->once()
            ->withArgs(fn (string $question, array $documents) => $question === 'Was ist mit dem Reifenwechsel?' && $documents[0]['key'] === 'item:'.$task->id)
            ->andReturn(['answer' => 'Der **Reifenwechsel** muss noch gebucht werden.', 'sources' => ['item:'.$task->id]]));

        Volt::test('brain.ask')
            ->set('question', 'Was ist mit dem Reifenwechsel?')
            ->call('ask')
            ->assertHasNoErrors()
            ->assertSet('question', '')
            ->assertSeeHtml('<strong>Reifenwechsel</strong>')
            ->assertSeeHtml('href="'.route('items.show', $task).'"');
    }

    public function test_answers_never_link_sources_the_member_cannot_see_and_failures_are_shown(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $secret = Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Geheimnis']);
        Item::factory()->note()->for($household)->create(['title' => 'Urlaub']);
        $this->actingAs($partner);

        $this->mock(BrainAi::class, function (MockInterface $ai) use ($secret) {
            $ai->shouldReceive('answer')->once()->andReturn(['answer' => 'Antwort', 'sources' => ['item:'.$secret->id]]);
            $ai->shouldReceive('answer')->once()->andThrow(new AiException('Gerade nicht erreichbar.'));
        });

        Volt::test('brain.ask')
            ->set('question', 'Was steht an?')->call('ask')
            ->assertSee('Antwort')
            ->assertDontSee('Geheimnis')
            ->set('question', 'Und sonst?')->call('ask')
            ->assertSee('Gerade nicht erreichbar.');
    }

    public function test_without_an_api_key_the_page_explains_that_the_ai_is_not_set_up(): void
    {
        config(['services.anthropic.key' => null]);
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->get(route('brain.ask'))
            ->assertOk()
            ->assertSee('ANTHROPIC_API_KEY');
    }

    public function test_suggested_tags_can_be_added_to_pages_and_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);
        $entry = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $tasks->id, 'title' => 'Hecke schneiden']);
        $entry->syncTagNames(['garten']);

        $this->mock(BrainAi::class, fn (MockInterface $ai) => $ai->shouldReceive('suggestTags')->andReturn(['garten', 'wochenende']));

        Volt::test('pages.index')
            ->set('title', 'Gartenplan')
            ->set('tagsInput', 'Garten')
            ->call('suggestTags')
            ->assertSet('tagSuggestions', ['wochenende'])
            ->call('addSuggestedTag', 'wochenende')
            ->assertSet('tagsInput', 'Garten, wochenende')
            ->assertSet('tagSuggestions', []);

        Volt::test('collections.show', ['collection' => $tasks->id])
            ->call('openEntry', $entry->id)
            ->call('suggestEntryTags', $entry->id)
            ->assertSet('entryTagSuggestions', ['wochenende'])
            ->call('addEntryTag', $entry->id, 'wochenende');

        $this->assertSame(['garten', 'wochenende'], $entry->fresh()->tags->pluck('name')->sort()->values()->all());
    }
}
