<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mail for the mobile app: accounts, reading and taking a mail over.
 */
class ApiMailTest extends TestCase
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

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return ['label' => 'Privat', 'provider' => 'generic_imap', 'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl', 'smtp_host' => '', 'validate_cert' => true, 'username' => 'me@example.com', 'password' => 'geheim'];
    }

    public function test_accounts_are_private_and_never_return_the_password(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/mail/accounts', $this->payload(), $headers)->assertCreated()->assertJsonMissingPath('data.password')->json('data.id');
        $this->assertSame('geheim', MailAccount::find($id)->password);
        $this->postJson('/api/v1/mail/accounts', ['password' => null] + $this->payload(), $headers)->assertStatus(422);

        $this->putJson("/api/v1/mail/accounts/{$id}", ['label' => 'Arbeit', 'password' => ''] + $this->payload(), $headers)->assertOk()->assertJsonPath('data.label', 'Arbeit');
        $this->assertSame('geheim', MailAccount::find($id)->password);
        $this->getJson('/api/v1/mail/accounts', $headers)->assertOk()->assertJsonPath('data.0.label', 'Arbeit')->assertJsonPath('data.0.unread', 0);

        $other = $this->addMemberTo($household);
        $otherHeaders = $this->headersFor($other);
        $this->getJson('/api/v1/mail/accounts', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/mail/accounts/{$id}", [], $otherHeaders)->assertNotFound();
        $this->deleteJson("/api/v1/mail/accounts/{$id}", [], $headers)->assertOk();
        $this->assertSame(0, MailAccount::count());
    }

    public function test_connection_test_and_sync(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $this->mock(Mailbox::class, function (MockInterface $mailbox) {
            $mailbox->shouldReceive('open')->once();
            $mailbox->shouldReceive('selectFolder')->once()->andReturn(['exists' => 7, 'uidvalidity' => 1, 'uidnext' => 8]);
            $mailbox->shouldReceive('logout')->once();
        });

        $this->postJson('/api/v1/mail/accounts/test', $this->payload(), $headers)->assertOk()->assertJsonPath('data.ok', true);

        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()->id]);
        $this->mock(MailSync::class, fn (MockInterface $sync) => $sync->shouldReceive('sync')->once()->andReturn(3));
        $this->postJson("/api/v1/mail/accounts/{$account->id}/sync", [], $headers)->assertOk()->assertJsonPath('data.new', 3);
    }

    public function test_reading_and_taking_a_mail_over(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()->id]);
        $mail = MailMessage::factory()->create(['mail_account_id' => $account->id, 'subject' => 'Einkauf', 'body_text' => "Milch\n- Brot\nEier"]);
        MailMessage::factory()->create(['mail_account_id' => $account->id, 'subject' => 'Rechnung Strom']);

        $this->getJson('/api/v1/mail/accounts', $headers)->assertJsonPath('data.0.unread', 2);
        $this->getJson("/api/v1/mail/accounts/{$account->id}/messages?q=strom", $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Rechnung Strom');
        $this->getJson("/api/v1/mail/messages/{$mail->id}", $headers)->assertOk()->assertJsonPath('data.subject', 'Einkauf');
        $this->assertTrue($mail->refresh()->is_read);

        $this->postJson("/api/v1/mail/messages/{$mail->id}/shopping", [], $headers)->assertOk()->assertJsonPath('data.added', 3);
        $this->postJson("/api/v1/mail/messages/{$mail->id}/task", [], $headers)->assertOk();
        $this->postJson("/api/v1/mail/messages/{$mail->id}/inbox", [], $headers)->assertOk();
        $this->assertSame('inbox', $mail->refresh()->processed_as);

        [$stranger] = $this->memberWithHousehold();
        $this->getJson("/api/v1/mail/messages/{$mail->id}", $this->headersFor($stranger))->assertNotFound();
    }
}
