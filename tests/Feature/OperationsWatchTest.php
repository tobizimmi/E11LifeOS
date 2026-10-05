<?php

namespace Tests\Feature;

use App\Http\Middleware\RecordSlowRequests;
use App\Models\Item;
use App\Models\User;
use App\Support\Backup\BackupVerifier;
use App\Support\ErrorReporter;
use App\Support\Operations\LogFiles;
use App\Support\Operations\SystemHealth;
use App\Support\WebPush;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class OperationsWatchTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/e11-verify-test-'.uniqid();
        config(['backup.path' => $this->directory, 'backup.keep' => 3]);
        Cache::put('scheduler:heartbeat', now()->toIso8601String());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_the_restore_test_loads_the_latest_backup_and_finds_missing_rows(): void
    {
        [, $household] = $this->memberWithHousehold();
        Item::factory()->note()->for($household)->create(['title' => "Zeile mit 'Apostroph'; und Semikolon\nund Umbruch;"]);
        $this->artisan('backup:run')->assertSuccessful();

        $this->artisan('backup:verify')->assertSuccessful();
        $last = Cache::get(SystemHealth::VERIFY_KEY);
        $this->assertTrue($last['ok']);
        // SQLite dumps are loaded into a test database; MySQL needs the scratch connection for that.
        if (config('database.default') === 'sqlite') {
            $this->assertStringContainsString(__('loaded into a test database'), $last['summary']);
        }

        // A backup whose dump lost the rows of a table is caught.
        $latest = SystemHealth::latestBackup();
        $zip = new ZipArchive;
        $zip->open($latest);
        $sql = preg_replace('/^INSERT INTO [`"]items[`"] .*$/m', '', (string) $zip->getFromName('database.sql'));
        $zip->addFromString('database.sql', $sql);
        $zip->close();

        $result = BackupVerifier::verify($latest);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('items', implode(' ', $result['problems']));
    }

    public function test_the_watcher_alerts_admins_once_when_a_check_turns_red_and_when_it_is_fine_again(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create();
        $this->mock(WebPush::class, function (MockInterface $push) use ($admin) {
            $push->shouldReceive('send')->once()->withArgs(fn (User $to, string $title) => $to->is($admin) && str_contains($title, __('Daily backup')));
            $push->shouldReceive('send')->once()->withArgs(fn (User $to, string $title) => $to->is($admin) && str_starts_with($title, '✅'));
        });

        $this->artisan('system:watch')->assertSuccessful();
        $this->artisan('system:watch')->assertSuccessful();
        $this->assertFalse(Cache::get(SystemHealth::STATE_KEY)['backup']);

        $this->artisan('backup:run')->assertSuccessful();
        $this->artisan('system:watch')->assertSuccessful();
        $this->assertTrue(Cache::get(SystemHealth::STATE_KEY)['backup']);
    }

    public function test_server_errors_reach_the_admins_by_push_once_an_hour(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->mock(WebPush::class, fn (MockInterface $push) => $push->shouldReceive('send')->once());

        $exception = new RuntimeException('Datenbank weg');
        ErrorReporter::handle($exception);
        ErrorReporter::handle($exception);
    }

    public function test_requests_are_measured_and_the_admin_page_shows_health_and_slow_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        foreach (range(1, 3) as $request) {
            $this->get(route('admin.system'))->assertOk();
        }

        $this->assertSame(3, Cache::get(RecordSlowRequests::ROUTES_KEY)['admin.system']['count']);
        $this->assertSame('admin.system', RecordSlowRequests::slowestRoutes()[0]['route']);

        Volt::test('admin.system')->assertSee(__('System health'))->assertSee(__('Daily backup'))->assertSee('admin.system');
    }

    public function test_scheduled_callbacks_never_count_as_failed_because_of_a_return_value(): void
    {
        // A database cache write that changes no row returns false – the task must still succeed.
        Cache::shouldReceive('forever')->once()->andReturn(false);
        $heartbeat = collect(app(Schedule::class)->events())->first(fn ($event) => $event->description === 'scheduler-heartbeat');

        $heartbeat->run(app());

        $this->assertSame(0, $heartbeat->exitCode);
    }

    public function test_only_the_own_log_file_counts_and_an_unwritable_one_is_moved_aside(): void
    {
        $dir = storage_path('logs');
        $own = config('logging.channels.single.path');
        $other = $dir.'/laravel-test-other.log';
        $this->assertStringEndsWith('laravel-cli.log', $own);

        File::put($own.'.tmp', '');
        File::put($other, '');
        chmod($other, 0444);
        File::move($own.'.tmp', $dir.'/laravel-cli-2026-10-05.log');
        chmod($dir.'/laravel-cli-2026-10-05.log', 0444);

        try {
            if (is_writable($dir.'/laravel-cli-2026-10-05.log')) {
                $this->markTestSkipped('Running as root: file permissions are not enforced.');
            }

            $this->assertSame([$dir.'/laravel-cli-2026-10-05.log'], LogFiles::unwritable());
            $this->assertSame([], LogFiles::repair());
            $this->assertFileDoesNotExist($dir.'/laravel-cli-2026-10-05.log');
            $this->assertFileExists($other);
        } finally {
            @chmod($other, 0644);
            File::delete([$other, $dir.'/laravel-cli-2026-10-05.log', ...File::glob($dir.'/laravel-cli-2026-10-05.log.unwritable-*')]);
        }
    }
}
