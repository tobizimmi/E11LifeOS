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

    public function test_a_scan_is_sent_to_paperless_when_reachable(): void
    {
        Http::fake([
            'paperless.test/api/tags/?*' => Http::response(['results' => [['id' => 3, 'name' => 'Posteingang', 'is_inbox_tag' => true]]]),
            'paperless.test/api/documents/post_document/' => Http::response('"task-123"'),
        ]);

        [$user, $household] = $this->memberWithHousehold();
        $this->paperlessInstance($user->id, $household->id);
        $this->actingAs($user);

        Volt::test('scan.index')
            ->call('save', [$this->pageDataUri()], 'Stromrechnung')
            ->assertSet('queued', false);

        $this->assertSame(0, PaperlessPendingUpload::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/documents/post_document/'));
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
