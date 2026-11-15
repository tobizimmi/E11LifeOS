<?php

namespace Tests\Feature;

use App\Models\PaperlessInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the document archive and scanning (paperless).
 */
class ApiDocumentsTest extends TestCase
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

    private function fakePaperless(): void
    {
        Http::fake([
            '93.184.216.34/api/documents/?*' => Http::response(['count' => 1, 'results' => [
                ['id' => 41, 'title' => 'Kaufvertrag', 'created' => '2026-07-01T00:00:00Z', 'correspondent' => 3, 'document_type' => null, 'tags' => [1]],
            ]]),
            '93.184.216.34/api/documents/41/thumb/' => Http::response('IMG', 200, ['Content-Type' => 'image/webp']),
            '93.184.216.34/api/documents/41/notes/' => Http::response([]),
            '93.184.216.34/api/documents/41/' => Http::response(['id' => 41, 'title' => 'Kaufvertrag', 'created' => '2026-07-01', 'tags' => [1], 'notes' => []]),
            '93.184.216.34/api/documents/post_document/' => Http::response('"task-1"'),
            '93.184.216.34/api/tags/?*' => Http::response(['results' => [['id' => 1, 'name' => 'Haus']]]),
            '93.184.216.34/api/correspondents/?*' => Http::response(['results' => [['id' => 3, 'name' => 'Bank']]]),
            '93.184.216.34/api/document_types/?*' => Http::response(['results' => []]),
        ]);
    }

    public function test_without_a_connection_the_archive_is_empty(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/documents', $headers)->assertOk()->assertJsonPath('data.instance', null)->assertJsonPath('data.instances', []);
        $this->postJson('/api/v1/documents/scan', [], $headers)->assertStatus(422);
        $this->getJson('/api/v1/documents', [])->assertUnauthorized();
    }

    public function test_browse_edit_note_preview_and_delete(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 'secret-token']);
        $headers = $this->headersFor($user);

        $data = $this->getJson('/api/v1/documents?q=kauf', $headers)->assertOk()->json('data');
        $this->assertSame('Kaufvertrag', $data['documents']['results'][0]['title']);
        $this->assertSame([['id' => 1, 'name' => 'Haus']], $data['tags']);
        $this->assertSame('Archiv', $data['instances'][0]['name']);
        $this->assertStringNotContainsString('secret-token', json_encode($data));

        $this->getJson('/api/v1/documents/41', $headers)->assertOk()->assertJsonPath('data.title', 'Kaufvertrag');
        $this->putJson('/api/v1/documents/41', ['title' => 'Neu', 'tags' => [1]], $headers)->assertOk();
        $this->putJson('/api/v1/documents/41', ['title' => ''], $headers)->assertStatus(422);
        $this->postJson('/api/v1/documents/41/notes', ['note' => 'Wichtig'], $headers)->assertCreated();
        $this->get('/api/v1/documents/41/thumb', $headers)->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/api/v1/documents/41/evil', $headers)->assertNotFound();
        $this->deleteJson('/api/v1/documents/41', [], $headers)->assertOk();
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/documents/41/'));
    }

    public function test_someone_elses_instance_is_not_reachable(): void
    {
        $this->fakePaperless();
        [$owner, $household] = $this->memberWithHousehold();
        $instance = PaperlessInstance::create(['user_id' => $owner->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 'secret-token']);
        [$stranger] = $this->memberWithHousehold();
        $headers = $this->headersFor($stranger);

        $this->getJson('/api/v1/documents/41?instance='.$instance->id, $headers)->assertNotFound();
        $this->getJson('/api/v1/documents', $headers)->assertOk()->assertJsonPath('data.instance', null);
    }

    public function test_scanned_pages_become_a_pdf_upload(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 'secret-token']);
        $headers = $this->headersFor($user);

        $this->post('/api/v1/documents/scan', ['title' => 'Brief', 'pages' => [UploadedFile::fake()->image('a.jpg', 200, 300)]], $headers + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.queued', false);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'post_document'));
    }

    public function test_pages_are_straightened_filtered_and_previewed(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 'secret-token']);
        $headers = $this->headersFor($user) + ['Accept' => 'application/json'];
        $meta = json_encode(['corners' => [0.1, 0.1, 0.9, 0.12, 0.88, 0.9, 0.08, 0.92], 'filter' => 'bw', 'rotate' => 90]);

        $started = microtime(true);
        $response = $this->post('/api/v1/documents/scan/preview', ['page' => UploadedFile::fake()->image('a.jpg', 500, 400), 'meta' => $meta], $headers)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $size = getimagesizefromstring($response->getContent());
        $this->assertLessThan(20, microtime(true) - $started);
        // The page is portrait-sized after the 90° turn of a landscape photo.
        $this->assertGreaterThan($size[0], $size[1]);

        $this->post('/api/v1/documents/scan', ['title' => 'Brief', 'pages' => [UploadedFile::fake()->image('a.jpg', 400, 300)], 'meta' => [$meta]], $headers)->assertCreated()->assertJsonPath('data.queued', false);
        $this->post('/api/v1/documents/scan/preview', ['page' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], $headers)->assertStatus(422);
        // Nonsense settings fall back to the unchanged page.
        gc_collect_cycles();
        $this->post('/api/v1/documents/scan/preview', ['page' => UploadedFile::fake()->image('a.jpg', 100, 100), 'meta' => json_encode(['corners' => [0, 0, 0, 0, 0, 0, 0, 0], 'filter' => 'nope'])], $headers)->assertOk();
    }

    public function test_other_requests_do_not_use_up_the_scan_limit(): void
    {
        $this->fakePaperless();
        [$user, $household] = $this->memberWithHousehold();
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://93.184.216.34', 'token' => 'secret-token']);
        $headers = $this->headersFor($user) + ['Accept' => 'application/json'];

        // A busy screen refreshing other data must not eat into the separate scan limit.
        for ($i = 0; $i < 25; $i++) {
            $this->get('/api/v1/documents', $headers);
        }

        $this->post('/api/v1/documents/scan', ['pages' => [UploadedFile::fake()->image('a.jpg', 60, 60)]], $headers)->assertCreated();
    }
}
