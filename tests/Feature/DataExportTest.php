<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DataExportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_a_member_can_download_their_household_data_as_json(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Notiz', 'created_by' => $user->id]);
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_TODO, 'title' => 'Aufgabe', 'created_by' => $user->id]);
        ShoppingListEntry::create(['household_id' => $household->id, 'label' => 'Milch', 'created_by' => $user->id]);

        $response = $this->actingAs($user)->get('/profile/export');

        $response->assertOk();
        $response->assertHeader('Content-Disposition');

        $data = $response->json();

        $this->assertSame($user->email, $data['user']['email']);
        $this->assertSame($household->name, $data['household']['name']);
        $this->assertCount(1, $data['notes']);
        $this->assertCount(1, $data['todos']);
        $this->assertCount(1, $data['shopping_list']);
    }

    public function test_guests_cannot_export_data(): void
    {
        $this->get('/profile/export')->assertRedirect('/login');
    }
}
