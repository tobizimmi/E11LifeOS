<?php

namespace Tests\Feature;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Admin\ModuleRights;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * EnsureModuleAllowed and LogSensitiveAccess only ran as regular web middleware, so they never
 * saw a Livewire update request (the browser's AJAX call for actions and property changes on an
 * already-loaded page) – a member who lost access to a module mid-session could keep using it
 * until the next full page reload. These tests exercise the real HTTP endpoint Livewire's
 * frontend calls, not the in-process Volt::test() helper, since that bypasses the middleware
 * pipeline entirely.
 */
class LivewireModuleGuardTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /**
     * Renders a page and returns the raw wire:snapshot payload of the named Livewire component,
     * exactly as Livewire's own JavaScript would read it from the page before posting it back.
     */
    private function snapshotFor(string $html, string $componentName): string
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $raw) {
            $decoded = html_entity_decode($raw);

            if (($componentName) === data_get(json_decode($decoded, true), 'memo.name')) {
                return $decoded;
            }
        }

        $this->fail("Snapshot for component [{$componentName}] not found.");
    }

    public function test_a_livewire_update_on_a_module_locked_mid_session_is_rejected_not_just_the_page_load(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $admin = $this->admin();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $html = $this->get(route('health.index'))->assertOk()->getContent();
        $snapshot = $this->snapshotFor($html, 'health.index');

        // While still allowed, a Livewire property update goes through normally.
        $this->withoutMiddleware(ValidateCsrfToken::class)->postJson('/livewire/update', [
            'components' => [['snapshot' => $snapshot, 'updates' => ['tab' => 'prevention'], 'calls' => []]],
        ])->assertOk();

        // The administrator locks the module without the member reloading the page.
        ModuleRights::set($admin, 'household', $household->id, 'health', 'blocked');

        $this->withoutMiddleware(ValidateCsrfToken::class)->postJson('/livewire/update', [
            'components' => [['snapshot' => $snapshot, 'updates' => ['tab' => 'medications'], 'calls' => []]],
        ])->assertNotFound();
    }

    public function test_a_livewire_update_on_a_sensitive_area_is_logged_like_the_page_load(): void
    {
        [$owner] = $this->memberWithHousehold();

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $html = $this->get(route('emergency.index'))->assertOk()->getContent();
        $snapshot = $this->snapshotFor($html, 'emergency.index');
        $this->assertSame(1, SecurityEvent::where('user_id', $owner->id)->where('event', 'sensitive_area')->count());

        SecurityEvent::query()->delete();

        $this->withoutMiddleware(ValidateCsrfToken::class)->postJson('/livewire/update', [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
        ])->assertOk();

        $this->assertSame('emergency', SecurityEvent::where('user_id', $owner->id)->where('event', 'sensitive_area')->sole()->details);
    }
}
