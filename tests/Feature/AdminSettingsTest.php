<?php

namespace Tests\Feature;

use App\Jobs\RunAdminCommand;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user);

        return $user;
    }

    public function test_only_administrators_open_the_server_settings(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.settings'))->assertForbidden();

        $this->artisan('user:admin', ['email' => auth()->user()->email])->assertSuccessful();
        $this->actingAs(auth()->user()->fresh());
        $this->get(route('admin.settings'))->assertOk()->assertSee('Servereinstellungen');
    }

    public function test_saved_values_are_encrypted_override_the_env_and_secrets_stay_hidden(): void
    {
        $this->admin();
        config(['services.anthropic.key' => null, 'mail.default' => 'log']);

        Volt::test('admin.settings')
            ->set('form.anthropic_api_key', 'sk-ant-secret')
            ->set('form.mail_host', 'mail.example.com')
            ->set('form.mail_port', '587')
            ->set('form.mail_from_address', 'lifeos@example.com')
            ->set('form.apify_api_key', 'apify_api_x')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.anthropic_api_key', '')
            ->assertSet('form.mail_host', 'mail.example.com')
            ->assertDontSee('sk-ant-secret');

        $raw = DB::table('app_settings')->where('key', 'anthropic_api_key')->value('value');
        $this->assertStringNotContainsString('sk-ant-secret', $raw);

        // A new request reads them from the database.
        config(['services.anthropic.key' => null, 'mail.default' => 'log']);
        AppSettings::apply();
        $this->assertSame('sk-ant-secret', config('services.anthropic.key'));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('apify_api_x', config('services.instagram.apify_key'));

        // An empty secret field keeps the value, "remove" deletes it.
        Volt::test('admin.settings')->call('save');
        $this->assertTrue(AppSetting::where('key', 'anthropic_api_key')->exists());
        Volt::test('admin.settings')->set('clear.anthropic_api_key', true)->call('save');
        $this->assertFalse(AppSetting::where('key', 'anthropic_api_key')->exists());

        Volt::test('admin.settings')->set('form.mail_from_address', 'kein-mail')->call('save')->assertHasErrors('form.mail_from_address');
    }

    public function test_push_keys_are_created_once_and_a_test_mail_is_sent(): void
    {
        $user = $this->admin();
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);

        Volt::test('admin.settings')->call('generateVapidKeys');
        $public = AppSettings::stored()['vapid_public_key'];
        $this->assertNotEmpty($public);
        $this->assertSame('mailto:'.$user->email, AppSettings::stored()['vapid_subject']);

        Volt::test('admin.settings')->call('generateVapidKeys');
        $this->assertSame($public, AppSettings::stored()['vapid_public_key']);

        Mail::fake();
        config(['mail.default' => 'smtp']);
        Volt::test('admin.settings')->set('testRecipient', 'test@example.com')->call('sendTestMail')->assertHasNoErrors();
    }

    public function test_imports_run_in_the_background_and_report_their_output(): void
    {
        $user = $this->admin();
        Queue::fake();

        Volt::test('admin.settings')->call('startImport', 'portfolio')->assertSee('wartet auf den Scheduler');
        Queue::assertPushed(RunAdminCommand::class, fn (RunAdminCommand $job) => $job->command === 'portfolio:import-legacy' && $job->arguments === ['email' => $user->email, '--all' => true] && $job->queue === 'admin');

        // The accounts of the old database can be listed and one of them chosen, into another LifeOS account.
        config(['database.connections.rezepteapp' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('rezepteapp');
        Schema::connection('rezepteapp')->create('users', fn ($table) => [$table->string('id'), $table->string('email'), $table->string('name')]);
        DB::connection('rezepteapp')->table('users')->insert(['id' => 'u1', 'email' => 'anna@example.com', 'name' => 'Anna']);
        $partner = User::factory()->create(['email' => 'partner@example.com']);

        Volt::test('admin.settings')
            ->call('loadAccounts', 'recipes')
            ->assertHasNoErrors()
            ->assertSee('anna@example.com (Anna)')
            ->set('importSource.recipes', 'anna@example.com')
            ->set('importTarget.recipes', (string) $partner->id)
            ->call('startImport', 'recipes');
        Queue::assertPushed(RunAdminCommand::class, fn (RunAdminCommand $job) => $job->command === 'recipes:import-rezepteapp' && $job->arguments === ['email' => 'partner@example.com', '--source-email' => 'anna@example.com']);

        config(['database.connections.portfolio_legacy' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '']]);
        Volt::test('admin.settings')->call('loadAccounts', 'portfolio')->assertHasErrors('importSource.portfolio');

        (new RunAdminCommand('portfolio', 'user:admin', ['email' => 'unknown@example.com']))->handle();
        $this->assertSame('failed', Cache::get(RunAdminCommand::cacheKey('portfolio'))['status']);
        Volt::test('admin.settings')->assertSee('No account with this e-mail address.');

        Volt::test('admin.settings')->call('startImport', 'unknown')->assertNotFound();
    }

    public function test_failed_background_jobs_are_listed_with_their_error_and_can_be_cleared(): void
    {
        $this->admin();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Illuminate\\Broadcasting\\BroadcastEvent']),
            'exception' => "Illuminate\\Broadcasting\\BroadcastException: Pusher error: cURL error 7: Failed to connect.\n#0 stack",
            'failed_at' => now(),
        ]);

        Volt::test('admin.settings')
            ->assertSee('1 Hintergrundaufgabe fehlgeschlagen')
            ->assertSee('BroadcastEvent')
            ->assertSee('cURL error 7: Failed to connect.')
            ->assertDontSee('#0 stack')
            ->call('deleteFailedJobs');

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_live_updates_are_sent_to_the_local_reverb_port_and_can_be_tested(): void
    {
        $this->admin();

        Volt::test('admin.settings')
            ->set('form.reverb_publish_port', '3021')
            ->set('form.vpn_socks_port', '25344')
            ->call('save')
            ->assertHasNoErrors();

        AppSettings::apply();
        $this->assertSame('127.0.0.1', config('broadcasting.connections.reverb.options.host'));
        $this->assertSame(3021, config('broadcasting.connections.reverb.options.port'));
        $this->assertSame('http', config('broadcasting.connections.reverb.options.scheme'));

        Volt::test('admin.settings')->call('testRealtime')->assertSee('Reverb hat die Testnachricht angenommen.');

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'key',
            'broadcasting.connections.pusher.secret' => 'secret',
            'broadcasting.connections.pusher.app_id' => '1',
            'broadcasting.connections.pusher.options' => ['host' => '127.0.0.1', 'port' => 9, 'scheme' => 'http', 'useTLS' => false],
        ]);
        Volt::test('admin.settings')->call('testRealtime')->assertSee('Reverb nicht erreichbar');
    }
}
