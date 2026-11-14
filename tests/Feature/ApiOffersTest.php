<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\ShoppingListEntry;
use App\Models\ShoppingWatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the supermarket offers.
 */
class ApiOffersTest extends TestCase
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

    public function test_settings_and_the_watch_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->putJson('/api/v1/shopping/offers/settings', ['zip' => '1234'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/shopping/offers/settings', ['zip' => '10115', 'chains' => ['rewe', 'lidl']], $headers)
            ->assertOk()->assertJsonPath('data.zip', '10115')->assertJsonPath('data.chains', ['rewe', 'lidl']);
        $this->putJson('/api/v1/shopping/offers/settings', ['zip' => '10115', 'chains' => ['other']], $headers)->assertStatus(422);
        $this->assertSame(['rewe', 'lidl'], Household::find($household->id)->offers_chains);

        $data = $this->getJson('/api/v1/shopping/offers/settings', $headers)->assertOk()->json('data');
        $this->assertNotContains('other', array_column($data['all_chains'], 'key'));

        $watches = $this->postJson('/api/v1/shopping/offers/watches', ['term' => ' Kaffee '], $headers)->assertCreated()->json('data.watches');
        $this->assertSame('Kaffee', $watches[0]['term']);
        $this->postJson('/api/v1/shopping/offers/watches', ['term' => 'Kaffee'], $headers)->assertCreated();
        $this->assertSame(1, ShoppingWatch::count());
        $this->postJson('/api/v1/shopping/offers/watches', ['term' => 'x'], $headers)->assertStatus(422);

        $this->deleteJson("/api/v1/shopping/offers/watches/{$watches[0]['id']}", [], $headers)->assertOk()->assertJsonCount(0, 'data.watches');

        [$other] = $this->memberWithHousehold();
        $this->getJson('/api/v1/shopping/offers/settings', $this->headersFor($other))->assertOk()->assertJsonPath('data.zip', null)->assertJsonCount(0, 'data.watches');
    }

    public function test_offers_are_searched_and_put_on_the_shopping_list(): void
    {
        Cache::put('offers:keys', ['api', 'client'], now()->addDay());
        Http::fake(['api.marktguru.de/*' => Http::response(['results' => [
            ['id' => 1, 'product' => ['name' => 'Kaffee gemahlen'], 'brand' => ['name' => 'Jacobs'], 'advertisers' => [['name' => 'REWE Center']], 'price' => 3.99, 'oldPrice' => 5.49, 'quantity' => 500, 'unit' => ['shortName' => 'g'], 'validityDates' => [['to' => today()->addDays(3)->toDateString().'T00:00:00Z']]],
            ['id' => 2, 'product' => ['name' => 'Kaffee Bohnen'], 'advertisers' => [['name' => 'Lidl']], 'price' => 4.49, 'validityDates' => [['to' => today()->addDays(3)->toDateString().'T00:00:00Z']]],
        ]])]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/shopping/offers?q=Kaffee', $headers)->assertOk()->assertJsonPath('data.zip_missing', true);

        $this->putJson('/api/v1/shopping/offers/settings', ['zip' => '10115', 'chains' => ['rewe']], $headers)->assertOk();
        $data = $this->getJson('/api/v1/shopping/offers?q=Kaffee', $headers)->assertOk()->json('data');
        $this->assertFalse($data['zip_missing']);
        $this->assertCount(1, $data['sections']);
        $this->assertCount(1, $data['sections'][0]['offers']);
        $offer = $data['sections'][0]['offers'][0];
        $this->assertSame('Kaffee gemahlen', $offer['title']);
        $this->assertEquals(3.99, $offer['price']);
        $this->assertSame('500 g', $offer['unit']);

        $id = $this->postJson('/api/v1/shopping/offers/add', ['title' => $offer['title'], 'brand' => $offer['brand'], 'store' => $offer['store'], 'price' => $offer['price'], 'unit' => $offer['unit'], 'valid_to' => $offer['valid_to']], $headers)
            ->assertCreated()->assertJsonPath('data.label', 'Jacobs Kaffee gemahlen')->json('data.id');
        $entry = ShoppingListEntry::find($id);
        $this->assertStringContainsString('REWE Center', (string) $entry->note);
        $this->assertEquals(3.99, $entry->price);
        $this->postJson('/api/v1/shopping/offers/add', ['title' => ''], $headers)->assertStatus(422);
    }

    public function test_an_unreachable_offers_service_answers_503(): void
    {
        Cache::forget('offers:keys');
        Http::fake(['*' => Http::response('', 500)]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $this->putJson('/api/v1/shopping/offers/settings', ['zip' => '10115'], $headers)->assertOk();

        $this->getJson('/api/v1/shopping/offers?q=Milch', $headers)->assertStatus(503)->assertJsonPath('success', false);
    }
}
