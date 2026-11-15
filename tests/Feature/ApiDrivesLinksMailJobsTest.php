<?php

namespace Tests\Feature;

use App\Models\CareerEntry;
use App\Models\ExternalLink;
use App\Models\InboundMail;
use App\Models\Item;
use App\Models\NetworkDrive;
use App\Models\PaperlessInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API: network drives, document links, household mail intake and job suggestions.
 */
class ApiDrivesLinksMailJobsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const URL = 'https://93.184.216.34/dav/';

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function propfind(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/dav/Steuer/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/Steuer/Bescheid%202025.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>2048</d:getcontentlength><d:getcontenttype>application/pdf</d:getcontenttype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML;
    }

    public function test_drives_are_managed_browsed_and_uploaded_to(): void
    {
        Http::fake(['93.184.216.34/*' => fn (Request $request) => $request->method() === 'PROPFIND' ? Http::response($this->propfind(), 207) : Http::response('PDFDATA', $request->method() === 'PUT' ? 201 : 200)]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/drives', ['name' => 'NAS', 'url' => 'http://192.168.178.20:5005/'], $headers)->assertStatus(422);
        $id = $this->postJson('/api/v1/drives', ['name' => 'NAS', 'url' => self::URL, 'username' => 'max', 'password' => 'geheim'], $headers)->assertCreated()->json('data.id');
        $this->assertStringNotContainsString('geheim', json_encode($this->getJson('/api/v1/drives', $headers)->assertOk()->json()));

        $entries = $this->getJson("/api/v1/drives/{$id}/browse?path=Steuer", $headers)->assertOk()->json('data.entries');
        $this->assertSame('Bescheid 2025.pdf', collect($entries)->firstWhere('is_dir', false)['name']);

        $this->post("/api/v1/drives/{$id}/upload", ['file' => UploadedFile::fake()->create('Rechnung.pdf', 10), 'path' => 'Steuer'], $headers + ['Accept' => 'application/json'])->assertCreated();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::URL.'Steuer/Rechnung.pdf');
        $this->get("/api/v1/drives/{$id}/download?path=Steuer/Bescheid%202025.pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');

        $this->putJson("/api/v1/drives/{$id}", ['name' => 'NAS 2', 'url' => self::URL], $headers)->assertOk()->assertJsonPath('data.name', 'NAS 2');
        $this->assertSame('geheim', NetworkDrive::find($id)->password);
        $this->deleteJson("/api/v1/drives/{$id}", [], $headers)->assertOk();
    }

    public function test_drives_belong_to_their_owner(): void
    {
        [$owner] = $this->memberWithHousehold();
        $drive = NetworkDrive::factory()->for($owner)->create(['url' => self::URL]);
        [$other] = $this->memberWithHousehold();
        $headers = $this->headersFor($other);

        $this->getJson('/api/v1/drives', $headers)->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/v1/drives/{$drive->id}/browse", $headers)->assertNotFound();
        $this->deleteJson("/api/v1/drives/{$drive->id}", [], $headers)->assertNotFound();
    }

    public function test_documents_are_linked_to_entries_and_unlinked(): void
    {
        Http::fake(['93.184.216.34/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Vertrag', 'tags' => [], 'notes' => []])]);
        [$user, $household] = $this->memberWithHousehold();
        $instance = PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 't']);
        $item = Item::factory()->note()->for($household)->create(['title' => 'Mietvertrag']);
        $headers = $this->headersFor($user);

        $link = $this->postJson('/api/v1/document-links', ['type' => 'item', 'id' => $item->id, 'kind' => 'paperless', 'instance' => $instance->id, 'document' => 41, 'title' => 'Vertrag'], $headers)->assertCreated()->json('data');
        $this->postJson('/api/v1/document-links', ['type' => 'item', 'id' => $item->id, 'kind' => 'paperless', 'instance' => $instance->id, 'document' => 41], $headers)->assertCreated();
        $this->assertSame(1, ExternalLink::count());
        $this->postJson('/api/v1/document-links', ['type' => 'item', 'id' => $item->id, 'kind' => 'paperless', 'instance' => $instance->id, 'document' => 999], $headers)->assertNotFound();
        $this->postJson('/api/v1/document-links', ['type' => 'item', 'id' => $item->id, 'kind' => 'mail', 'url' => 'javascript:alert(1)'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/document-links', ['type' => 'item', 'id' => $item->id, 'kind' => 'mail', 'url' => 'https://mail.google.com/mail/u/0/#inbox/abc', 'title' => 'Mail'], $headers)->assertCreated();

        $this->assertCount(2, $this->getJson("/api/v1/document-links?type=item&id={$item->id}", $headers)->assertOk()->json('data'));
        $this->deleteJson('/api/v1/document-links/'.$link['id'], [], $headers)->assertOk();
        $this->assertSame(1, ExternalLink::count());

        // A stranger neither sees nor links to somebody else's entry.
        [$stranger] = $this->memberWithHousehold();
        $this->getJson("/api/v1/document-links?type=item&id={$item->id}", $this->headersFor($stranger))->assertNotFound();
    }

    public function test_household_mail_address_and_log(): void
    {
        config(['services.inbound_mail.domain' => 'mail.example.test']);
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $headers = $this->headersFor($owner);

        $this->getJson('/api/v1/household/mail', $headers)->assertOk()->assertJsonPath('data.address', null)->assertJsonPath('data.available', true);
        $address = $this->postJson('/api/v1/household/mail', [], $headers)->assertCreated()->json('data.address');
        $this->assertStringEndsWith('@mail.example.test', $address);
        InboundMail::create(['household_id' => $household->id, 'user_id' => $owner->id, 'message_id' => 'm1', 'from_address' => 'a@b.test', 'subject' => 'Einkauf: Milch', 'status' => 'shopping']);
        $this->assertSame('Einkauf: Milch', $this->getJson('/api/v1/household/mail', $headers)->json('data.mails.0.subject'));

        $this->postJson('/api/v1/household/mail', [], $this->headersFor($partner))->assertForbidden();
        $this->deleteJson('/api/v1/household/mail', [], $headers)->assertOk()->assertJsonPath('data.address', null);
    }

    public function test_job_suggestions_can_be_remembered_as_applications(): void
    {
        Http::fake(['rest.arbeitsagentur.de/*' => Http::response(['stellenangebote' => [['titel' => 'PHP-Entwickler', 'arbeitgeber' => 'ACME', 'arbeitsort' => ['ort' => 'München'], 'refnr' => '123-ABC']]])]);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/career/jobs', $headers)->assertOk()->assertJsonPath('data.jobs', []);
        $this->putJson('/api/v1/career/profile', ['desired_role' => 'PHP-Entwickler', 'desired_location' => 'München'], $headers)->assertOk();
        $job = $this->getJson('/api/v1/career/jobs', $headers)->assertOk()->json('data.jobs.0');
        $this->assertSame('PHP-Entwickler', $job['title']);

        $this->postJson('/api/v1/career/jobs/remember', ['title' => $job['title'], 'employer' => $job['employer'], 'location' => $job['location'], 'url' => $job['url']], $headers)->assertCreated()->assertJsonPath('data.details.status', 'planned');
        $this->postJson('/api/v1/career/jobs/remember', ['title' => $job['title'], 'url' => $job['url']], $headers)->assertCreated();
        $this->assertSame(1, CareerEntry::where('type', 'application')->count());
        $this->postJson('/api/v1/career/jobs/remember', ['title' => 'X', 'url' => 'http://insecure.test'], $headers)->assertStatus(422);
    }
}
