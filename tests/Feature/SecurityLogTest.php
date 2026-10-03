<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Support\Security\SecurityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private function request(string $agent, string $ip = '203.0.113.7'): Request
    {
        return Request::create('/login', 'POST', server: ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => $ip]);
    }

    public function test_sign_ins_are_logged_and_a_new_device_is_reported(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'password')->call('login');
        $this->assertSame(['login'], SecurityEvent::pluck('event')->all());

        SecurityLog::login($user, $this->request(self::IPHONE));
        SecurityLog::login($user, $this->request(self::IPHONE));
        Notification::assertSentToTimes($user, SecurityAlert::class, 1);
        Notification::assertSentTo($user, SecurityAlert::class, fn (SecurityAlert $alert) => in_array('Gerät: Safari auf iPhone', $alert->lines, true));
        $this->assertSame(['login', 'login_new_device', 'login'], SecurityEvent::orderBy('id')->pluck('event')->all());
        $this->assertSame('🔐 Neue Anmeldung bei deinem Konto', $user->notifications()->sole()->data['title']);
        $this->assertSame('Chrome auf Windows', SecurityLog::device(self::WINDOWS));
    }

    public function test_repeated_failed_sign_ins_lock_the_account_and_alert_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        foreach (range(1, 7) as $attempt) {
            Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'falsch')->call('login');
        }

        $this->assertSame(5, SecurityEvent::where('event', 'login_failed')->count());
        $this->assertSame(2, SecurityEvent::where('event', 'lockout')->count());
        Notification::assertSentToTimes($user, SecurityAlert::class, 1);
    }

    public function test_sensitive_areas_idle_logout_and_other_devices_are_logged(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('health.index'))->assertOk();
        $this->withSession(['auth.password_confirmed_at' => time()])->get(route('health.index'))->assertOk();
        $this->assertSame(1, SecurityEvent::where('event', 'sensitive_area')->where('user_id', $user->id)->count());

        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'andere-sitzung', 'user_id' => $user->id, 'ip_address' => '198.51.100.4', 'user_agent' => self::WINDOWS, 'payload' => '', 'last_activity' => time()]);
        Volt::test('profile.security-form')
            ->assertSee('Chrome auf Windows')
            ->assertSeeText('Sensibler Bereich geöffnet · Gesundheit')
            ->call('revokeSession', 'andere-sitzung')
            ->set('password', 'falsch')->call('logoutOtherDevices')->assertHasErrors('password')
            ->set('password', 'password')->call('logoutOtherDevices')->assertHasNoErrors();
        $this->assertSame(0, DB::table('sessions')->where('id', 'andere-sitzung')->count());
        $this->assertTrue(SecurityEvent::where('event', 'logout_other_devices')->exists());

        $this->post(route('logout.idle'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(SecurityEvent::where('event', 'idle_logout')->exists());
    }

    public function test_browsers_signed_in_with_remember_me_are_not_signed_out_for_inactivity(): void
    {
        [$user] = $this->memberWithHousehold();
        $recaller = Auth::guard('web')->getRecallerName();

        $this->actingAs($user)->get(route('dashboard'))->assertSee('id="idle-logout"', false);
        $this->withCookie($recaller, 'remembered')->get(route('dashboard'))->assertDontSee('id="idle-logout"', false);

        $this->withCookie($recaller, 'remembered')->post(route('logout.idle'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }
}
