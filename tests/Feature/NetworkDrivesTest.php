<?php

namespace Tests\Feature;

use App\Models\NetworkDrive;
use App\Models\User;
use App\Support\Drives\WebDavClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class NetworkDrivesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://93.184.216.34/dav/';

    private function propfind(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <d:multistatus xmlns:d="DAV:">
              <d:response><d:href>/dav/Steuer/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/Steuer/Bescheid%202025.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>2048</d:getcontentlength><d:getcontenttype>application/pdf</d:getcontenttype></d:prop></d:propstat></d:response>
              <d:response><d:href>/dav/Steuer/Belege/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
            </d:multistatus>
            XML;
    }

    public function test_a_folder_is_listed_with_folders_first_and_files_can_be_uploaded(): void
    {
        Http::fake(['93.184.216.34/*' => fn (Request $request) => $request->method() === 'PROPFIND' ? Http::response($this->propfind(), 207) : Http::response('', 201)]);
        $user = User::factory()->create();
        $drive = NetworkDrive::factory()->for($user)->create(['url' => self::URL]);

        $this->actingAs($user);
        Volt::test('drives.index')
            ->call('open', $drive->id, 'Steuer')
            ->assertSeeInOrder(['Belege', 'Bescheid 2025.pdf'])
            ->set('upload', UploadedFile::fake()->create('Rechnung.pdf', 10))
            ->call('uploadFile')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Auf NAS hochgeladen.');

        Http::assertSent(fn (Request $request) => $request->method() === 'PROPFIND' && $request->url() === self::URL.'Steuer/' && $request->hasHeader('Depth', '1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === self::URL.'Steuer/Rechnung.pdf' && $request->hasHeader('Authorization'));
    }

    public function test_addresses_in_the_local_network_are_refused_without_vpn(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('drives.index')
            ->set('form', ['name' => 'NAS', 'url' => 'http://192.168.178.20:5005/', 'username' => '', 'password' => ''])
            ->call('save')
            ->assertHasErrors('form.url');

        $this->assertFalse(WebDavClient::isAllowedUrl('http://127.0.0.1/'));
        $this->assertFalse(WebDavClient::isAllowedUrl('https://user:pass@93.184.216.34/'));
        $this->assertTrue(WebDavClient::isAllowedUrl(self::URL));
        $this->assertDatabaseCount('network_drives', 0);
    }

    public function test_paths_leaving_the_drive_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebDavClient::cleanPath('Steuer/../../etc');
    }

    public function test_only_the_owner_can_download_and_files_are_never_rendered(): void
    {
        Http::fake(['93.184.216.34/*' => Http::response('<html>hi</html>', 200, ['Content-Type' => 'text/html'])]);
        $drive = NetworkDrive::factory()->create(['url' => self::URL]);

        $this->actingAs(User::factory()->create())
            ->get(route('drives.download', ['drive' => $drive, 'path' => 'a.html']))
            ->assertNotFound();

        $response = $this->actingAs($drive->user)->get(route('drives.download', ['drive' => $drive, 'path' => 'a.html']));
        $response->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertSame('<html>hi</html>', $response->streamedContent());
    }

    public function test_google_drive_is_connected_with_the_google_sign_in(): void
    {
        config(['services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600])]);
        $user = User::factory()->create();

        $redirect = $this->actingAs($user)->get(route('drives.google.connect'));
        $redirect->assertRedirectContains('accounts.google.com');
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('drives.google.callback', ['state' => 'forged', 'code' => 'abc']))->assertForbidden();

        $this->withSession(['google_drive_state' => $query['state']])
            ->get(route('drives.google.callback', ['state' => $query['state'], 'code' => 'abc']))
            ->assertRedirect();

        $drive = NetworkDrive::where('user_id', $user->id)->sole();
        $this->assertTrue($drive->isGoogle());
        $this->assertSame('refresh-1', $drive->token['refresh_token']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token' && $request['code'] === 'abc' && $request['grant_type'] === 'authorization_code');
    }

    public function test_google_drive_folders_are_browsed_by_name_and_expired_tokens_are_renewed(): void
    {
        config(['services.google_drive.client_id' => 'client', 'services.google_drive.client_secret' => 'secret']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/files/doc-1/export*' => Http::response('%PDF'),
            'www.googleapis.com/drive/v3/files*' => function (Request $request) {
                $q = $request->data()['q'] ?? '';

                return match (true) {
                    str_contains($q, "name = 'Steuer'") => Http::response(['files' => [['id' => 'folder-1', 'mimeType' => 'application/vnd.google-apps.folder']]]),
                    str_contains($q, "name = 'Notizen'") => Http::response(['files' => [['id' => 'doc-1', 'mimeType' => 'application/vnd.google-apps.document']]]),
                    str_contains($q, "'folder-1' in parents") => Http::response(['files' => [
                        ['id' => 'doc-1', 'name' => 'Notizen', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-09-01T10:00:00Z'],
                        ['id' => 'folder-2', 'name' => 'Belege', 'mimeType' => 'application/vnd.google-apps.folder'],
                    ]]),
                    default => Http::response(['files' => []]),
                };
            },
        ]);
        $drive = NetworkDrive::factory()->create(['type' => 'google', 'url' => 'https://drive.google.com/', 'username' => null, 'password' => null,
            'token' => ['access_token' => 'old', 'refresh_token' => 'refresh-1', 'expires_at' => now()->subMinute()->getTimestamp()]]);

        $entries = $drive->client()->list('Steuer');

        $this->assertSame(['Belege', 'Notizen'], array_column($entries, 'name'));
        $this->assertSame('Steuer/Notizen', $entries[1]['path']);
        $this->assertSame('fresh', $drive->fresh()->token['access_token']);
        $this->assertSame('refresh-1', $drive->fresh()->token['refresh_token']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'drive/v3/files') && $request->hasHeader('Authorization', 'Bearer fresh'));

        $this->actingAs($drive->user)->get(route('drives.download', ['drive' => $drive, 'path' => 'Steuer/Notizen']))->assertOk();
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'files/doc-1/export') && str_contains($request->url(), 'mimeType=application%2Fpdf'));
    }
}
