<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PageAttachmentsAndTreeMovingTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function page(int $householdId, string $title, array $attributes = []): Item
    {
        return Item::create($attributes + ['household_id' => $householdId, 'type' => Item::TYPE_NOTE, 'title' => $title, 'content' => ['body' => '']]);
    }

    public function test_images_and_files_are_uploaded_into_a_new_page_and_linked_on_save(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($user);

        $component = Volt::test('pages.index')->call('create')
            ->set('attachmentUpload', UploadedFile::fake()->image('Garten Plan.png', 300, 200));
        $image = $component->call('storeAttachment')->effects['returns'][0] ?? null;
        $component->set('attachmentUpload', UploadedFile::fake()->create('Vertrag.pdf', 120, 'application/pdf'));
        $file = $component->call('storeAttachment')->effects['returns'][0] ?? null;

        $this->assertTrue($image['isImage']);
        $this->assertStringStartsWith('![Garten Plan.png](', $image['markdown']);
        $this->assertFalse($file['isImage']);
        $this->assertStringStartsWith('[📎 Vertrag.pdf](', $file['markdown']);

        // Not saved yet: only the uploader can open the files.
        $attachment = Attachment::where('original_name', 'Garten Plan.png')->sole();
        $this->assertNull($attachment->item_id);
        Storage::disk('local')->assertExists($attachment->path);
        $this->get($image['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($partner)->get($image['url'])->assertNotFound();

        $this->actingAs($user);
        $component->set('title', 'Garten')->set('body', "Plan:\n\n".$image['markdown']."\n\n".$file['markdown'])->call('save');

        $page = Item::where('title', 'Garten')->sole();
        $this->assertSame(2, $page->attachments()->count());
        $this->actingAs($partner)->get($image['url'])->assertOk();
        $this->get($file['url'])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=Vertrag.pdf');

        // The rendered page shows the image.
        $this->get(route('pages.index', $page))->assertSee('<img src="'.e($image['url']).'"', false);
    }

    public function test_files_of_private_pages_stay_private_and_are_deleted_with_the_page(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$stranger] = $this->memberWithHousehold();
        $private = $this->page($household->id, 'Tagebuch', ['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
        $this->actingAs($user);

        $component = Volt::test('pages.index', ['item' => $private->id])->call('edit', $private->id)
            ->set('attachmentUpload', UploadedFile::fake()->image('foto.jpg'));
        $url = $component->call('storeAttachment')->effects['returns'][0]['url'];
        $attachment = Attachment::sole();
        $this->assertSame($private->id, $attachment->item_id);

        $this->get($url)->assertOk();
        $this->actingAs($partner)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get($url)->assertNotFound();

        $this->actingAs($user);
        Volt::test('pages.index', ['item' => $private->id])->call('delete', $private->id);
        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    public function test_dangerous_or_too_large_files_are_rejected(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('pages.index')->call('create')
            ->set('attachmentUpload', UploadedFile::fake()->createWithContent('bild.svg', '<svg onload="alert(1)"></svg>'))
            ->call('storeAttachment')->assertHasErrors('attachmentUpload');
        Volt::test('pages.index')->call('create')
            ->set('attachmentUpload', UploadedFile::fake()->create('gross.pdf', Attachment::MAX_KILOBYTES + 1, 'application/pdf'))
            ->call('storeAttachment')->assertHasErrors('attachmentUpload');
        $this->assertSame(0, Attachment::count());
    }

    public function test_account_deletion_removes_files_of_private_pages_and_unsaved_uploads(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $shared = $this->page($household->id, 'Geteilt', ['created_by' => $user->id]);
        $private = $this->page($household->id, 'Privat', ['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);
        Storage::disk('local')->put('attachments/a.jpg', 'a');
        Storage::disk('local')->put('attachments/b.jpg', 'b');
        Storage::disk('local')->put('attachments/c.jpg', 'c');
        $keep = Attachment::factory()->for($household)->create(['item_id' => $shared->id, 'created_by' => $user->id, 'path' => 'attachments/a.jpg']);
        Attachment::factory()->for($household)->create(['item_id' => $private->id, 'created_by' => $user->id, 'path' => 'attachments/b.jpg']);
        Attachment::factory()->for($household)->create(['item_id' => null, 'created_by' => $user->id, 'path' => 'attachments/c.jpg']);

        $user->delete();

        $this->assertSame([$keep->id], Attachment::pluck('id')->all());
        Storage::disk('local')->assertExists('attachments/a.jpg');
        Storage::disk('local')->assertMissing(['attachments/b.jpg', 'attachments/c.jpg']);
    }

    public function test_pages_can_be_reordered_and_moved_in_the_sidebar(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $apfel = $this->page($household->id, 'Apfel');
        $birne = $this->page($household->id, 'Birne');
        $kirsche = $this->page($household->id, 'Kirsche');
        $garten = $this->page($household->id, 'Garten');
        $beet = $this->page($household->id, 'Beet', ['parent_id' => $garten->id]);
        $this->actingAs($user);

        // Alphabetical until arranged by hand.
        Volt::test('layout.navigation')->assertSeeInOrder(['Apfel', 'Birne', 'Garten', 'Kirsche']);

        Volt::test('layout.navigation')->call('movePage', $kirsche->id, null, 0);
        Volt::test('layout.navigation')->assertSeeInOrder(['Kirsche', 'Apfel', 'Birne', 'Garten']);

        // Into another list: becomes a subpage of "Garten", placed before "Beet".
        Volt::test('layout.navigation')->call('movePage', $apfel->id, $garten->id, 0);
        $this->assertSame($garten->id, $apfel->fresh()->parent_id);
        $this->assertLessThan($beet->fresh()->position, $apfel->fresh()->position);

        // A new top-level page goes to the end once pages were arranged.
        Volt::test('pages.index')->call('create')->set('title', 'Zitrone')->call('save');
        Volt::test('layout.navigation')->assertSeeInOrder(['Kirsche', 'Birne', 'Garten', 'Zitrone']);
        $this->assertNotNull($birne);
    }

    public function test_pages_cannot_be_moved_into_their_own_subpages_or_hidden_pages(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $garten = $this->page($household->id, 'Garten');
        $beet = $this->page($household->id, 'Beet', ['parent_id' => $garten->id]);
        $foreign = $this->page($household->id, 'Fremd privat', ['visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $partner->id]);
        $this->actingAs($user);

        Volt::test('layout.navigation')->call('movePage', $garten->id, $beet->id, 0)->assertDispatched('toast');
        Volt::test('layout.navigation')->call('movePage', $garten->id, $foreign->id, 0)->assertDispatched('toast');
        $this->assertNull($garten->fresh()->parent_id);

        $this->expectException(ModelNotFoundException::class);
        Volt::test('layout.navigation')->call('movePage', $foreign->id, null, 0);
    }
}
