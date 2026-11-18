<?php

namespace Tests\Feature;

use App\Models\MailAccount;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A mailbox talking to a scripted IMAP server: answers are written up front, the commands the
     * client sent can be read afterwards.
     *
     * @return array{0: Mailbox, 1: resource}
     */
    private function mailboxWithServer(string $answers): array
    {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, str_replace("\n", "\r\n", $answers));
        $mailbox = new Mailbox;
        $mailbox->useStream($client);

        return [$mailbox, $server];
    }

    public function test_new_mails_are_pulled_into_the_store_without_deleting_them_on_the_server(): void
    {
        $account = MailAccount::factory()->create(['imap_encryption' => 'none']);

        $mail1 = "From: Shop <a@shop.example>\nSubject: Bestellung\nDate: Mon, 17 Nov 2026 10:00:00 +0000\nMessage-ID: <m1@shop.example>\n\nDein Paket kommt bald.";
        $mail2 = "From: Chef <chef@work.example>\nSubject: Meeting\nDate: Mon, 17 Nov 2026 11:00:00 +0000\nMessage-ID: <m2@work.example>\n\nMorgen um 9.";
        $sent1 = str_replace("\n", "\r\n", $mail1);
        $sent2 = str_replace("\n", "\r\n", $mail2);

        [$mailbox, $server] = $this->mailboxWithServer(
            "* OK IMAP ready\n"
            ."A0001 OK Logged in\n"
            ."* 2 EXISTS\n* OK [UIDVALIDITY 100] Ok\nA0002 OK [READ-WRITE] Selected\n"
            ."* SEARCH 1 2\nA0003 OK Search completed\n"
            .'* 1 FETCH (UID 1 FLAGS () RFC822.SIZE '.strlen($sent1).' BODY[] {'.strlen($sent1)."}\n".$mail1.")\nA0004 OK Fetch completed\n"
            .'* 2 FETCH (UID 2 FLAGS (\Seen) RFC822.SIZE '.strlen($sent2).' BODY[] {'.strlen($sent2)."}\n".$mail2.")\nA0005 OK Fetch completed\n"
            ."* BYE Logging out\nA0006 OK Logout completed\n"
        );
        $this->app->instance(Mailbox::class, $mailbox);

        $stored = app(MailSync::class)->sync($account);

        $this->assertSame(2, $stored);
        $this->assertSame(2, $account->messages()->count());

        $first = $account->messages()->where('uid', 1)->sole();
        $this->assertSame('Bestellung', $first->subject);
        $this->assertSame('a@shop.example', $first->from_address);
        $this->assertStringContainsString('Dein Paket', (string) $first->body_text);
        $this->assertFalse($first->is_read);

        $second = $account->messages()->where('uid', 2)->sole();
        $this->assertTrue($second->is_read); // \Seen on the server

        $this->assertSame(100, $account->fresh()->folder_uidvalidity['INBOX']);
        $this->assertNotNull($account->fresh()->last_synced_at);

        $commands = stream_get_contents($server);
        $this->assertStringContainsString('A0003 UID SEARCH ALL', $commands);
        $this->assertStringContainsString('A0004 UID FETCH 1 (FLAGS RFC822.SIZE BODY.PEEK[])', $commands);
        $this->assertStringContainsString('A0006 LOGOUT', $commands);
        // A client must never delete or expunge the member's server mail.
        $this->assertStringNotContainsString('EXPUNGE', $commands);
        $this->assertStringNotContainsString('\\Deleted', $commands);
    }

    public function test_only_newer_uids_are_fetched_on_the_next_run(): void
    {
        $account = MailAccount::factory()->create(['imap_encryption' => 'none', 'folder_uidvalidity' => ['INBOX' => 100]]);
        $account->messages()->create(['folder' => 'INBOX', 'uid' => 1, 'subject' => 'alt', 'is_read' => true]);

        $mail = "From: n@example.com\nSubject: Neu\n\nNeuer Text";
        $sent = str_replace("\n", "\r\n", $mail);

        [$mailbox, $server] = $this->mailboxWithServer(
            "* OK ready\nA0001 OK Logged in\n"
            ."* OK [UIDVALIDITY 100] Ok\nA0002 OK Selected\n"
            ."* SEARCH 1 2\nA0003 OK done\n"
            .'* 2 FETCH (UID 2 FLAGS () RFC822.SIZE '.strlen($sent).' BODY[] {'.strlen($sent)."}\n".$mail.")\nA0004 OK done\n"
            ."* BYE\nA0005 OK Logout\n"
        );
        $this->app->instance(Mailbox::class, $mailbox);

        $stored = app(MailSync::class)->sync($account);

        $this->assertSame(1, $stored);
        $this->assertSame(2, $account->messages()->count());
        $commands = stream_get_contents($server);
        $this->assertStringContainsString('UID FETCH 2', $commands);
        $this->assertStringNotContainsString('UID FETCH 1 ', $commands); // uid 1 already stored
    }

    public function test_a_changed_uidvalidity_drops_the_cached_folder_and_resyncs(): void
    {
        $account = MailAccount::factory()->create(['imap_encryption' => 'none', 'folder_uidvalidity' => ['INBOX' => 100]]);
        $account->messages()->create(['folder' => 'INBOX', 'uid' => 9, 'subject' => 'stale', 'is_read' => true]);

        $mail = "From: n@example.com\nSubject: Frisch\n\nFrischer Text";
        $sent = str_replace("\n", "\r\n", $mail);

        [$mailbox, $server] = $this->mailboxWithServer(
            "* OK ready\nA0001 OK Logged in\n"
            ."* OK [UIDVALIDITY 777] Ok\nA0002 OK Selected\n"
            ."* SEARCH 1\nA0003 OK done\n"
            .'* 1 FETCH (UID 1 FLAGS () RFC822.SIZE '.strlen($sent).' BODY[] {'.strlen($sent)."}\n".$mail.")\nA0004 OK done\n"
            ."* BYE\nA0005 OK Logout\n"
        );
        $this->app->instance(Mailbox::class, $mailbox);
        $this->assertIsResource($server); // keep the scripted server socket alive for the duration

        app(MailSync::class)->sync($account);

        $this->assertNull($account->messages()->where('uid', 9)->first()); // stale cache dropped
        $this->assertNotNull($account->messages()->where('uid', 1)->first());
        $this->assertSame(777, $account->fresh()->folder_uidvalidity['INBOX']);
    }
}
