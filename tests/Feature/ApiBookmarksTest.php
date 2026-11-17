<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for "Bookmarks & read later".
 */
class ApiBookmarksTest extends TestCase
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

    public function test_links_areas_read_state_and_wishlist(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $area = $this->postJson('/api/v1/bookmark-categories', ['name' => 'Rezepte', 'icon' => '🍳'], $headers)->assertCreated()->json('data');
        $id = $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/artikel', 'title' => 'Guter Artikel', 'category_id' => $area['id'], 'comment' => 'später lesen'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Guter Artikel')->assertJsonPath('data.read', false)->json('data.id');
        $this->postJson('/api/v1/bookmarks', ['url' => 'kein link'], $headers)->assertStatus(422);

        $this->getJson('/api/v1/bookmarks', $headers)->assertOk()->assertJsonPath('data.bookmarks.0.id', $id)->assertJsonPath('data.categories.0.count', 1);
        $this->postJson("/api/v1/bookmarks/{$id}/read", [], $headers)->assertOk()->assertJsonPath('data.read', true);
        $this->putJson("/api/v1/bookmarks/{$id}", ['comment' => 'neu', 'private' => true], $headers)->assertOk()->assertJsonPath('data.private', true)->assertJsonPath('data.comment', 'neu');
        $this->postJson("/api/v1/bookmarks/{$id}/wishlist", [], $headers)->assertCreated();
        $this->postJson("/api/v1/bookmarks/{$id}/place", [], $headers)->assertCreated();

        $this->deleteJson("/api/v1/bookmarks/{$id}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/bookmark-categories/{$area['id']}", [], $headers)->assertOk();
        $this->getJson('/api/v1/bookmarks', $headers)->assertJsonCount(0, 'data.bookmarks');
    }

    public function test_private_links_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $other = $this->memberWithHousehold()[0];
        $id = $this->postJson('/api/v1/bookmarks', ['url' => 'https://example.com/a', 'private' => true], $this->headersFor($user))->assertCreated()->json('data.id');

        $this->getJson('/api/v1/bookmarks', $this->headersFor($other))->assertJsonCount(0, 'data.bookmarks');
        $this->deleteJson("/api/v1/bookmarks/{$id}", [], $this->headersFor($other))->assertNotFound();
    }
}
