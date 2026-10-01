<?php

namespace Tests\Feature;

use App\Console\Commands\FetchInboundMail;
use App\Support\Mail\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class InboundMailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.inbound_mail' => [
            'domain' => 'in.example.com', 'host' => '127.0.0.1', 'port' => 993, 'encryption' => 'none',
            'validate_cert' => false, 'username' => 'eingang@in.example.com', 'password' => 'geheim"1', 'daily_limit' => 100,
        ]]);
    }

    /**
     * A mailbox talking to a scripted IMAP server: the answers are written up front, the
     * commands the client sent can be read afterwards.
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

    public function test_mails_are_listed_fetched_and_deleted_without_the_imap_extension(): void
    {
        $mail = "From: a@example.com\nSubject: Hallo\n\nText";
        $sentMail = str_replace("\n", "\r\n", $mail);
        [$mailbox, $server] = $this->mailboxWithServer(
            "* OK Dovecot ready.\n"
            ."A0001 OK Logged in\n"
            ."* 2 EXISTS\nA0002 OK [READ-WRITE] Select completed\n"
            ."* SEARCH 2 1\nA0003 OK Search completed\n"
            ."* 1 FETCH (RFC822.SIZE 46)\nA0004 OK Fetch completed\n"
            .'* 1 FETCH (BODY[] {'.strlen($sentMail)."}\n".$mail.")\nA0005 OK Fetch completed\n"
            ."A0006 OK Store completed\n"
            ."* 1 EXPUNGE\nA0007 OK Expunge completed\n"
            ."* BYE Logging out\nA0008 OK Logout completed\n"
        );

        $mailbox->open();
        $this->assertSame([1, 2], $mailbox->messages(10));
        $this->assertSame(46, $mailbox->size(1));
        $this->assertSame($sentMail, $mailbox->raw(1));
        $mailbox->delete(1);
        $mailbox->close();

        $sent = stream_get_contents($server);
        $this->assertStringContainsString('A0001 LOGIN "eingang@in.example.com" "geheim\\"1"', $sent);
        $this->assertStringContainsString('A0005 FETCH 1 (BODY.PEEK[])', $sent);
        $this->assertStringContainsString('A0006 STORE 1 +FLAGS.SILENT (\\Deleted)', $sent);
        $this->assertStringContainsString('A0007 EXPUNGE', $sent);
        $this->assertTrue(Mailbox::isSupported());
    }

    public function test_a_failed_login_is_reported_and_shown_as_status(): void
    {
        [$mailbox, $server] = $this->mailboxWithServer("* OK ready\nA0001 NO [AUTHENTICATIONFAILED] Authentication failed.\n");

        try {
            $mailbox->open();
            $this->fail('The login should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Authentication failed', $exception->getMessage());
        }

        [$failing, $failingServer] = $this->mailboxWithServer("* OK ready\nA0001 NO [AUTHENTICATIONFAILED] Authentication failed.\n");
        $this->app->instance(Mailbox::class, $failing);
        $this->artisan('mail:fetch')->assertFailed();

        $status = Cache::get(FetchInboundMail::STATUS_KEY);
        $this->assertFalse($status['ok']);
        $this->assertStringContainsString('Authentication failed', $status['error']);
    }
}
