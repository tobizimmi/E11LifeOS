<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * The API that lets the native app read and write the generic collections/items (notes, pantry,
 * custom databases) and portfolio positions offline.
 */
class ApiOfflineModulesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_collection_items_are_listed_created_updated_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $collection = Collection::factory()->create(['household_id' => $household->id, 'name' => 'Vorräte', 'created_by' => $user->id]);
        $amount = CollectionProperty::create(['collection_id' => $collection->id, 'name' => 'Menge', 'type' => CollectionProperty::TYPE_NUMBER, 'position' => 0]);

        $this->getJson('/api/v1/collections', $headers)->assertOk()->assertJsonPath('data.0.name', 'Vorräte');

        $id = $this->postJson("/api/v1/collections/{$collection->id}/items", [
            'title' => 'Mehl',
            'properties' => [$amount->id => 2],
        ], $headers)->assertCreated()->assertJsonPath('data.title', 'Mehl')->json('data.id');

        $this->getJson("/api/v1/collections/{$collection->id}/items", $headers)->assertOk()
            ->assertJsonPath('data.collection.properties.0.name', 'Menge')
            ->assertJsonPath('data.items.0.title', 'Mehl')
            ->assertJsonPath("data.items.0.properties.{$amount->id}", 2);

        $this->putJson("/api/v1/items/{$id}", ['title' => 'Dinkelmehl', 'properties' => [$amount->id => 5]], $headers)
            ->assertOk()->assertJsonPath('data.title', 'Dinkelmehl')->assertJsonPath("data.properties.{$amount->id}", 5);

        $this->deleteJson("/api/v1/items/{$id}", [], $headers)->assertOk();
        $this->assertNull(Item::find($id));
    }

    public function test_collections_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $foreign = Collection::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id]);
        $foreignItem = Item::factory()->create(['household_id' => $otherHousehold->id, 'collection_id' => $foreign->id, 'created_by' => $other->id]);

        $this->getJson("/api/v1/collections/{$foreign->id}/items", $headers)->assertNotFound();
        $this->postJson("/api/v1/collections/{$foreign->id}/items", ['title' => 'X'], $headers)->assertNotFound();
        $this->putJson("/api/v1/items/{$foreignItem->id}", ['title' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/items/{$foreignItem->id}", [], $headers)->assertNotFound();
    }

    public function test_portfolio_positions_are_created_updated_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Depot']);

        $id = $this->postJson("/api/v1/portfolios/{$portfolio->id}/positions", [
            'symbol' => 'SAP.DE',
            'name' => 'SAP',
            'quantity' => 10,
            'avg_buy_price' => 120.5,
        ], $headers)->assertCreated()->assertJsonPath('data.manual', true)->assertJsonPath('data.symbol', 'SAP.DE')->json('data.id');

        $this->putJson("/api/v1/positions/{$id}", ['quantity' => 15], $headers)->assertOk()->assertJsonPath('data.quantity', 15);
        $this->assertSame(15.0, (float) PortfolioPosition::find($id)->quantity);
        $this->deleteJson("/api/v1/positions/{$id}", [], $headers)->assertOk();
        $this->assertNull(PortfolioPosition::find($id));
    }

    public function test_positions_of_another_user_are_out_of_reach(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $otherHousehold->setAddon('portfolio', true);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $foreign = Portfolio::factory()->for($other)->create();
        $position = $foreign->positions()->create(['symbol' => 'AAPL', 'quantity' => 1, 'avg_buy_price' => 100, 'manual' => true]);

        $this->postJson("/api/v1/portfolios/{$foreign->id}/positions", ['symbol' => 'X', 'quantity' => 1, 'avg_buy_price' => 1], $headers)->assertNotFound();
        $this->putJson("/api/v1/positions/{$position->id}", ['quantity' => 9], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/positions/{$position->id}", [], $headers)->assertNotFound();
    }
}
