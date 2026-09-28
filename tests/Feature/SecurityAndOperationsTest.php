<?php

namespace Tests\Feature;

use App\Models\ExternalLink;
use App\Models\Item;
use App\Models\PaperlessInstance;
use App\Models\RealEstate;
use App\Models\User;
use App\Support\Backup\BackupEncryption;
use App\Support\ErrorReporter;
use App\Support\QrCode;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SecurityAndOperationsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function enableTwoFactor(User $user): string
    {
        $secret = TwoFactor::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => ['abcde-fghij'], 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    public function test_totp_codes_follow_rfc_6238_and_the_qr_code_has_the_right_size(): void
    {
        // RFC 6238 test secret "12345678901234567890": at 59 s the 8-digit code is 94287082.
        $this->assertSame('287082', TwoFactor::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
        $this->assertSame(1, TwoFactor::matchingStep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287 082', 59));
        $this->assertNull(TwoFactor::matchingStep('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59 + 3600));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', TwoFactor::generateSecret());

        $this->assertCount(21, QrCode::matrix('hello'));
        $this->assertCount(41, QrCode::matrix(str_repeat('x', 100)));
        $this->assertStringStartsWith('<svg', QrCode::svg('otpauth://totp/test'));
    }

    public function test_two_factor_is_set_up_with_a_code_and_then_required_at_login(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $form = Volt::test('profile.two-factor-form')->set('password', 'wrong')->call('start')->assertHasErrors('password')
            ->set('password', 'password')->call('start')->assertSee('<svg', false);
        $secret = $form->get('pendingSecret');
        $form->set('code', '000000')->call('confirm')->assertHasErrors('code')
            ->set('code', TwoFactor::code($secret, intdiv(time(), 30)))->call('confirm')->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        $this->assertCount(8, $form->get('shownRecoveryCodes'));
        $this->assertNotSame($secret, \DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        auth()->logout();

        // Password alone does not sign in.
        Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'password')->call('login')->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $challenge = Volt::test('pages.auth.two-factor-challenge')->set('code', '123456')->call('verify')->assertHasErrors('code');
        $this->assertGuest();
        $recovery = $form->get('shownRecoveryCodes')[0];
        $challenge->set('code', $recovery)->call('verify')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains($recovery, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_a_code_works_only_once_and_the_app_login_needs_it(): void
    {
        $user = User::factory()->create();
        $secret = $this->enableTwoFactor($user);
        $code = TwoFactor::code($secret, intdiv(time(), 30));

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422)->assertJson(['two_factor_required' => true]);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'code' => $code])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'code' => $code])->assertStatus(422);
    }

    public function test_the_admin_can_require_two_factor_for_everyone(): void
    {
        config(['security.require_two_factor' => true]);
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('profile'));
        $this->get(route('profile'))->assertOk()->assertSee('auf diesem Server Pflicht');

        $this->enableTwoFactor($user);
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_backups_are_encrypted_and_copied_to_webdav(): void
    {
        $directory = sys_get_temp_dir().'/e11-backup-test-'.uniqid();
        config([
            'backup.path' => $directory,
            'backup.remote_url' => 'https://storage.test/e11/',
            'backup.remote_username' => 'u1',
            'backup.remote_password' => 'pw',
            'backup.encryption_password' => 'geheim',
            'backup.remote_keep' => 2,
        ]);
        Http::fake([
            'storage.test/e11/' => Http::response('<d:multistatus xmlns:d="DAV:"><d:response><d:href>/e11/e11lifeos-2026-01-01-000000.zip.enc</d:href></d:response><d:response><d:href>/e11/e11lifeos-2026-01-02-000000.zip.enc</d:href></d:response><d:response><d:href>/e11/e11lifeos-2026-01-03-000000.zip.enc</d:href></d:response></d:multistatus>', 207),
            'storage.test/*' => Http::response('', 201),
        ]);

        $this->artisan('backup:run')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'PUT' && str_ends_with($request->url(), '.zip.enc') && str_starts_with($request->body(), 'E11BAK1') && $request->hasHeader('Authorization'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'e11lifeos-2026-01-01-000000.zip.enc'));
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '2026-01-03'));
        $this->assertSame([], glob($directory.'/*.enc'));

        // Round trip and wrong password.
        $plain = $directory.'/plain.bin';
        file_put_contents($plain, random_bytes(2_500_000));
        BackupEncryption::encrypt($plain, $plain.'.enc', 'geheim');
        BackupEncryption::decrypt($plain.'.enc', $plain.'.out', 'geheim');
        $this->assertSame(md5_file($plain), md5_file($plain.'.out'));
        $this->expectException(RuntimeException::class);
        BackupEncryption::decrypt($plain.'.enc', $plain.'.bad', 'falsch');
    }

    public function test_server_errors_are_kept_and_mailed_once_an_hour(): void
    {
        Mail::fake();
        Cache::forget(ErrorReporter::CACHE_KEY);
        config(['security.error_report_email' => 'admin@example.com']);

        ErrorReporter::handle(new RuntimeException('Datenbank weg'));
        ErrorReporter::handle(new RuntimeException('Datenbank weg'));
        ErrorReporter::handle(new ValidationException(validator([], [])));

        $this->assertCount(2, Cache::get(ErrorReporter::CACHE_KEY));
        $this->assertSame('Datenbank weg', Cache::get(ErrorReporter::CACHE_KEY)[0]['message']);
    }

    public function test_export_and_account_deletion_cover_the_new_modules(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $shared = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Gemeinsames Haus']);
        $private = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Meine Wohnung', 'visibility' => Item::VISIBILITY_PRIVATE]);
        PaperlessInstance::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Archiv', 'url' => 'https://p.test', 'token' => 'geheim-token']);
        ExternalLink::create(['household_id' => $household->id, 'created_by' => $user->id, 'linkable_type' => 'real_estate', 'linkable_id' => $private->id, 'kind' => 'mail', 'url' => 'message://x', 'title' => 'Mail']);

        $export = $this->actingAs($user)->get(route('profile.export'))->assertOk()->json();
        $this->assertSame(['Gemeinsames Haus', 'Meine Wohnung'], collect($export['real_estate'])->pluck('name')->sort()->values()->all());
        $this->assertSame('Archiv', $export['paperless_instances'][0]['name']);
        $this->assertArrayNotHasKey('token', $export['paperless_instances'][0]);
        $this->assertCount(1, $export['documents_and_mail_links']);

        $partnerExport = $this->actingAs($partner)->get(route('profile.export'))->json();
        $this->assertSame(['Gemeinsames Haus'], collect($partnerExport['real_estate'])->pluck('name')->all());

        $user->delete();
        $this->assertNull(RealEstate::find($private->id));
        $this->assertNotNull(RealEstate::find($shared->id));
        $this->assertSame(0, ExternalLink::count());
        $this->assertSame(0, PaperlessInstance::count());
    }

    public function test_new_members_see_first_steps_until_they_hide_them(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('dashboard')->assertSee('Erste Schritte')->call('dismissOnboarding')->assertDontSee('Erste Schritte');
        $this->assertNotNull($user->fresh()->onboarding_dismissed_at);
    }
}
