<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiriShortcutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_a_shortcut_with_an_embedded_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.siri-shortcut'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/octet-stream');
        $this->assertStringContainsString('.shortcut"', (string) $response->headers->get('content-disposition'));

        // A token was issued for this download …
        $token = ApiToken::where('user_id', $user->id)->sole();
        $this->assertStringStartsWith('Siri / ', (string) $token->name);

        // … and the plist carries the endpoint, the Bearer header and the dictate→POST wiring.
        $body = (string) $response->getContent();
        $this->assertStringContainsString('api/v1/shopping/quick', $body);
        $this->assertStringContainsString('is.workflow.actions.dictatetext', $body);
        $this->assertStringContainsString('is.workflow.actions.downloadurl', $body);
        $this->assertStringContainsString('Bearer ', $body);
    }

    public function test_it_requires_a_signed_in_user(): void
    {
        $this->get(route('profile.siri-shortcut'))->assertRedirect(route('login'));
    }
}
