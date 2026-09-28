<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Vpn;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
