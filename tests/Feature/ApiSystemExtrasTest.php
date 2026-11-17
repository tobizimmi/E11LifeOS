<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BusinessProfile;
use App\Models\NetworkDrive;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Kiosk, menu, PDF design, business settings, Google Drive, finance files and the trip map for the app.
 */
class ApiSystemExtrasTest extends TestCase
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

    public function test_kiosk_menu_and_pdf_design(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $list = ShoppingList::create(['household_id' => $household->id, 'name' => 'Einkauf']);
        ShoppingListEntry::create(['shopping_list_id' => $list->id, 'household_id' => $household->id, 'label' => 'Milch', 'quantity' => '2', 'checked' => false]);
        $recipe = Recipe::factory()->create(['household_id' => $household->id, 'title' => 'Pasta']);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/kiosk', $headers)->assertOk()->assertJsonPath('data.shopping.0.label', 'Milch');

        $menu = $this->getJson('/api/v1/menu', $headers)->assertOk();
        $this->assertNotEmpty($menu->json('data.presets'));
        $this->postJson('/api/v1/menu/toggle', ['module' => 'notes', 'action' => 'pin'], $headers)->assertOk();
        $this->postJson('/api/v1/menu/toggle', ['module' => 'dashboard', 'action' => 'hide'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/menu/toggle', ['module' => 'nope', 'action' => 'pin'], $headers)->assertNotFound();
        $this->postJson('/api/v1/menu/preset', ['preset' => 'minimal'], $headers)->assertOk();
        $hidden = collect($this->getJson('/api/v1/menu', $headers)->json('data.groups'))->pluck('modules')->flatten(1)->where('hidden', true);
        $this->assertTrue($hidden->isNotEmpty());

        $this->putJson('/api/v1/pdf-design', ['general' => ['accent' => '#30a46c', 'font' => 'serif', 'footer' => 'Familie'], 'recipe' => ['paper' => 'A5']], $headers)
            ->assertOk()->assertJsonPath('data.general.accent', '#30a46c')->assertJsonPath('data.recipe.paper', 'A5')->assertJsonPath('data.recipes.0.title', 'Pasta');
        $this->postJson('/api/v1/pdf-design/preview/'.$recipe->id, ['general' => ['accent' => '#e5484d']], $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->deleteJson('/api/v1/pdf-design', [], $headers)->assertOk()->assertJsonPath('data.general.accent', '#4f46e5');
    }

    public function test_business_settings(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('business', true);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/business/settings', $headers)->assertOk()->assertJsonPath('data.exists', false)->assertJsonPath('data.invoice_prefix', 'RE-');

        $payload = ['company_name' => 'Muster GmbH', 'small_business' => false, 'default_vat_rate' => 19, 'accent_color' => '#4338ca', 'payment_days' => 14, 'reminder_days' => 7, 'reminder_fee_1' => 0, 'reminder_fee_2' => 5, 'reminder_fee_3' => 10, 'iban' => 'de89 3704 0044 0532 0130 00', 'invoice_prefix' => 'RE-', 'quote_prefix' => 'AN-'];
        $this->putJson('/api/v1/business/settings', $payload, $headers)->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.iban', 'DE89370400440532013000');
        $this->putJson('/api/v1/business/settings', ['quote_prefix' => 'RE-'] + $payload, $headers)->assertStatus(422);
        $this->assertSame('Muster GmbH', BusinessProfile::where('user_id', $user->id)->value('company_name'));

        $this->post('/api/v1/business/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 100, 100)], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_logo', true);
        $this->deleteJson('/api/v1/business/settings/logo', [], $headers)->assertOk()->assertJsonPath('data.has_logo', false);
    }

    public function test_google_drive_connects_from_the_app(): void
    {
        config(['services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600])]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/drives/google', $headers)->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.connected', false);
        $url = $this->postJson('/api/v1/drives/google/connect', [], $headers)->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->app['auth']->forgetGuards();
        $this->get(route('drives.google.callback', ['state' => $query['state'], 'code' => 'abc']))->assertRedirect('e11lifeos://drives');
        $this->assertTrue(NetworkDrive::where('user_id', $user->id)->where('type', 'google')->exists());
        $this->get(route('drives.google.callback', ['state' => $query['state'], 'code' => 'abc']))->assertForbidden();
    }

    public function test_finance_import_and_exports(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $headers = $this->headersFor($user);
        $csv = "Buchungstag;Verwendungszweck;Beguenstigter/Zahlungspflichtiger;Betrag\n01.10.2026;Miete;Vermieter;-900,00\n02.10.2026;Gehalt;Firma;2500,00\n";
        $file = fn () => UploadedFile::fake()->createWithContent('umsaetze.csv', $csv);

        $this->post('/api/v1/finance/import', ['statement' => $file(), 'account' => 'new', 'name' => 'Giro'], $headers + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.imported', 2);
        $account = BankAccount::where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->post('/api/v1/finance/import', ['statement' => $file(), 'account' => (string) $account->id], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.imported', 0);
        $this->post('/api/v1/finance/import', ['statement' => $file(), 'account' => 'new'], $headers + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/finance/import/inspect', ['statement' => $file()], $headers + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.format', 'bank');

        $this->get('/api/v1/finance/tax/2026/export', $headers)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get('/api/v1/finance/report/pdf?period=3m', $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_trip_map_looks_up_missing_positions(): void
    {
        Http::fake(['*' => Http::response([['lat' => '45.07', 'lon' => '7.68', 'display_name' => 'Turin']])]);
        [$user, $household] = $this->memberWithHousehold();
        $trip = Trip::factory()->create(['household_id' => $household->id, 'destination' => 'Turin']);
        TripStop::create(['trip_id' => $trip->id, 'title' => 'Dom', 'kind' => 'sight', 'lat' => 45.0734, 'lng' => 7.6853]);
        $headers = $this->headersFor($user);

        $response = $this->getJson('/api/v1/trips/'.$trip->id.'/map', $headers)->assertOk();
        $this->assertSame('Dom', $response->json('data.stops.0.title'));
        $this->assertSame(0, $response->json('data.missing'));
    }
}
