<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PublicWikiTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_published_page_is_readable_with_its_shared_subtree_but_not_private_subpages(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $root = Item::factory()->create([
            'household_id' => $household->id, 'type' => Item::TYPE_NOTE,
            'title' => 'Handbuch', 'visibility' => Item::VISIBILITY_SHARED,
            'content' => ['body' => '# Willkommen'],
        ]);
        $sharedChild = Item::factory()->create([
            'household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'parent_id' => $root->id,
            'title' => 'Kapitel 1', 'visibility' => Item::VISIBILITY_SHARED, 'content' => ['body' => 'Inhalt K1'],
        ]);
        $privateChild = Item::factory()->create([
            'household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'parent_id' => $root->id,
            'title' => 'Geheim', 'visibility' => Item::VISIBILITY_PRIVATE, 'content' => ['body' => 'Streng geheim'],
        ]);

        $token = Str::random(40);
        $root->forceFill(['public_token' => $token])->save();

        $this->get(route('pages.public', $token))
            ->assertOk()
            ->assertSee('Handbuch')
            ->assertSee('Willkommen')
            ->assertSee('Kapitel 1')
            ->assertDontSee('Geheim')
            ->assertDontSee('Streng geheim');

        // A shared subpage opens within the same public link.
        $this->get(route('pages.public', $token).'?p='.$sharedChild->id)
            ->assertOk()
            ->assertSee('Inhalt K1');

        // A private subpage cannot be reached even by id.
        $this->get(route('pages.public', $token).'?p='.$privateChild->id)
            ->assertOk()
            ->assertDontSee('Streng geheim');
    }

    public function test_an_unknown_or_expired_token_is_not_found(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $page = Item::factory()->create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Seite']);
        $token = Str::random(40);
        $page->forceFill(['public_token' => $token, 'public_expires_at' => now()->subDay()])->save();

        $this->get(route('pages.public', $token))->assertNotFound();
        $this->get(route('pages.public', Str::random(40)))->assertNotFound();
    }
}
