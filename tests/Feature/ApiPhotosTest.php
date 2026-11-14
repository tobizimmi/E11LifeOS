<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\LoyaltyCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for loyalty card photos and journal photos.
 */
class ApiPhotosTest extends TestCase
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

    public function test_a_card_photo_is_stored_served_and_removed(): void
    {
        Storage::fake(LoyaltyCard::IMAGE_DISK);
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/cards', ['name' => 'Payback', 'code' => '123', 'code_format' => 'QR_CODE'], $headers)->assertCreated()->assertJsonPath('data.has_front_image', false)->json('data.id');

        $this->post("/api/v1/cards/{$id}/image/front", ['image' => UploadedFile::fake()->image('front.jpg', 600, 400)], $headers + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.has_front_image', true)->assertJsonPath('data.has_back_image', false);
        $this->get("/api/v1/cards/{$id}/image/front", $headers)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/api/v1/cards/{$id}/image/back", $headers)->assertNotFound();
        $this->get("/api/v1/cards/{$id}/image/side", $headers)->assertNotFound();
        $this->post("/api/v1/cards/{$id}/image/front", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], $headers + ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson("/api/v1/cards/{$id}/image/front", [], $headers)->assertOk()->assertJsonPath('data.has_front_image', false);
        $this->get("/api/v1/cards/{$id}/image/front", $headers)->assertNotFound();

        // A private card of the partner stays out of reach.
        [$other] = $this->memberWithHousehold();
        $foreign = LoyaltyCard::create(['household_id' => $other->currentHousehold()->id, 'created_by' => $other->id, 'visibility' => Item::VISIBILITY_SHARED, 'name' => 'Fremd', 'code' => '1', 'code_format' => 'QR_CODE']);
        $this->post("/api/v1/cards/{$foreign->id}/image/front", ['image' => UploadedFile::fake()->image('a.jpg')], $headers + ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_journal_photos_are_limited_to_the_member_and_to_six_per_day(): void
    {
        Storage::fake('local');
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $day = today()->toDateString();

        $data = $this->post("/api/v1/journal/{$day}/photos", ['photo' => UploadedFile::fake()->image('a.jpg', 800, 600)], $headers + ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame(1, $data['photo_count']);
        $this->get("/api/v1/journal/{$day}/photos/0", $headers)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/api/v1/journal/{$day}/photos/3", $headers)->assertNotFound();

        for ($i = 0; $i < 5; $i++) {
            $this->post("/api/v1/journal/{$day}/photos", ['photo' => UploadedFile::fake()->image("p{$i}.jpg")], $headers + ['Accept' => 'application/json'])->assertCreated();
        }
        $this->post("/api/v1/journal/{$day}/photos", ['photo' => UploadedFile::fake()->image('too-many.jpg')], $headers + ['Accept' => 'application/json'])->assertStatus(422);

        $this->deleteJson("/api/v1/journal/{$day}/photos/0", [], $headers)->assertOk()->assertJsonPath('data.photo_count', 5);

        // Someone else sees nothing of it.
        [$other] = $this->memberWithHousehold();
        $this->get("/api/v1/journal/{$day}/photos/0", $this->headersFor($other))->assertNotFound();
    }
}
