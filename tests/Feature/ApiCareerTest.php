<?php

namespace Tests\Feature;

use App\Models\CareerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API: career.
 */
class ApiCareerTest extends TestCase
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

    public function test_entries_applications_and_profile(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/career', ['type' => 'job', 'title' => 'Entwickler', 'organization' => 'ACME', 'starts_on' => '2020-01-01', 'grade' => 'ignored'], $headers)->assertCreated()->assertJsonPath('data.current', true)->json('data.id');
        $this->postJson('/api/v1/career', ['type' => 'job', 'title' => ''], $headers)->assertStatus(422);
        $this->postJson('/api/v1/career', ['type' => 'job', 'title' => 'X', 'starts_on' => '2020-02-01', 'ends_on' => '2020-01-01'], $headers)->assertStatus(422);
        $this->putJson("/api/v1/career/{$id}", ['type' => 'job', 'title' => 'Senior', 'starts_on' => '2020-01-01'], $headers)->assertOk()->assertJsonPath('data.title', 'Senior');

        $application = $this->postJson('/api/v1/career', ['type' => 'application', 'title' => 'Stelle', 'status' => 'planned', 'url' => 'https://example.com/job'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/career/{$application}/status", ['status' => 'interview'], $headers)->assertOk()->assertJsonPath('data.details.status', 'interview');
        $this->postJson("/api/v1/career/{$id}/status", ['status' => 'interview'], $headers)->assertStatus(422);
        $this->postJson("/api/v1/career/{$application}/status", ['status' => 'nope'], $headers)->assertStatus(422);

        $this->putJson('/api/v1/career/profile', ['headline' => 'Dev', 'skills' => 'PHP, SQL'], $headers)->assertOk()->assertJsonPath('data.headline', 'Dev');
        $data = $this->getJson('/api/v1/career', $headers)->assertOk()->json('data');
        $this->assertCount(2, $data['entries']);
        $this->assertSame('Dev', $data['profile']['headline']);

        $this->get('/api/v1/career/cv', $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->deleteJson("/api/v1/career/{$id}", [], $headers)->assertOk();
        $this->assertSame(1, CareerEntry::count());
    }

    public function test_entries_are_personal(): void
    {
        [$owner] = $this->memberWithHousehold();
        $entry = $this->postJson('/api/v1/career', ['type' => 'job', 'title' => 'Geheim'], $this->headersFor($owner))->json('data.id');
        [$other] = $this->memberWithHousehold();
        $headers = $this->headersFor($other);

        $this->getJson('/api/v1/career', $headers)->assertOk()->assertJsonPath('data.entries', []);
        $this->putJson("/api/v1/career/{$entry}", ['type' => 'job', 'title' => 'Mein'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/career/{$entry}", [], $headers)->assertNotFound();
    }
}
