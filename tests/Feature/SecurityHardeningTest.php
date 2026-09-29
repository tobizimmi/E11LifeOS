<?php

namespace Tests\Feature;

use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Support\Security\EncryptedFilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_documents_are_encrypted_on_disk_and_old_files_are_encrypted_later(): void
    {
        $root = storage_path('framework/testing/secure-'.uniqid());
        config(['filesystems.disks.secure.root' => $root]);
        Storage::forgetDisk('secure');
        $raw = Storage::build(['driver' => 'local', 'root' => $root]);

        Storage::disk('secure')->put('external/1/ausweis.pdf', '%PDF Personalausweis L01X00T47');
        $this->assertStringStartsWith(EncryptedFilesystemAdapter::MARKER, $raw->get('external/1/ausweis.pdf'));
        $this->assertStringNotContainsString('Personalausweis', $raw->get('external/1/ausweis.pdf'));
        $this->assertSame('%PDF Personalausweis L01X00T47', Storage::disk('secure')->get('external/1/ausweis.pdf'));
        $this->assertSame('application/pdf', Storage::disk('secure')->mimeType('external/1/ausweis.pdf'));

        $raw->put('attachments/1/alt.txt', 'Klartext von früher');
        $this->assertSame('Klartext von früher', Storage::disk('secure')->get('attachments/1/alt.txt'));
        $this->artisan('files:encrypt')->expectsOutput('Encrypted files: 1')->assertSuccessful();
        $this->assertStringNotContainsString('Klartext', $raw->get('attachments/1/alt.txt'));
        $this->assertSame('Klartext von früher', Storage::disk('secure')->get('attachments/1/alt.txt'));

        File::deleteDirectory($root);
    }

    public function test_sensitive_pages_need_the_password_again_and_every_response_has_security_headers(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->get(route('health.index'))->assertRedirect(route('password.confirm'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin');
        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('health.index'))->assertOk();
        $this->withSession(['auth.password_confirmed_at' => time() - 1000])->get(route('emergency.index'))->assertRedirect(route('password.confirm'));
    }

    public function test_what_a_health_entry_is_about_is_stored_encrypted(): void
    {
        [$user] = $this->memberWithHousehold();
        $profile = HealthProfile::factory()->create(['user_id' => $user->id, 'blood_type' => 'AB-']);
        HealthEntry::factory()->medication(['08:00'])->create(['health_profile_id' => $profile->id, 'title' => 'Sertralin', 'dosage' => '50 mg']);

        $this->assertStringNotContainsString('Sertralin', (string) DB::table('health_entries')->value('title'));
        $this->assertStringNotContainsString('50 mg', (string) DB::table('health_entries')->value('dosage'));
        $this->assertStringNotContainsString('AB-', (string) DB::table('health_profiles')->value('blood_type'));
        $this->assertSame(['Sertralin', '50 mg'], [HealthEntry::sole()->title, HealthEntry::sole()->dosage]);
    }
}
