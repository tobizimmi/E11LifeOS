<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tag;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NotesIndexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_create_a_note(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')
            ->set('title', 'Geschenkidee Oma')
            ->set('body', 'Buch über Gartenarbeit')
            ->set('tagsInput', 'geschenk, oma')
            ->call('save');

        $this->assertDatabaseHas('items', [
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Geschenkidee Oma',
            'created_by' => $user->id,
        ]);

        $note = Item::where('title', 'Geschenkidee Oma')->firstOrFail();
        $this->assertSame(['geschenk', 'oma'], $note->tags->pluck('name')->sort()->values()->all());
        $this->assertSame('Buch über Gartenarbeit', $note->content['body']);
    }

    public function test_a_member_can_edit_and_delete_their_note(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $note = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Alter Titel',
            'content' => ['body' => 'Alter Inhalt'],
            'created_by' => $user->id,
        ]);

        Volt::test('notes.index')
            ->call('edit', $note->id)
            ->set('title', 'Neuer Titel')
            ->call('save');

        $this->assertDatabaseHas('items', ['id' => $note->id, 'title' => 'Neuer Titel']);

        Volt::test('notes.index')->call('delete', $note->id);

        $this->assertDatabaseMissing('items', ['id' => $note->id]);
    }

    public function test_search_filters_notes_by_title(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Urlaubsideen', 'created_by' => $user->id]);
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Einkaufsliste Gedanken', 'created_by' => $user->id]);

        $component = Volt::test('notes.index')->set('search', 'Urlaub');

        $component->assertSee('Urlaubsideen');
        $component->assertDontSee('Einkaufsliste Gedanken');
    }

    public function test_a_member_cannot_see_notes_from_another_household(): void
    {
        [$user] = $this->memberWithHousehold();
        [$otherUser, $otherHousehold] = $this->memberWithHousehold();

        Item::create(['household_id' => $otherHousehold->id, 'type' => Item::TYPE_NOTE, 'title' => 'Fremde Notiz', 'created_by' => $otherUser->id]);

        $this->actingAs($user);

        Volt::test('notes.index')->assertDontSee('Fremde Notiz');
    }

    public function test_tags_are_normalized_and_shared_within_the_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')->set('title', 'Eins')->set('tagsInput', '#Urlaub, urlaub , Reise')->call('save');
        Volt::test('notes.index')->set('title', 'Zwei')->set('tagsInput', 'URLAUB')->call('save');

        $this->assertSame(['reise', 'urlaub'], Tag::where('household_id', $household->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['reise', 'urlaub'], Item::where('title', 'Eins')->firstOrFail()->tags->pluck('name')->sort()->values()->all());
    }

    public function test_filtering_by_tag_shows_only_tagged_notes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $tagged = Item::factory()->note()->for($household)->create(['title' => 'Strandurlaub']);
        $tagged->syncTagNames(['urlaub']);
        Item::factory()->note()->for($household)->create(['title' => 'Steuererklärung']);

        Volt::test('notes.index')
            ->call('filterByTag', 'urlaub')
            ->assertSee('Strandurlaub')
            ->assertDontSee('Steuererklärung');
    }

    public function test_private_notes_are_only_visible_to_their_creator(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $this->actingAs($owner);
        Volt::test('notes.index')->set('title', 'Geheime Geschenkidee')->set('isPrivate', true)->set('tagsInput', 'überraschung')->call('save');

        $note = Item::where('title', 'Geheime Geschenkidee')->firstOrFail();
        $this->assertTrue($note->isPrivate());

        Volt::test('notes.index')->assertSee('Geheime Geschenkidee');

        $this->actingAs($partner);
        Volt::test('notes.index')
            ->assertDontSee('Geheime Geschenkidee')
            ->assertDontSee('überraschung');

        $this->assertThrows(fn () => Volt::test('notes.index')->call('edit', $note->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => Volt::test('notes.index')->call('delete', $note->id), ModelNotFoundException::class);
        $this->assertDatabaseHas('items', ['id' => $note->id]);
    }

    public function test_only_the_creator_can_change_the_visibility_of_a_note(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $note = Item::factory()->note()->for($household)->create(['created_by' => $owner->id]);

        $this->actingAs($partner);
        Volt::test('notes.index')->call('edit', $note->id)->set('isPrivate', true)->call('save');

        $this->assertFalse($note->fresh()->isPrivate());
    }

    public function test_note_markdown_is_rendered_without_raw_html(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $note = Item::factory()->note()->for($household)->create([
            'title' => 'Formatiert',
            'content' => ['body' => "**Wichtig**\n\n<script>alert('x')</script>\n\n[Klick](javascript:alert(1))"],
        ]);

        Volt::test('notes.index', ['selectedId' => $note->id])
            ->assertSeeHtml('<strong>Wichtig</strong>')
            ->assertDontSeeHtml('<script>alert')
            ->assertDontSeeHtml('javascript:alert');
    }

    public function test_wiki_links_point_to_existing_notes_or_offer_to_create_missing_ones(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $target = Item::factory()->note()->for($household)->create(['title' => 'Rezepte']);
        $source = Item::factory()->note()->for($household)->create([
            'title' => 'Wochenplan',
            'content' => ['body' => 'Siehe [[rezepte|unsere Rezepte]] und [[Urlaub 2027]].'],
        ]);

        Volt::test('notes.index', ['selectedId' => $source->id])
            ->assertSeeHtml('href="'.e(route('notes.index', ['note' => $target->id])).'"')
            ->assertSee('unsere Rezepte')
            ->assertSeeHtml('href="'.e(route('notes.index', ['new' => 'Urlaub 2027'])).'"');
    }

    public function test_wiki_links_do_not_reveal_other_members_private_notes(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $private = Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Tagebuch']);
        $source = Item::factory()->note()->for($household)->create(['content' => ['body' => '[[Tagebuch]]']]);

        $this->actingAs($partner);

        Volt::test('notes.index', ['selectedId' => $source->id])
            ->assertDontSeeHtml('note='.$private->id)
            ->assertSeeHtml('href="'.e(route('notes.index', ['new' => 'Tagebuch'])).'"');
    }

    public function test_a_note_shows_the_notes_linking_to_it(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $target = Item::factory()->note()->for($household)->create(['title' => 'Rezepte']);
        Item::factory()->note()->for($household)->create(['title' => 'Wochenplan', 'content' => ['body' => 'Heute aus [[Rezepte]] kochen']]);
        Item::factory()->note()->for($household)->create(['title' => 'Einkaufsideen', 'content' => ['body' => 'Rezepte ohne Link']]);

        Volt::test('notes.index', ['selectedId' => $target->id])
            ->assertViewHas('backlinks', fn ($backlinks) => $backlinks->pluck('title')->all() === ['Wochenplan']);
    }

    public function test_a_missing_wiki_link_prefills_the_new_note_form(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)
            ->get(route('notes.index', ['new' => 'Urlaub 2027']))
            ->assertOk()
            ->assertSee('Urlaub 2027');
    }

    public function test_the_new_note_button_in_the_header_opens_the_form(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('notes.index')
            ->assertSet('showForm', false)
            ->dispatch('create-note')
            ->assertSet('showForm', true);
    }
}
