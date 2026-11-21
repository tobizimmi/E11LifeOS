<?php

namespace Tests\Unit;

use App\Support\Mail\MimeMessage;
use Tests\TestCase;

class MimeMessageTest extends TestCase
{
    public function test_a_large_base64_attachment_is_parsed_without_exhausting_memory(): void
    {
        $bytes = random_bytes(1_000_000); // 1 MB binary payload
        $boundary = 'part-boundary-xyz';
        $raw = implode("\r\n", [
            'From: Sender <sender@example.com>',
            'To: me@example.com',
            'Subject: Rechnung',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
            '',
            '--'.$boundary,
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Hallo, im Anhang die Rechnung.',
            '--'.$boundary,
            'Content-Type: application/pdf; name="rechnung.pdf"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="rechnung.pdf"',
            '',
            chunk_split(base64_encode($bytes), 76, "\r\n"),
            '--'.$boundary.'--',
            '',
        ]);

        $message = MimeMessage::parse($raw);

        $this->assertSame('Rechnung', $message->subject);
        $this->assertStringContainsString('im Anhang', $message->plainText());
        $this->assertCount(1, $message->attachments);
        $this->assertSame('rechnung.pdf', $message->attachments[0]['name']);
        $this->assertSame('application/pdf', $message->attachments[0]['mime']);
        $this->assertSame($bytes, $message->attachments[0]['content']);
    }
}
