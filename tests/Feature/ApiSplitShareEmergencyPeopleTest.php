<?php

namespace Tests\Feature;

use App\Models\ExpenseGroup;
use App\Models\ExpenseMember;
use App\Models\TrustedPerson;
use App\Models\User;
use App\Notifications\EmergencyAccessUpdate;
use App\Notifications\TrustedPersonInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Share link and export of expense groups, trusted people of the emergency folder (for the app).
 */
class ApiSplitShareEmergencyPeopleTest extends TestCase
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

    public function test_the_share_link_renaming_and_exports(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $group = ExpenseGroup::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'WG']);
        $member = ExpenseMember::create(['expense_group_id' => $group->id, 'name' => 'Anna']);
        $headers = $this->headersFor($user);

        $this->getJson("/api/v1/splits/{$group->id}", $headers)->assertOk()->assertJsonPath('data.share_url', null);
        $url = $this->postJson("/api/v1/splits/{$group->id}/share", [], $headers)->assertOk()->json('data.share_url');
        $this->assertStringContainsString('/k/', $url);
        $this->putJson("/api/v1/splits/{$group->id}/share/expiry", ['days' => 7], $headers)->assertOk();
        $this->assertNotNull($group->refresh()->share_expires_at);
        $this->putJson("/api/v1/splits/{$group->id}/share/expiry", ['days' => 5], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/splits/{$group->id}/share", [], $headers)->assertOk()->assertJsonPath('data.share_url', null);

        $this->putJson("/api/v1/splits/{$group->id}/members/{$member->id}", ['name' => 'Anna B.'], $headers)->assertOk();
        $this->assertSame('Anna B.', $member->refresh()->name);

        $this->get("/api/v1/splits/{$group->id}/export/csv", $headers)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get("/api/v1/splits/{$group->id}/export/pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/splits/{$group->id}/export/xls", $headers)->assertNotFound();
    }

    public function test_trusted_people_of_the_emergency_folder(): void
    {
        Notification::fake();
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $id = $this->postJson('/api/v1/emergency/people', ['name' => 'Oma', 'email' => 'Oma@Example.de', 'wait_days' => 7], $headers)->assertCreated()->assertJsonPath('data.status', 'invited')->json('data.id');
        Notification::assertSentOnDemand(TrustedPersonInvitation::class);
        $this->assertSame('oma@example.de', TrustedPerson::find($id)->email);

        TrustedPerson::find($id)->update(['requested_at' => now()]);
        $this->getJson('/api/v1/emergency/people', $headers)->assertOk()->assertJsonPath('data.0.status', 'waiting');
        $this->postJson("/api/v1/emergency/people/{$id}/allow", [], $headers)->assertOk()->assertJsonPath('data.status', 'granted');
        Notification::assertSentOnDemand(EmergencyAccessUpdate::class);
        $this->postJson("/api/v1/emergency/people/{$id}/decline", [], $headers)->assertOk()->assertJsonPath('data.status', 'invited');
        $this->postJson("/api/v1/emergency/people/{$id}/resend", [], $headers)->assertOk();

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $otherHeaders)->assertOk();
        $this->deleteJson("/api/v1/emergency/people/{$id}", [], $otherHeaders)->assertNotFound();
        $this->deleteJson("/api/v1/emergency/people/{$id}", [], $headers)->assertOk();
        $this->postJson('/api/v1/emergency/people', ['name' => 'X', 'email' => 'nope', 'wait_days' => 99], $headers)->assertStatus(422);
    }
}
