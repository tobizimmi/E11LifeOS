<?php

namespace Tests\Feature;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PageHierarchyTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_subpage_is_created_under_its_parent_with_the_parents_visibility(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $parent = Item::factory()->note()->for($household)->privateTo($user)->create(['title' => 'Urlaub']);

        Volt::test('pages.index', ['item' => $parent->id])
            ->call('createChild', $parent->id)
            ->assertSet('parentId', $parent->id)
            ->assertSet('isPrivate', true)
            ->set('title', 'Packliste')
            ->set('icon', '🧳')
            ->call('save')
            ->assertHasNoErrors();

        $child = Item::where('title', 'Packliste')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('🧳', $child->icon);
        $this->assertTrue($child->isPrivate());
    }

    public function test_the_sidebar_plus_link_prefills_a_new_subpage(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $parent = Item::factory()->note()->for($household)->create();

        $this->actingAs($user)
            ->get(route('pages.index', ['create' => 1, 'parent' => $parent->id]))
            ->assertOk()
            ->assertSee(__('New page'))
            ->assertSee('&quot;parentId&quot;:'.$parent->id.',', false);
    }

    public function test_a_page_cannot_be_moved_below_itself_or_its_descendants(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $root = Item::factory()->note()->for($household)->create();
        $child = Item::factory()->note()->for($household)->create(['parent_id' => $root->id]);
        $grandchild = Item::factory()->note()->for($household)->create(['parent_id' => $child->id]);

        foreach ([$root->id, $grandchild->id] as $invalidParentId) {
            Volt::test('pages.index')
                ->call('edit', $root->id)
                ->set('parentId', $invalidParentId)
                ->call('save')
                ->assertHasErrors('parentId');
        }

        $this->assertNull($root->fresh()->parent_id);
    }

    public function test_a_cycle_cannot_be_built_through_another_members_private_page(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $sharedTop = Item::factory()->note()->for($household)->create(['created_by' => $partner->id]);
        $hidden = Item::factory()->note()->for($household)->privateTo($owner)->create(['parent_id' => $sharedTop->id]);
        $sharedBelow = Item::factory()->note()->for($household)->create(['parent_id' => $hidden->id]);

        $this->actingAs($partner);

        Volt::test('pages.index')
            ->call('edit', $sharedTop->id)
            ->set('parentId', $sharedBelow->id)
            ->call('save')
            ->assertHasErrors('parentId');

        $this->assertNull($sharedTop->fresh()->parent_id);
    }

    public function test_a_page_cannot_be_placed_under_another_members_private_page(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = Item::factory()->note()->for($household)->privateTo($owner)->create();

        $this->actingAs($partner);

        Volt::test('pages.index')
            ->set('title', 'Versuch')
            ->set('parentId', $private->id)
            ->call('save')
            ->assertHasErrors('parentId');

        $this->assertDatabaseMissing('items', ['title' => 'Versuch']);
    }

    public function test_deleting_a_page_moves_its_subpages_up_one_level(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $root = Item::factory()->note()->for($household)->create();
        $middle = Item::factory()->note()->for($household)->create(['parent_id' => $root->id]);
        $leaf = Item::factory()->note()->for($household)->create(['parent_id' => $middle->id]);

        Volt::test('pages.index', ['item' => $middle->id])
            ->call('delete', $middle->id)
            ->assertRedirect(route('pages.index', ['item' => $root->id]));

        $this->assertDatabaseMissing('items', ['id' => $middle->id]);
        $this->assertSame($root->id, $leaf->fresh()->parent_id);
    }

    public function test_a_page_shows_its_breadcrumbs_and_subpages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $root = Item::factory()->note()->for($household)->create(['title' => 'Haus']);
        $middle = Item::factory()->note()->for($household)->create(['title' => 'Garten', 'parent_id' => $root->id]);
        Item::factory()->note()->for($household)->create(['title' => 'Hochbeet', 'parent_id' => $middle->id]);

        Volt::test('pages.index', ['item' => $middle->id])
            ->assertViewHas('breadcrumbs', fn (array $crumbs) => array_column($crumbs, 'title') === ['Haus'])
            ->assertViewHas('subpages', fn (array $subpages) => array_column($subpages, 'title') === ['Hochbeet'])
            ->assertSeeHtml('href="'.e(route('pages.index', ['item' => $root->id])).'"');
    }

    public function test_opening_a_page_the_user_cannot_see_returns_not_found(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = Item::factory()->note()->for($household)->privateTo($owner)->create();

        $this->actingAs($partner)->get(route('pages.index', ['item' => $private->id]))->assertNotFound();
        $this->actingAs($partner)->get('/pages/abc')->assertNotFound();
    }

    public function test_old_note_links_redirect_to_pages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $page = Item::factory()->note()->for($household)->create();

        $this->actingAs($user);

        $this->get('/notes?note='.$page->id)->assertRedirect(route('pages.index', ['item' => $page->id]));
        $this->get('/notes?new=Idee')->assertRedirect(route('pages.index', ['new' => 'Idee']));
        $this->get('/notes')->assertRedirect(route('pages.index'));
    }

    public function test_the_sidebar_shows_shared_and_own_private_pages_as_trees(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $shared = Item::factory()->note()->for($household)->create(['title' => 'Haushaltsordner']);
        Item::factory()->note()->for($household)->create(['title' => 'Versicherungen', 'parent_id' => $shared->id]);
        $ownersPrivate = Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Tagebuch']);
        Item::factory()->note()->for($household)->create(['title' => 'Unter fremder privater Seite', 'parent_id' => $ownersPrivate->id]);
        Item::factory()->note()->for($household)->privateTo($partner)->create(['title' => 'Meine Ideen']);

        $this->actingAs($partner);

        Volt::test('layout.navigation')
            ->assertViewHas('sharedRoots', fn (array $roots) => array_column($roots, 'title') === ['Haushaltsordner', 'Unter fremder privater Seite'])
            ->assertViewHas('privateRoots', fn (array $roots) => array_column($roots, 'title') === ['Meine Ideen'])
            ->assertSee('Versicherungen')
            ->assertDontSee('Tagebuch');
    }

    public function test_the_sidebar_expands_the_path_to_the_open_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $root = Item::factory()->note()->for($household)->create();
        $child = Item::factory()->note()->for($household)->create(['parent_id' => $root->id]);

        // The root is an ancestor of the open page, so its subtree starts expanded.
        $this->actingAs($user)
            ->get(route('pages.index', ['item' => $child->id]))
            ->assertOk()
            ->assertSee('wire:key="tree-'.$root->id.'" x-data="{ open: true }"', false)
            ->assertSee('wire:key="tree-'.$child->id.'" x-data="{ open: true }"', false);
    }
}
