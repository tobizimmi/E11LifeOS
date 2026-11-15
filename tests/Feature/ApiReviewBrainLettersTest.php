<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\User;
use App\Support\BrainAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API: year in review, "ask your Second Brain" and letter templates.
 */
class ApiReviewBrainLettersTest extends TestCase
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

    public function test_the_year_in_review_defaults_to_this_year_and_ignores_the_future(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/review', $headers)->assertOk()->assertJsonPath('data.review.year', (int) now()->year);
        $this->getJson('/api/v1/review?year=2999', $headers)->assertOk()->assertJsonPath('data.review.year', (int) now()->year);
        $this->assertContains((int) now()->year, $this->getJson('/api/v1/review', $headers)->json('data.years'));
    }

    public function test_a_question_is_answered_with_titled_sources_and_actions_need_confirmation(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        [$user, $household] = $this->memberWithHousehold();
        $tasks = Collection::tasksFor($household->id);
        $task = Item::factory()->for($household)->create(['type' => Item::TYPE_ENTRY, 'collection_id' => $tasks->id, 'title' => 'Reifenwechsel buchen']);
        $headers = $this->headersFor($user);

        $this->mock(BrainAi::class, fn (MockInterface $ai) => $ai->shouldReceive('answer')->once()->andReturn([
            'answer' => 'Der Reifenwechsel fehlt noch.',
            'sources' => ['item:'.$task->id],
            'actions' => BrainAi::cleanActions([['type' => 'add_to_shopping_list', 'title' => '', 'due' => '', 'items' => ['Milch']]]),
        ]));

        $data = $this->postJson('/api/v1/brain/ask', ['question' => 'Was ist mit dem Reifenwechsel?'], $headers)->assertOk()->json('data');
        $this->assertSame('Reifenwechsel buchen', $data['sources'][0]['title']);
        $this->assertSame(0, ShoppingListEntry::count());

        $this->postJson('/api/v1/brain/action', ['action' => $data['actions'][0]], $headers)->assertOk();
        $this->assertSame(1, ShoppingListEntry::count());
        $this->postJson('/api/v1/brain/action', ['action' => ['type' => 'delete_everything', 'title' => 'x']], $headers)->assertStatus(422);
        $this->postJson('/api/v1/brain/ask', ['question' => 'ab'], $headers)->assertStatus(422);
    }

    public function test_an_empty_brain_says_so(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        [$user] = $this->memberWithHousehold();

        $this->postJson('/api/v1/brain/ask', ['question' => 'Gibt es etwas Neues?'], $this->headersFor($user))->assertStatus(422);
    }

    public function test_letters_are_filled_and_rendered_as_pdf(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $templates = $this->getJson('/api/v1/letters/templates', $headers)->assertOk()->json('data');
        $this->assertContains('cancellation', array_column($templates['templates'], 'key'));

        $this->postJson('/api/v1/letters/fill', ['template' => 'cancellation'], $headers)->assertOk()->assertJsonStructure(['data' => ['subject', 'body', 'recipient']]);
        $this->postJson('/api/v1/letters/fill', ['template' => 'nope'], $headers)->assertStatus(422);

        $response = $this->postJson('/api/v1/letters/pdf', ['sender' => 'Max Muster, Weg 1, 12345 Ort', 'recipient' => 'Strom AG', 'subject' => 'Kündigung', 'body' => 'Hiermit kündige ich.'], $headers)->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame('Max Muster, Weg 1, 12345 Ort', $user->fresh()->postal_address);
    }
}
