<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\LoyaltyCard;
use App\Models\Place;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the everyday modules: loyalty cards, wishlist, journal, people and places.
 */
class ApiEverydayModulesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_loyalty_cards_are_listed_with_code_and_can_be_changed(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/cards', ['name' => ' Payback ', 'code' => '4006381333931', 'code_format' => 'EAN_13', 'details' => [['label' => 'Nr.', 'value' => '123'], ['label' => '', 'value' => '']]], $headers)
            ->assertCreated()->assertJsonPath('data.name', 'Payback')->assertJsonPath('data.two_dimensional', false)->json('data.id');

        $list = $this->getJson('/api/v1/cards', $headers)->assertOk()->json('data');
        $this->assertCount(1, $list['cards']);
        $this->assertStringContainsString('<svg', $list['cards'][0]['code_svg']);
        $this->assertSame([['label' => 'Nr.', 'value' => '123']], $list['cards'][0]['details']);
        $this->assertContains('QR_CODE', array_column($list['formats'], 'key'));

        $this->putJson("/api/v1/cards/{$id}", ['name' => 'Payback', 'code' => 'ABC', 'code_format' => 'QR_CODE', 'private' => true], $headers)
            ->assertOk()->assertJsonPath('data.two_dimensional', true)->assertJsonPath('data.private', true);
        $this->postJson('/api/v1/cards', ['name' => 'X', 'code' => '1', 'code_format' => 'NOPE'], $headers)->assertStatus(422);

        $this->deleteJson("/api/v1/cards/{$id}", [], $headers)->assertOk();
        $this->assertNull(LoyaltyCard::find($id));
    }

    public function test_private_and_foreign_cards_stay_out_of_reach(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $private = LoyaltyCard::create(['household_id' => $household->id, 'created_by' => $partner->id, 'visibility' => Item::VISIBILITY_PRIVATE, 'name' => 'Geheim', 'code' => '1', 'code_format' => 'QR_CODE']);
        $shared = LoyaltyCard::create(['household_id' => $household->id, 'created_by' => $partner->id, 'visibility' => Item::VISIBILITY_SHARED, 'name' => 'Gemeinsam', 'code' => '2', 'code_format' => 'QR_CODE']);
        $foreign = LoyaltyCard::create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'visibility' => Item::VISIBILITY_SHARED, 'name' => 'Fremd', 'code' => '3', 'code_format' => 'QR_CODE']);

        $names = array_column($this->getJson('/api/v1/cards', $headers)->assertOk()->json('data.cards'), 'name');
        $this->assertSame(['Gemeinsam'], $names);

        $this->deleteJson("/api/v1/cards/{$private->id}", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/cards/{$foreign->id}", [], $headers)->assertNotFound();

        // A shared card may be edited by the partner, but only its creator can make it private.
        $this->putJson("/api/v1/cards/{$shared->id}", ['name' => 'Neu', 'code' => '2', 'code_format' => 'QR_CODE', 'private' => true], $headers)->assertOk()->assertJsonPath('data.private', false);
    }

    public function test_wishes_are_created_decided_and_deleted(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/wishlist', ['title' => 'Fahrrad', 'price' => '899.999', 'priority' => 'want', 'category' => 'hobby', 'wanted_by' => '2030-05-01'], $headers)
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.price', 900)->assertJsonPath('data.wanted_by', '2030-05-01')->json('data.id');

        $this->postJson("/api/v1/wishlist/{$id}/decide", ['status' => 'bought'], $headers)->assertOk()->assertJsonPath('data.status', 'bought');
        $this->assertNotNull(WishlistItem::find($id)->decided_at);
        $this->postJson("/api/v1/wishlist/{$id}/decide", ['status' => 'open'], $headers)->assertOk();
        $this->assertNull(WishlistItem::find($id)->decided_at);

        $this->putJson("/api/v1/wishlist/{$id}", ['title' => 'E-Bike', 'priority' => 'need', 'category' => 'hobby'], $headers)->assertOk()->assertJsonPath('data.title', 'E-Bike')->assertJsonPath('data.price', null);
        $this->postJson('/api/v1/wishlist', ['title' => 'X', 'priority' => 'urgent', 'category' => 'hobby'], $headers)->assertStatus(422);

        $data = $this->getJson('/api/v1/wishlist', $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['wishes']);
        $this->assertContains('need', array_column($data['priorities'], 'key'));

        $this->deleteJson("/api/v1/wishlist/{$id}", [], $headers)->assertOk();
        $this->assertNull(WishlistItem::find($id));
    }

    public function test_wishes_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $foreign = WishlistItem::create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'visibility' => Item::VISIBILITY_SHARED, 'title' => 'Fremd', 'priority' => 'want', 'category' => 'other', 'status' => 'open']);

        $this->getJson('/api/v1/wishlist', $headers)->assertOk()->assertJsonCount(0, 'data.wishes');
        $this->postJson("/api/v1/wishlist/{$foreign->id}/decide", ['status' => 'bought'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/wishlist/{$foreign->id}", [], $headers)->assertNotFound();
    }

    public function test_a_journal_day_is_saved_partially_and_removed_when_empty(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $day = today()->toDateString();

        $this->getJson("/api/v1/journal/{$day}", $headers)->assertOk()->assertJsonPath('data', null);

        $this->putJson("/api/v1/journal/{$day}", ['mood' => 4, 'activities' => ['Sport', ' Sport ', 'Familie'], 'grateful' => ' Sonne '], $headers)
            ->assertOk()->assertJsonPath('data.mood', 4)->assertJsonPath('data.activities', ['Sport', 'Familie'])->assertJsonPath('data.grateful', 'Sonne');

        // A partial update keeps the other fields.
        $this->putJson("/api/v1/journal/{$day}", ['notes' => 'Guter Tag'], $headers)->assertOk()->assertJsonPath('data.mood', 4)->assertJsonPath('data.notes', 'Guter Tag');
        $this->assertSame(1, JournalEntry::where('user_id', $user->id)->count());

        $index = $this->getJson('/api/v1/journal', $headers)->assertOk()->json('data');
        $this->assertCount(1, $index['entries']);
        $this->assertCount(5, $index['moods']);
        $this->assertNotEmpty($index['activities']);

        $this->putJson("/api/v1/journal/{$day}", ['mood' => null, 'activities' => [], 'grateful' => '', 'notes' => ''], $headers)->assertOk()->assertJsonPath('data', null);
        $this->assertSame(0, JournalEntry::where('user_id', $user->id)->count());

        $this->putJson('/api/v1/journal/'.today()->addDays(5)->toDateString(), ['mood' => 3], $headers)->assertStatus(422);
        $this->putJson("/api/v1/journal/{$day}", ['mood' => 9], $headers)->assertStatus(422);
    }

    public function test_the_journal_is_private_to_the_member(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        JournalEntry::create(['user_id' => $partner->id, 'date' => today()->toDateString(), 'mood' => 2, 'notes' => 'Privat']);

        $headers = $this->headersFor($user);
        $this->getJson('/api/v1/journal', $headers)->assertOk()->assertJsonCount(0, 'data.entries');
        $this->getJson('/api/v1/journal/'.today()->toDateString(), $headers)->assertOk()->assertJsonPath('data', null);
    }

    public function test_people_with_birthday_and_gift_ideas(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $birthday = today()->subYears(30)->addDays(10)->toDateString();

        $id = $this->postJson('/api/v1/people', ['name' => 'Anna', 'relation' => 'Schwester', 'birthday' => $birthday, 'birth_year_known' => true, 'remind_days' => 7], $headers)
            ->assertCreated()->assertJsonPath('data.days_until_birthday', 10)->assertJsonPath('data.turns', 30)->json('data.id');

        $giftId = $this->postJson("/api/v1/people/{$id}/gifts", ['title' => 'Buch', 'price' => 20], $headers)->assertCreated()->assertJsonPath('data.status', 'idea')->json('data.id');
        $this->putJson("/api/v1/gifts/{$giftId}", ['status' => 'given'], $headers)->assertOk()->assertJsonPath('data.status', 'given');

        $this->postJson("/api/v1/people/{$id}/contacted", [], $headers)->assertOk()->assertJsonPath('data.last_contact_on', today()->toDateString());
        $this->putJson("/api/v1/people/{$id}", ['name' => 'Anna M.', 'phone' => '0123'], $headers)->assertOk()->assertJsonPath('data.name', 'Anna M.')->assertJsonPath('data.relation', null);

        $people = $this->getJson('/api/v1/people', $headers)->assertOk()->json('data');
        $this->assertCount(1, $people);
        $this->assertCount(1, $people[0]['gifts']);

        $this->deleteJson("/api/v1/gifts/{$giftId}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/people/{$id}", [], $headers)->assertOk();
        $this->assertNull(Contact::find($id));
    }

    public function test_people_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $foreign = Contact::create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'visibility' => Item::VISIBILITY_SHARED, 'name' => 'Fremd', 'remind_days' => 7]);
        $gift = $foreign->giftIdeas()->create(['created_by' => $other->id, 'title' => 'Geheim', 'status' => 'idea']);

        $this->getJson('/api/v1/people', $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/v1/people/{$foreign->id}", ['name' => 'X'], $headers)->assertNotFound();
        $this->postJson("/api/v1/people/{$foreign->id}/gifts", ['title' => 'X'], $headers)->assertNotFound();
        $this->putJson("/api/v1/gifts/{$gift->id}", ['status' => 'given'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/gifts/{$gift->id}", [], $headers)->assertNotFound();
    }

    public function test_places_are_created_visited_rated_and_deleted(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/places', ['name' => 'Trattoria', 'category' => 'restaurant', 'city' => 'Rom', 'price_level' => 2], $headers)
            ->assertCreated()->assertJsonPath('data.visited', false)->assertJsonPath('data.visit_count', 0)->json('data.id');

        $this->postJson("/api/v1/places/{$id}/visit", [], $headers)->assertOk()->assertJsonPath('data.visit_count', 1)->assertJsonPath('data.visited', true);
        $this->postJson("/api/v1/places/{$id}/rate", ['stars' => 5], $headers)->assertOk()->assertJsonPath('data.rating', 5)->assertJsonPath('data.visit_count', 1);
        $this->postJson("/api/v1/places/{$id}/rate", ['stars' => 0], $headers)->assertOk()->assertJsonPath('data.rating', null);
        $this->postJson("/api/v1/places/{$id}/rate", ['stars' => 6], $headers)->assertStatus(422);

        $this->putJson("/api/v1/places/{$id}", ['name' => 'Trattoria Roma', 'category' => 'restaurant'], $headers)->assertOk()->assertJsonPath('data.city', null)->assertJsonPath('data.visited', true);

        $data = $this->getJson('/api/v1/places', $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['places']);
        $this->assertStringContainsString('google.com/maps', $data['places'][0]['maps_url']);
        $this->assertContains('restaurant', array_column($data['categories'], 'key'));

        $this->deleteJson("/api/v1/places/{$id}", [], $headers)->assertOk();
        $this->assertNull(Place::find($id));
    }

    public function test_places_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $foreign = Place::create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'visibility' => Item::VISIBILITY_SHARED, 'name' => 'Fremd', 'category' => 'cafe']);

        $this->getJson('/api/v1/places', $headers)->assertOk()->assertJsonCount(0, 'data.places');
        $this->postJson("/api/v1/places/{$foreign->id}/visit", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/places/{$foreign->id}", [], $headers)->assertNotFound();
    }

    public function test_the_endpoints_need_a_token(): void
    {
        foreach (['cards', 'wishlist', 'journal', 'people', 'places'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertUnauthorized();
        }
    }
}
