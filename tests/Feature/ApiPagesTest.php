<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Pages / notes API: the second brain, editable offline (Markdown body in content.body).
 */
class ApiPagesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_pages_are_listed_created_read_updated_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $id = $this->postJson('/api/v1/pages', ['title' => 'Ideen', 'body' => '# Urlaub'], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Ideen')->assertJsonPath('data.body', '# Urlaub')->json('data.id');

        $this->getJson('/api/v1/pages', $headers)->assertOk()->assertJsonPath('data.0.title', 'Ideen');
        $this->getJson("/api/v1/pages/{$id}", $headers)->assertOk()->assertJsonPath('data.body', '# Urlaub');

        $this->putJson("/api/v1/pages/{$id}", ['body' => "# Urlaub\n- [ ] Pass"], $headers)
            ->assertOk()->assertJsonPath('data.body', "# Urlaub\n- [ ] Pass");

        $this->deleteJson("/api/v1/pages/{$id}", [], $headers)->assertOk();
        $this->assertNull(Item::find($id));
    }

    public function test_a_page_with_sub_pages_is_not_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $parent = Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Parent', 'created_by' => $user->id, 'content' => ['body' => '']]);
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Child', 'parent_id' => $parent->id, 'created_by' => $user->id, 'content' => ['body' => '']]);

        $this->deleteJson("/api/v1/pages/{$parent->id}", [], $headers)->assertStatus(422);
        $this->assertNotNull(Item::find($parent->id));
    }

    public function test_pages_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $foreign = Item::create(['household_id' => $otherHousehold->id, 'type' => Item::TYPE_NOTE, 'title' => 'Fremd', 'created_by' => $other->id, 'content' => ['body' => 'geheim']]);

        $this->getJson("/api/v1/pages/{$foreign->id}", $headers)->assertNotFound();
        $this->putJson("/api/v1/pages/{$foreign->id}", ['body' => 'x'], $headers)->assertNotFound();
        $this->getJson('/api/v1/pages', $headers)->assertOk()->assertJsonCount(0, 'data');
    }
}
