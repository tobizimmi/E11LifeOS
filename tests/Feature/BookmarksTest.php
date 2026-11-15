<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\BookmarkCategory;
use App\Models\Item;
use App\Models\Place;
use App\Models\WishlistItem;
use App\Support\BookmarkAreas;
use App\Support\LinkPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class BookmarksTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_link_preview_parses_opengraph_metadata_and_images(): void
    {
        $html = <<<'HTML'
            <html><head>
            <title>Fallback Title</title>
            <meta property="og:title" content="Great Article">
            <meta property="og:description" content="A very interesting read.">
            <meta property="og:site_name" content="Example Mag">
            <meta property="og:image" content="/img/cover.jpg">
            <meta name="twitter:image" content="https://cdn.example.com/social.png">
            <link rel="icon" href="/favicon.png">
            </head><body>…</body></html>
        HTML;

        $meta = LinkPreview::parse($html, 'https://example.com/articles/42');

        $this->assertSame('Great Article', $meta['title']);
        $this->assertSame('A very interesting read.', $meta['description']);
        $this->assertSame('Example Mag', $meta['site_name']);
        $this->assertSame('https://example.com/favicon.png', $meta['favicon']);
        $this->assertContains('https://example.com/img/cover.jpg', $meta['images']); // made absolute
        $this->assertContains('https://cdn.example.com/social.png', $meta['images']);
    }

    public function test_a_bookmark_is_saved_with_a_comment_and_area(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $category = BookmarkCategory::factory()->for($household)->create(['name' => 'Einkäufe']);

        Volt::test('bookmarks.index')
            ->set('url', 'https://shop.example.com/product/123')
            ->set('title', 'Cooler Artikel')
            ->set('description', 'Schöne Lampe')
            ->set('comment', 'Zum Geburtstag')
            ->set('categoryId', (string) $category->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('adding', false);

        $bookmark = Bookmark::sole();
        $this->assertSame('Cooler Artikel', $bookmark->title);
        $this->assertSame('Zum Geburtstag', $bookmark->comment);
        $this->assertSame($category->id, $bookmark->category_id);
        $this->assertSame(Item::VISIBILITY_SHARED, $bookmark->visibility);
    }

    public function test_an_invalid_url_is_rejected(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('bookmarks.index')->set('url', 'not-a-url')->call('save')->assertHasErrors('url');
        $this->assertSame(0, Bookmark::count());
    }

    public function test_categories_can_be_added_and_removed_without_losing_links(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('bookmarks.index')->set('newCategory', 'Zeitschriften')->call('addCategory');
        $category = BookmarkCategory::where('name', 'Zeitschriften')->sole();

        $bookmark = Bookmark::factory()->for($household)->create(['category_id' => $category->id]);

        $component->call('deleteCategory', $category->id);

        $this->assertNull(BookmarkCategory::find($category->id));
        $this->assertNull($bookmark->fresh()->category_id); // the link stays, just loses its area
    }

    public function test_marking_a_bookmark_as_read(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $bookmark = Bookmark::factory()->for($household)->create(['is_read' => false]);

        Volt::test('bookmarks.index')->call('toggleRead', $bookmark->id);

        $this->assertTrue($bookmark->fresh()->is_read);
        $this->assertNotNull($bookmark->fresh()->read_at);
    }

    public function test_the_area_is_suggested_from_the_link(): void
    {
        $this->assertSame('Videos', BookmarkAreas::suggest('https://www.youtube.com/watch?v=abc')['name']);
        $this->assertSame('Rezepte', BookmarkAreas::suggest('https://www.chefkoch.de/rezepte/123')['name']);
        $this->assertSame('Einkäufe', BookmarkAreas::suggest('https://www.amazon.de/dp/B0')['name']);
        $this->assertSame('Reise', BookmarkAreas::suggest('https://www.booking.com/hotel/x')['name']);
        $this->assertNull(BookmarkAreas::suggest('https://example.com/misc', 'Nichts Besonderes'));
    }

    public function test_the_suggest_area_button_selects_or_creates_the_area(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('bookmarks.index')
            ->set('url', 'https://www.youtube.com/watch?v=abc')
            ->call('suggestArea')
            ->assertSet('categoryId', fn ($id) => $id !== null);

        $this->assertDatabaseHas('bookmark_categories', ['household_id' => $household->id, 'name' => 'Videos']);
    }

    public function test_a_bookmark_can_be_added_to_the_wishlist(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $bookmark = Bookmark::factory()->for($household)->create(['title' => 'Schöne Lampe', 'url' => 'https://shop.example.com/lampe']);

        Volt::test('bookmarks.index')->call('toWishlist', $bookmark->id);

        $wish = WishlistItem::sole();
        $this->assertSame('Schöne Lampe', $wish->title);
        $this->assertSame('https://shop.example.com/lampe', $wish->url);
    }

    public function test_a_bookmark_can_be_saved_as_a_place(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $bookmark = Bookmark::factory()->for($household)->create(['title' => 'Hotel Schön', 'url' => 'https://hotel.example.com', 'comment' => 'Für den Sommer']);

        Volt::test('bookmarks.index')->call('saveAsPlace', $bookmark->id);

        $place = Place::sole();
        $this->assertSame('Hotel Schön', $place->name);
        $this->assertSame('https://hotel.example.com', $place->url);
        $this->assertSame('Für den Sommer', $place->notes);
    }

    public function test_private_bookmarks_are_hidden_from_other_members(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $other = $this->addMemberTo($household);

        Bookmark::factory()->for($household)->create(['title' => 'Shared read']);
        Bookmark::factory()->for($household)->privateTo($owner)->create(['title' => 'Owner only']);

        $this->actingAs($other);
        Volt::test('bookmarks.index')->assertSee('Shared read')->assertDontSee('Owner only');
    }
}
