<?php

namespace Tests\Feature;

use App\Models\PaperlessInstance;
use App\Models\PaperlessPendingUpload;
use App\Support\Paperless\PaperlessUploader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class DocumentScanTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function paperlessInstance(int $userId, int $householdId): PaperlessInstance
    {
        return PaperlessInstance::create(['user_id' => $userId, 'household_id' => $householdId, 'name' => 'Archiv', 'url' => 'https://paperless.test', 'token' => 'secret-token', 'shared' => false]);
    }

    private function pageDataUri(): string
    {
        $image = imagecreatetruecolor(40, 40);
        imagefilledrectangle($image, 0, 0, 40, 40, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagejpeg($image);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    public function test_a_scan_is_sent_to_paperless_with_chosen_tags_when_reachable(): void
    {
        Http::fake([
            'paperless.test/api/tags/?*' => Http::response(['results' => [
                ['id' => 3, 'name' => 'Posteingang', 'is_inbox_tag' => true],
                ['id' => 7, 'name' => 'Steuer', 'is_inbox_tag' => false],
            ]]),
            'paperless.test/api/correspondents/?*' => Http::response(['results' => [['id' => 5, 'name' => 'Finanzamt']]]),
            'paperless.test/api/document_types/?*' => Http::response(['results' => [['id' => 9, 'name' => 'Rechnung']]]),
            'paperless.test/api/documents/post_document/' => Http::response('"task-123"'),
        ]);

        [$user, $household] = $this->memberWithHousehold();
        $this->paperlessInstance($user->id, $household->id);
        $this->actingAs($user);

        Volt::test('scan.index')
            ->assertSet('tags', [3 => 'Posteingang', 7 => 'Steuer'])
            ->call('toggleTag', 7)
            ->set('correspondentId', 5)
            ->set('documentTypeId', 9)
            ->call('save', [$this->pageDataUri()], 'Stromrechnung')
            ->assertSet('queued', false);

        $this->assertSame(0, PaperlessPendingUpload::count());
        // The upload carries the inbox tag (3) plus the chosen tag (7), correspondent and type.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/documents/post_document/')) {
                return false;
            }
            $data = collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']]);

            return $data->get('correspondent') == 5 && $data->get('document_type') == 9
                && $data->values()->contains('3') && $data->values()->contains('7');
        });
    }

    public function test_a_scan_is_queued_and_later_flushed_when_paperless_is_down(): void
    {
        Storage::fake('local');

        // paperless is down first, then comes back (toggled via $down).
        $down = true;
        Http::fake(function ($request) use (&$down) {
            if ($down) {
                return Http::response('down', 500);
            }

            return str_contains($request->url(), '/api/tags/')
                ? Http::response(['results' => [['id' => 3, 'name' => 'Posteingang', 'is_inbox_tag' => true]]])
                : Http::response('"task-9"');
        });

        [$user, $household] = $this->memberWithHousehold();
        $this->paperlessInstance($user->id, $household->id);
        $this->actingAs($user);

        Volt::test('scan.index')
            ->call('save', [$this->pageDataUri()], 'Stromrechnung')
            ->assertSet('queued', true);

        $this->assertSame(1, PaperlessPendingUpload::count());
        $pending = PaperlessPendingUpload::sole();
        Storage::disk('local')->assertExists($pending->path);

        // paperless comes back: the scheduler flushes the queue and the file is uploaded and removed.
        $down = false;
        $this->assertSame(1, app(PaperlessUploader::class)->flush());
        $this->assertSame(0, PaperlessPendingUpload::count());
        Storage::disk('local')->assertMissing($pending->path);
    }
}
