<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Page attachments API (image/file upload + token-authenticated serving).
 */
class ApiAttachmentsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_an_image_can_be_uploaded_to_a_page_and_served(): void
    {
        Storage::fake(Attachment::DISK);
        [$user] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $pageId = $this->postJson('/api/v1/pages', ['title' => 'Notiz'], $headers)->assertCreated()->json('data.id');

        $data = $this->post("/api/v1/pages/{$pageId}/attachments", ['file' => UploadedFile::fake()->image('foto.jpg', 120, 120)], $headers)
            ->assertCreated()
            ->assertJsonPath('data.is_image', true)
            ->json('data');

        $this->assertStringStartsWith('/attachments/', $data['path']);
        Storage::disk(Attachment::DISK)->assertExists(Attachment::find($data['id'])->path);

        // The owner can fetch it with their token.
        $this->get("/api/v1/attachments/{$data['id']}", $headers)->assertOk();
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        Storage::fake(Attachment::DISK);
        [$user] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];
        $pageId = $this->postJson('/api/v1/pages', ['title' => 'Notiz'], $headers)->assertCreated()->json('data.id');

        $this->post("/api/v1/pages/{$pageId}/attachments", ['file' => UploadedFile::fake()->create('schad.exe', 10)], $headers)
            ->assertStatus(422);
    }

    public function test_attachments_of_another_household_are_not_served(): void
    {
        Storage::fake(Attachment::DISK);
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];
        $otherHeaders = ['Authorization' => 'Bearer '.$this->tokenFor($other)];

        $otherPageId = $this->postJson('/api/v1/pages', ['title' => 'Fremd'], $otherHeaders)->assertCreated()->json('data.id');
        $id = $this->post("/api/v1/pages/{$otherPageId}/attachments", ['file' => UploadedFile::fake()->image('x.png')], $otherHeaders)
            ->assertCreated()->json('data.id');

        // The uploading page belongs to the other household -> not visible / not served.
        $this->post("/api/v1/pages/{$otherPageId}/attachments", ['file' => UploadedFile::fake()->image('y.png')], $headers)->assertNotFound();
        $this->get("/api/v1/attachments/{$id}", $headers)->assertNotFound();

        $this->assertSame($otherHousehold->id, Attachment::find($id)->household_id);
    }
}
