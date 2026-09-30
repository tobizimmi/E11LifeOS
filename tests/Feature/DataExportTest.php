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

        $response = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/profile/export');

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

        $data = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/profile/export')->assertOk()->json();

        $this->assertSame(['Mein Tagebuch'], array_column($data['notes'], 'title'));
        $this->assertSame(['privat'], $data['notes'][0]['tags']);
        $this->assertSame(['Gemeinsame Idee'], array_column($data['inbox'], 'title'));
        $this->assertSame(['Mein Giro'], array_column($data['bank_accounts'], 'name'));
        $this->assertSame(['Mein Kunde'], array_column($data['business']['invoices'], 'recipient'));
    }

    public function test_the_export_requires_a_recent_password_confirmation(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->get('/profile/export')->assertRedirect(route('password.confirm'));
    }

    public function test_the_encrypted_export_is_a_password_protected_zip(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Item::create(['household_id' => $household->id, 'type' => Item::TYPE_NOTE, 'title' => 'Geheime Notiz', 'created_by' => $user->id]);

        $response = $this->actingAs($user)->post(route('profile.export.encrypted'), ['current_password' => 'password', 'export_password' => 'zip-geheim-123', 'export_password_confirmation' => 'zip-geheim-123']);

        $response->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $name = $zip->getNameIndex(0);
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statIndex(0)['encryption_method']);
        $this->assertFalse($zip->getFromName($name));
        $zip->setPassword('zip-geheim-123');
        $this->assertSame('Geheime Notiz', json_decode($zip->getFromName($name), true)['notes'][0]['title']);
        $zip->close();
        unlink($path);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'export']);
    }

    public function test_the_encrypted_export_rejects_a_wrong_account_password(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->from(route('profile'))->post(route('profile.export.encrypted'), ['current_password' => 'falsch', 'export_password' => 'zip-geheim-123', 'export_password_confirmation' => 'zip-geheim-123'])
            ->assertRedirect(route('profile'))->assertSessionHasErrors(['current_password' => 'Das Passwort ist falsch.']);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'login_failed']);
    }

    public function test_guests_cannot_export_data(): void
    {
        $this->get('/profile/export')->assertRedirect('/login');
    }
}
