<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Broker import for the mobile app.
 */
class ApiPortfolioImportTest extends TestCase
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

    public function test_a_csv_is_parsed_and_the_confirmed_rows_are_imported(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        Http::fake(['*' => Http::response([], 500)]);
        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'Depot']);
        $headers = $this->headersFor($user);
        $csv = "date,type,symbol,name,quantity,price,fees\n2026-03-01,buy,SAP.DE,SAP,2,120.50,1\n2026-04-01,sell,SAP.DE,SAP,1,130,1\n";

        $this->getJson('/api/v1/portfolio-import', $headers)->assertOk()->assertJsonFragment(['key' => 'generic']);
        $parsed = $this->post('/api/v1/portfolio-import/parse', ['file' => UploadedFile::fake()->createWithContent('export.csv', $csv), 'broker' => 'generic'], $headers + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(2, 'data.rows')->json('data');

        $this->postJson('/api/v1/portfolio-import/commit', ['portfolio_id' => $portfolio->id, 'broker' => $parsed['broker'], 'rows' => $parsed['rows']], $headers)->assertOk()->assertJsonPath('data.imported', 2);
        $this->assertSame(2, PortfolioTransaction::count());
        $this->postJson('/api/v1/portfolio-import/commit', ['portfolio_id' => $portfolio->id, 'rows' => $parsed['rows']], $headers)->assertOk()->assertJsonPath('data.imported', 0);

        $this->post('/api/v1/portfolio-import/parse', ['file' => UploadedFile::fake()->createWithContent('leer.csv', "foo\n")], $headers + ['Accept' => 'application/json'])->assertUnprocessable();
        $foreign = Portfolio::create(['user_id' => User::factory()->create()->id, 'name' => 'Fremd']);
        $this->postJson('/api/v1/portfolio-import/commit', ['portfolio_id' => $foreign->id, 'rows' => $parsed['rows']], $headers)->assertNotFound();
    }
}
