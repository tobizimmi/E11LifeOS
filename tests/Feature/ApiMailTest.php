<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\User;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSender;
use App\Support\Mail\MailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->getJson('/api/v1/mail/accounts', $headers)->assertOk()->assertJsonPath('data.accounts.0.label', 'Arbeit')->assertJsonPath('data.accounts.0.unread', 0)->assertJsonPath('data.swipe.left', 'archive');

        $other = $this->addMemberTo($household);
        $otherHeaders = $this->headersFor($other);
        $this->getJson('/api/v1/mail/accounts', $otherHeaders)->assertOk()->assertJsonCount(0, 'data.accounts');
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
        $this->mock(MailSync::class, fn (MockInterface $sync) => $sync->shouldReceive('syncAccount')->once()->andReturn(3));
        $this->postJson("/api/v1/mail/accounts/{$account->id}/sync", [], $headers)->assertOk()->assertJsonPath('data.new', 3);
    }

    public function test_reading_and_taking_a_mail_over(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()->id]);
        $mail = MailMessage::factory()->create(['mail_account_id' => $account->id, 'subject' => 'Einkauf', 'body_text' => "Milch\n- Brot\nEier"]);
        MailMessage::factory()->create(['mail_account_id' => $account->id, 'subject' => 'Rechnung Strom']);

        $this->getJson('/api/v1/mail/accounts', $headers)->assertJsonPath('data.accounts.0.unread', 2);
        $this->getJson("/api/v1/mail/accounts/{$account->id}/messages?q=strom", $headers)->assertOk()->assertJsonCount(1, 'data.threads')->assertJsonPath('data.threads.0.subject', 'Rechnung Strom')->assertJsonPath('data.folders.0', 'INBOX');
        $this->getJson("/api/v1/mail/messages/{$mail->id}", $headers)->assertOk()->assertJsonPath('data.subject', 'Einkauf');
        $this->assertTrue($mail->refresh()->is_read);

        $this->postJson("/api/v1/mail/messages/{$mail->id}/shopping", [], $headers)->assertOk()->assertJsonPath('data.added', 3);
        $this->postJson("/api/v1/mail/messages/{$mail->id}/task", [], $headers)->assertOk();
        $this->postJson("/api/v1/mail/messages/{$mail->id}/note", [], $headers)->assertOk()->assertJsonPath('data.processed_as', 'note');
        $this->postJson("/api/v1/mail/messages/{$mail->id}/inbox", [], $headers)->assertOk();
        $this->assertSame('inbox', $mail->refresh()->processed_as);

        [$stranger] = $this->memberWithHousehold();
        $this->getJson("/api/v1/mail/messages/{$mail->id}", $this->headersFor($stranger))->assertNotFound();
    }

    public function test_threads_html_and_actions_on_the_server(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()->id]);
        $first = MailMessage::factory()->create(['mail_account_id' => $account->id, 'thread_key' => 't1', 'subject' => 'Plan', 'body_html' => '<p>Hi</p><img src="https://track.example/x.gif"><script>alert(1)</script>']);
        MailMessage::factory()->create(['mail_account_id' => $account->id, 'thread_key' => 't1', 'subject' => 'Re: Plan', 'date' => now()->subDay()]);
        MailMessage::factory()->create(['mail_account_id' => $account->id, 'folder' => 'Sent']);

        $response = $this->getJson("/api/v1/mail/accounts/{$account->id}/messages", $headers)->assertOk();
        $this->assertSame(['INBOX', 'Sent'], $response->json('data.folders'));
        $this->assertSame(1, count($response->json('data.threads')));
        $this->assertSame(2, $response->json('data.threads.0.count'));
        $this->getJson("/api/v1/mail/accounts/{$account->id}/messages?folder=Sent", $headers)->assertOk()->assertJsonCount(1, 'data.threads');

        $open = $this->getJson("/api/v1/mail/messages/{$first->id}", $headers)->assertOk()->assertJsonPath('data.images_blocked', true)->assertJsonCount(1, 'data.thread');
        $this->assertStringNotContainsString('<script', (string) $open->json('data.html'));
        $this->assertStringNotContainsString('track.example', (string) $open->json('data.html'));
        $this->assertStringContainsString('track.example', (string) $this->getJson("/api/v1/mail/messages/{$first->id}?images=1", $headers)->json('data.html'));

        $this->mock(Mailbox::class, function (MockInterface $mailbox) use ($first) {
            $mailbox->shouldReceive('open')->times(3);
            $mailbox->shouldReceive('selectFolder')->times(3)->with('INBOX');
            $mailbox->shouldReceive('uidStore')->once()->with($first->uid, '\\Flagged', true);
            $mailbox->shouldReceive('uidStore')->once()->with($first->uid, '\\Seen', false);
            $mailbox->shouldReceive('uidMove')->once()->with($first->uid, 'Archive');
            $mailbox->shouldReceive('logout')->times(3);
        });
        $this->postJson("/api/v1/mail/messages/{$first->id}/flag", [], $headers)->assertOk()->assertJsonPath('data.is_flagged', true);
        $this->postJson("/api/v1/mail/messages/{$first->id}/read", [], $headers)->assertOk()->assertJsonPath('data.is_read', false);
        $this->postJson("/api/v1/mail/messages/{$first->id}/archive", [], $headers)->assertOk();
        $this->assertNull(MailMessage::find($first->id));
    }

    public function test_sending_drafts_and_swipe_actions(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = MailAccount::factory()->create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()->id]);
        $original = MailMessage::factory()->create(['mail_account_id' => $account->id, 'message_id' => 'abc@example.com']);

        $draft = $this->postJson("/api/v1/mail/accounts/{$account->id}/drafts", ['to' => 'a@example.com', 'subject' => 'Entwurf'], $headers)->assertCreated()->assertJsonPath('data.label', 'Entwurf')->json('data.id');
        $this->postJson("/api/v1/mail/accounts/{$account->id}/drafts", ['id' => $draft, 'to' => 'b@example.com', 'subject' => 'Entwurf'], $headers)->assertCreated()->assertJsonPath('data.id', $draft);
        $this->getJson("/api/v1/mail/accounts/{$account->id}/drafts", $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.to', 'b@example.com');

        $this->mock(MailSender::class, fn (MockInterface $sender) => $sender->shouldReceive('send')->once()->withArgs(fn ($acc, $to, $subject, $body, $options) => $to === 'b@example.com' && $options['cc'] === 'c@example.com' && $options['in_reply_to'] === '<abc@example.com>' && count($options['attachments']) === 1));
        $this->post("/api/v1/mail/accounts/{$account->id}/send", ['to' => 'b@example.com', 'cc' => 'c@example.com', 'subject' => 'Re: x', 'body' => 'Hallo', 'reply_to_id' => $original->id, 'draft_id' => $draft, 'attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], $headers + ['Accept' => 'application/json'])->assertOk();
        $this->getJson("/api/v1/mail/accounts/{$account->id}/drafts", $headers)->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/mail/accounts/{$account->id}/send", [], $headers)->assertStatus(422);

        $this->putJson('/api/v1/mail/swipe', ['right' => 'trash', 'left' => 'read'], $headers)->assertOk()->assertJsonPath('data.right', 'trash');
        $this->putJson('/api/v1/mail/swipe', ['right' => 'explode'], $headers)->assertStatus(422);
    }
}
