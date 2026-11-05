<?php

namespace Tests\Feature;

use App\Models\WishlistItem;
use App\Support\PriceFinder;
use App\Support\WishlistPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class WishlistPriceTrackingTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_price_finder_reads_structured_data_and_parses_formats(): void
    {
        $jsonLd = '<html><head><script type="application/ld+json">'
            .'{"@type":"Product","name":"X","offers":{"@type":"Offer","price":"129.99","priceCurrency":"EUR"}}'
            .'</script></head></html>';
        $this->assertSame(['price' => 129.99, 'currency' => 'EUR'], PriceFinder::fromHtml($jsonLd));

        $meta = '<meta property="product:price:amount" content="99,90"><meta property="product:price:currency" content="eur">';
        $this->assertSame(['price' => 99.90, 'currency' => 'EUR'], PriceFinder::fromHtml($meta));

        $this->assertNull(PriceFinder::fromHtml('<p>no price here</p>'));

        $this->assertSame(1299.0, PriceFinder::parsePrice('1.299,00 €'));
        $this->assertSame(1299.0, PriceFinder::parsePrice('1,299.00'));
        $this->assertSame(49.95, PriceFinder::parsePrice('49,95'));
        $this->assertSame(129.99, PriceFinder::parsePrice('129.99'));
        $this->assertNull(PriceFinder::parsePrice('kostenlos'));
    }

    public function test_refresh_stores_price_and_notifies_on_a_drop(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Http::fake(['shop.test/*' => Http::response('<script type="application/ld+json">{"@type":"Product","offers":{"price":"89.99","priceCurrency":"EUR"}}</script>', 200)]);

        $item = WishlistItem::factory()->create([
            'household_id' => $household->id,
            'created_by' => $user->id,
            'title' => 'Kopfhörer',
            'price' => 120,
            'url' => 'https://shop.test/kopfhoerer',
            'status' => 'open',
        ]);

        $this->assertTrue(WishlistPrices::refresh($item));

        $item->refresh();
        $this->assertSame(89.99, (float) $item->tracked_price);
        $this->assertSame(89.99, (float) $item->lowest_price);
        $this->assertSame('ok', $item->price_check_status);
        $this->assertNotNull($item->price_checked_at);
        // The online price (89.99) is well below the saved price (120) → the creator is notified.
        $this->assertSame(1, $user->fresh()->notifications()->count());
    }

    public function test_failed_lookup_is_recorded_without_a_price(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Http::fake(['shop.test/*' => Http::response('<p>Seite ohne Preis</p>', 200)]);

        $item = WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'price' => 50, 'url' => 'https://shop.test/x', 'status' => 'open']);

        $this->assertFalse(WishlistPrices::refresh($item));
        $item->refresh();
        $this->assertNull($item->tracked_price);
        $this->assertSame('failed', $item->price_check_status);
    }

    public function test_scheduled_command_checks_open_wishes_with_a_link(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Http::fake(['shop.test/*' => Http::response('<meta property="product:price:amount" content="19.99"><meta property="product:price:currency" content="EUR">', 200)]);

        WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'url' => 'https://shop.test/a', 'status' => 'open', 'price' => 30]);
        WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'url' => null, 'status' => 'open']);

        $this->artisan('wishlist:check-prices')->expectsOutputToContain('Wishlist prices checked: 1')->assertSuccessful();
    }

    public function test_wishlist_can_be_filtered_by_category(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Laptop', 'category' => 'tech', 'status' => 'open']);
        WishlistItem::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Sofa', 'category' => 'home', 'status' => 'open']);

        Volt::test('wishlist.index')
            ->assertSee('Laptop')
            ->assertSee('Sofa')
            ->set('filterCategory', 'tech')
            ->assertSee('Laptop')
            ->assertDontSee('Sofa');
    }
}
