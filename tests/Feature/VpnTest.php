<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Vpn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VpnTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIG = <<<'CONF'
        [Interface]
        PrivateKey = cGFzc3dvcmRwYXNzd29yZHBhc3N3b3JkcGFzc3dvcmQ=
        Address = 192.168.178.201/24
        DNS = 192.168.178.1

        [Peer]
        PublicKey = cHVibGljcHVibGljcHVibGljcHVibGljcHVibGljcHU=
        # home network
        AllowedIPs = 192.168.178.0/24, 0.0.0.0/0
        Endpoint = abc123.myfritz.net:51820
        CONF;

    public function test_a_wireguard_configuration_is_checked_and_decides_what_goes_through_the_vpn(): void
    {
        $this->assertNotNull(Vpn::parse(self::CONFIG));
        $this->assertNull(Vpn::parse("[Interface]\nPrivateKey = x\n"));
        $this->assertNull(Vpn::parse('no config at all'));

        config(['vpn.enabled' => false, 'vpn.wireguard_config' => self::CONFIG, 'vpn.socks_port' => 25111]);
        $this->assertNull(Vpn::proxyFor('http://192.168.178.20:8000'));

        config(['vpn.enabled' => true]);
        // AllowedIPs without the default route.
        $this->assertSame(['192.168.178.0/24'], Vpn::networks());
        $this->assertSame('socks5h://127.0.0.1:25111', Vpn::proxyFor('http://192.168.178.20:8000'));
        $this->assertSame(['proxy' => 'socks5h://127.0.0.1:25111'], Vpn::httpOptions('http://192.168.178.20:8000/api/'));
        $this->assertSame([], Vpn::httpOptions('https://paperless.example.com'));

        config(['vpn.networks' => '10.0.0.0/8, fritz.box']);
        $this->assertTrue(Vpn::routes('http://paperless.fritz.box:8000'));
        $this->assertTrue(Vpn::routes('http://10.1.2.3'));
        $this->assertFalse(Vpn::routes('http://192.168.178.20'));
        $this->assertFalse(Vpn::routes('https://notfritz.box.example.com'));

        $files = Vpn::writeFiles(storage_path('framework/testing/vpn'));
        $this->assertStringContainsString('BindAddress = 127.0.0.1:25111', file_get_contents($files['wireproxy']));
        $this->assertStringContainsString('Endpoint = abc123.myfritz.net:51820', file_get_contents($files['wireguard']));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($files['wireguard'])), -4));
        // Keepalive is added so routers keep the UDP connection open.
        $this->assertStringContainsString("Endpoint = abc123.myfritz.net:51820\nPersistentKeepalive = 25", file_get_contents($files['wireguard']));
        $this->assertSame(1, substr_count(Vpn::withKeepalive(self::CONFIG."\nPersistentKeepalive = 15"), 'PersistentKeepalive'));
    }

    public function test_a_port_taken_by_the_other_installation_is_avoided(): void
    {
        $taken = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($taken, false), ':'), 1);
        $script = storage_path('framework/testing/fake-wireproxy');
        @mkdir(dirname($script), 0777, true);
        // Stands in for wireproxy: listens on the port from its configuration.
        file_put_contents($script, "#!/usr/bin/env php\n<?php \$c = file_get_contents(\$argv[2]); preg_match('/:(\\d+)/', \$c, \$m); \$s = stream_socket_server('tcp://127.0.0.1:'.\$m[1]); sleep(5);\n");
        chmod($script, 0755);
        config(['vpn.enabled' => true, 'vpn.wireguard_config' => self::CONFIG, 'vpn.socks_port' => $port, 'vpn.binary' => $script, 'vpn.info_port' => 1]);

        $this->artisan('vpn:run', ['--once' => true])->assertSuccessful();

        $status = Vpn::status();
        $this->assertSame('running', $status['state']);
        $this->assertSame(Vpn::defaultSocksPort(), $status['port']);
        $this->assertStringContainsString((string) $port, (string) $status['message']);
        $this->assertSame('socks5h://127.0.0.1:'.Vpn::defaultSocksPort(), Vpn::proxyFor('http://192.168.178.20'));
        fclose($taken);
    }

    public function test_the_vpn_is_set_up_on_the_admin_page_and_the_runner_reports_its_state(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        Volt::test('admin.settings')
            ->set('form.vpn_enabled', '1')
            ->set('form.vpn_wireguard_config', "[Interface]\nPrivateKey = x")
            ->call('save')
            ->assertHasErrors('form.vpn_wireguard_config')
            ->set('form.vpn_wireguard_config', self::CONFIG)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Durch das VPN: 192.168.178.0/24');

        $this->assertTrue(Vpn::isConfigured());
        $this->assertNotSame(self::CONFIG, \DB::table('app_settings')->where('key', 'vpn_wireguard_config')->value('value'));
        $this->assertSame(trim(self::CONFIG), AppSetting::where('key', 'vpn_wireguard_config')->value('value'));

        config(['vpn.binary' => '/nonexistent/wireproxy']);
        $this->artisan('vpn:run', ['--once' => true])->assertSuccessful();
        $this->assertSame('error', Vpn::status()['state']);
        $this->assertFalse(Vpn::isRunning());

        Volt::test('admin.settings')->assertSee('wireproxy ist nicht installiert');
        Volt::test('admin.settings')->call('testVpn')->assertHasErrors('vpnTestUrl');
    }

    public function test_a_failed_connection_test_names_the_likely_cause(): void
    {
        config(['vpn.enabled' => true, 'vpn.socks_port' => 25111, 'vpn.info_port' => 26111, 'vpn.wireguard_config' => str_replace(['192.168.178.0/24, 0.0.0.0/0', 'abc123.myfritz.net'], ['10.8.0.0/24', '92.217.95.163'], self::CONFIG)]);
        $handshake = 0;
        $timeout = Http::failedConnection('cURL error 28: Connection timed out after 10001 milliseconds');
        Http::fake(function (Request $request) use (&$handshake, $timeout) {
            return str_ends_with($request->url(), ':26111/metrics')
                ? Http::response("public_key=abc\nendpoint=1.2.3.4:51820\nlast_handshake_time_sec={$handshake}\nrx_bytes=0\ntx_bytes=1480\n")
                : $timeout($request);
        });

        $result = Vpn::test('http://192.168.10.210:2212');

        $this->assertFalse($result['ok']);
        $hints = implode(' ', $result['hints']);
        $this->assertStringContainsString('192.168.10.210 ist in AllowedIPs', $hints);
        $this->assertStringContainsString('192.168.10.0/24', $hints);
        $this->assertStringContainsString('Noch kein WireGuard-Handshake', $hints);
        $this->assertStringContainsString('feste IP-Adresse', $hints);

        // Tunnel up and the network covered: the problem lies behind the router.
        config(['vpn.wireguard_config' => str_replace(['10.8.0.0/24', '92.217.95.163'], ['192.168.10.0/24', 'abc123.myfritz.net'], config('vpn.wireguard_config'))]);
        $handshake = now()->subSeconds(40)->getTimestamp();

        $hints = Vpn::test('http://192.168.10.210:2212')['hints'];
        $this->assertCount(1, $hints);
        $this->assertStringContainsString('Der Tunnel steht', $hints[0]);
    }
}
