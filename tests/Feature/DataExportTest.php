<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Invoice;
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

    public function test_the_export_contains_own_private_items_but_not_those_of_other_members(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        Item::factory()->note()->for($household)->privateTo($user)->create(['title' => 'Mein Tagebuch'])->syncTagNames(['privat']);
        Item::factory()->note()->for($household)->privateTo($partner)->create(['title' => 'Partner Tagebuch']);
        Item::factory()->inbox()->for($household)->create(['title' => 'Gemeinsame Idee']);
        BankAccount::factory()->for($household)->for($user)->create(['name' => 'Mein Giro']);
        BankAccount::factory()->for($household)->for($partner)->create(['name' => 'Partner Giro']);
        Invoice::factory()->for($household)->for($user)->create(['recipient' => 'Mein Kunde']);
        Invoice::factory()->for($household)->for($partner)->create(['recipient' => 'Partner Kunde']);

        $data = $this->actingAs($user)->get('/profile/export')->assertOk()->json();

        $this->assertSame(['Mein Tagebuch'], array_column($data['notes'], 'title'));
        $this->assertSame(['privat'], $data['notes'][0]['tags']);
        $this->assertSame(['Gemeinsame Idee'], array_column($data['inbox'], 'title'));
        $this->assertSame(['Mein Giro'], array_column($data['bank_accounts'], 'name'));
        $this->assertSame(['Mein Kunde'], array_column($data['business']['invoices'], 'recipient'));
    }

    public function test_guests_cannot_export_data(): void
    {
        $this->get('/profile/export')->assertRedirect('/login');
    }
}
