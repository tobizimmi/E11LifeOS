<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for quote comparisons.
 */
class ApiQuoteComparisonsTest extends TestCase
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

    public function test_quotes_are_compared_and_one_is_chosen(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/quote-comparisons', ['title' => 'Küche', 'icon' => '🍳'], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/quote-comparisons/{$id}/offers", ['vendor' => 'Möbel A', 'amount' => 8900, 'rating' => 4, 'url' => 'https://example.com/a'], $headers)->assertCreated();
        $detail = $this->postJson("/api/v1/quote-comparisons/{$id}/offers", ['vendor' => 'Möbel B', 'amount' => 7500], $headers)->assertCreated()->json('data');
        $this->postJson("/api/v1/quote-comparisons/{$id}/offers", ['amount' => 1], $headers)->assertStatus(422);

        $this->assertCount(2, $detail['quote_list']);
        $this->assertSame('Möbel B', $detail['cheapest']['vendor']);
        $cheap = collect($detail['quote_list'])->firstWhere('vendor', 'Möbel B');
        $this->assertTrue($cheap['cheapest']);

        $this->postJson("/api/v1/quote-comparisons/{$id}/choose", ['quote' => $cheap['id']], $headers)->assertOk()->assertJsonPath('data.decided', true)->assertJsonPath('data.chosen.vendor', 'Möbel B');
        $this->putJson("/api/v1/quote-comparisons/{$id}/offers/{$cheap['id']}", ['amount' => 7000], $headers)->assertOk();
        $this->getJson('/api/v1/quote-comparisons', $headers)->assertOk()->assertJsonPath('data.0.quotes', 2);

        $this->deleteJson("/api/v1/quote-comparisons/{$id}/offers/{$cheap['id']}", [], $headers)->assertOk()->assertJsonPath('data.decided', false);
        $this->deleteJson("/api/v1/quote-comparisons/{$id}", [], $headers)->assertOk();
        $this->getJson('/api/v1/quote-comparisons', $headers)->assertJsonCount(0, 'data');
    }

    public function test_private_comparisons_stay_private(): void
    {
        [$user] = $this->memberWithHousehold();
        $stranger = $this->memberWithHousehold()[0];
        $id = $this->postJson('/api/v1/quote-comparisons', ['title' => 'Geheim', 'private' => true], $this->headersFor($user))->json('data.id');

        $this->getJson("/api/v1/quote-comparisons/{$id}", $this->headersFor($stranger))->assertNotFound();
    }
}
