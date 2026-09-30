<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Security\VirusScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VirusScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/_test/upload', fn () => response('stored'))->middleware('web');
    }

    public function test_clamd_answers_are_read(): void
    {
        $this->assertSame(VirusScanner::CLEAN, VirusScanner::parseReply("stream: OK\0")['status']);
        $this->assertSame(['status' => VirusScanner::INFECTED, 'signature' => 'Eicar-Test-Signature', 'message' => null], VirusScanner::parseReply("stream: Eicar-Test-Signature FOUND\0"));
        $this->assertSame(VirusScanner::UNAVAILABLE, VirusScanner::parseReply('INSTREAM size limit exceeded. ERROR')['status']);
    }

    public function test_the_instream_protocol_is_spoken_with_clamd(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $socket = sys_get_temp_dir().'/clamd-test-'.getmypid().'.sock';
        @unlink($socket);
        $server = stream_socket_server('unix://'.$socket, $code, $error);
        $pid = pcntl_fork();

        if ($pid === 0) {
            // Fake clamd: reads one INSTREAM command and reports the EICAR string as infected.
            $client = stream_socket_accept($server, 10);
            $command = fread($client, 10);
            $data = '';

            while (true) {
                $length = unpack('N', fread($client, 4))[1];

                if ($length === 0) {
                    break;
                }

                while ($length > 0) {
                    $chunk = fread($client, $length);
                    $data .= $chunk;
                    $length -= strlen($chunk);
                }
            }

            fwrite($client, $command === "zINSTREAM\0" && str_contains($data, 'EICAR') ? "stream: Eicar-Test-Signature FOUND\0" : "stream: OK\0");
            fclose($client);
            exit(0);
        }

        fclose($server);
        config(['security.clamav.socket' => $socket]);

        $result = app(VirusScanner::class)->scanString(VirusScanner::EICAR);
        pcntl_waitpid($pid, $status);
        @unlink($socket);

        $this->assertSame(VirusScanner::INFECTED, $result['status']);
        $this->assertSame('Eicar-Test-Signature', $result['signature']);
    }

    public function test_infected_uploads_are_rejected_and_logged(): void
    {
        $user = User::factory()->create();
        config(['security.clamav.enabled' => true, 'security.clamav.socket' => '/tmp/clamd.sock']);
        $this->mock(VirusScanner::class)->shouldReceive('scanFile')->once()->andReturn(['status' => VirusScanner::INFECTED, 'signature' => 'Win.Trojan.Test', 'message' => null]);

        $this->actingAs($user)->post('/_test/upload', ['document' => UploadedFile::fake()->create('rechnung.pdf', 10)])
            ->assertSessionHasErrors(['document' => '"rechnung.pdf" enthält einen Virus (Win.Trojan.Test) und wurde nicht hochgeladen.']);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'virus_found']);
    }

    public function test_clean_uploads_pass_and_an_unreachable_scanner_only_blocks_when_configured(): void
    {
        config(['security.clamav.enabled' => true, 'security.clamav.socket' => '/tmp/clamd.sock']);
        $scanner = $this->mock(VirusScanner::class);
        $scanner->shouldReceive('scanFile')->once()->andReturn(['status' => VirusScanner::CLEAN, 'signature' => null, 'message' => null]);
        $this->post('/_test/upload', ['document' => UploadedFile::fake()->create('a.pdf', 10)])->assertOk()->assertSee('stored');

        $scanner->shouldReceive('scanFile')->twice()->andReturn(['status' => VirusScanner::UNAVAILABLE, 'signature' => null, 'message' => 'down']);
        $this->post('/_test/upload', ['document' => UploadedFile::fake()->create('b.pdf', 10)])->assertOk();

        config(['security.clamav.fail_closed' => true]);
        $this->post('/_test/upload', ['document' => UploadedFile::fake()->create('c.pdf', 10)])->assertSessionHasErrors('document');
    }

    public function test_without_the_scanner_switched_on_nothing_is_scanned(): void
    {
        $this->mock(VirusScanner::class)->shouldNotReceive('scanFile');

        $this->post('/_test/upload', ['document' => UploadedFile::fake()->create('a.pdf', 10)])->assertOk();
    }

    public function test_the_admin_can_switch_the_virus_scan_on(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        Volt::test('admin.settings')->assertSee('Virenscan (ClamAV)')
            ->set('form.clamav_enabled', '1')->set('form.clamav_socket', 'tcp://127.0.0.1:3310')->call('save')->assertHasNoErrors();

        $this->assertTrue(VirusScanner::isEnabled());
        $this->assertSame('tcp://127.0.0.1:3310', config('security.clamav.socket'));
    }
}
