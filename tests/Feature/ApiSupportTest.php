<?php

namespace Tests\Feature;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Help & support for the mobile app.
 */
class ApiSupportTest extends TestCase
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

    public function test_write_follow_answer_solve_and_rate(): void
    {
        Storage::fake('local');
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/support', $headers)->assertOk()->assertJsonPath('data.tickets', [])->assertJsonStructure(['data' => ['types', 'areas']]);

        $id = $this->postJson('/api/v1/support', ['type' => 'bug', 'subject' => 'Fehler beim Speichern', 'body' => 'Es passiert nichts.', 'priority' => 'high'], $headers)
            ->assertCreated()->assertJsonPath('data.subject', 'Fehler beim Speichern')->assertJsonPath('data.messages.0.mine', true)->json('data.id');

        $this->postJson('/api/v1/support', ['type' => 'task', 'subject' => 'x', 'body' => 'y'], $headers)->assertUnprocessable();

        $this->postJson("/api/v1/support/{$id}/reply", ['body' => 'Mit Screenshot.', 'files' => [UploadedFile::fake()->image('shot.png')]], $headers)
            ->assertOk()->assertJsonPath('data.messages.1.files.0.name', 'shot.png');
        $messageId = $this->getJson("/api/v1/support/{$id}", $headers)->json('data.messages.1.id');
        $this->get("/api/v1/support/{$id}/messages/{$messageId}/files/0", $headers)->assertOk();

        $this->postJson("/api/v1/support/{$id}/rate", ['rating' => 'good'], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/support/{$id}/solve", [], $headers)->assertOk()->assertJsonPath('data.status', 'solved')->assertJsonPath('data.can_rate', true);
        $this->postJson("/api/v1/support/{$id}/rate", ['rating' => 'good', 'comment' => 'Danke'], $headers)->assertOk()->assertJsonPath('data.can_rate', false);

        $this->assertCount(1, $this->getJson('/api/v1/support', $headers)->json('data.tickets'));
    }

    public function test_tickets_of_others_are_invisible(): void
    {
        [$owner] = $this->memberWithHousehold();
        [$other] = $this->memberWithHousehold();
        $ticket = SupportTicket::factory()->create(['user_id' => $owner->id]);
        $headers = $this->headersFor($other);

        $this->getJson("/api/v1/support/{$ticket->id}", $headers)->assertNotFound();
        $this->postJson("/api/v1/support/{$ticket->id}/reply", ['body' => 'hi'], $headers)->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/support', $headers)->json('data.tickets'));
    }
}
