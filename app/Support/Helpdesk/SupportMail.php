<?php

namespace App\Support\Helpdesk;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Support\Mail\MailIntake;
use App\Support\Mail\MimeMessage;
use App\Support\Security\VirusScanner;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Answers of requesters to a support e-mail: they go to support.<token>@<inbound domain> and land
 * in the ticket as a reply – only from the requester's own address, without the quoted mail.
 */
class SupportMail
{
    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    private const ATTACHMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'text/plain'];

    /**
     * Files the mail into its ticket. Null when it is not addressed to a ticket (the household intake
     * takes it then); true when it became a reply, false when it was dropped.
     */
    public static function process(string $raw): ?bool
    {
        $message = MimeMessage::parse($raw);
        $ticket = self::ticketFor($message);

        if (! $ticket) {
            return null;
        }

        $requester = $ticket->requester;

        if (! $requester || strtolower($message->from) !== strtolower($requester->email) || MailIntake::isAutomatic($message) || $ticket->status === 'closed') {
            return false;
        }

        $body = MailIntake::cleanText($message->plainText());
        $files = self::files($message);

        if ($body === '' && $files === []) {
            return false;
        }

        try {
            $reply = Tickets::reply($ticket, $requester, $body !== '' ? $body : '📎', $files);
            $reply->forceFill(['via' => SupportMessage::VIA_MAIL])->save();
        } finally {
            foreach ($files as $file) {
                @unlink($file->getPathname());
            }
        }

        return true;
    }

    private static function ticketFor(MimeMessage $message): ?SupportTicket
    {
        $domain = MailIntake::domain();

        if ($domain === null) {
            return null;
        }

        foreach ($message->recipients as $recipient) {
            if (preg_match('/^'.preg_quote(SupportTicket::MAIL_PREFIX, '/').'([a-z0-9]{32})@'.preg_quote($domain, '/').'$/', strtolower($recipient), $match)) {
                return SupportTicket::where('reply_token', $match[1])->first();
            }
        }

        return null;
    }

    /**
     * Allowed attachments (virus checked) as uploaded files for the ticket.
     *
     * @return list<UploadedFile>
     */
    private static function files(MimeMessage $message): array
    {
        $files = [];

        foreach ($message->attachments as $attachment) {
            if (count($files) >= self::MAX_ATTACHMENTS) {
                break;
            }

            if (! in_array($attachment['mime'], self::ATTACHMENT_TYPES, true) || $attachment['content'] === '' || strlen($attachment['content']) > self::MAX_ATTACHMENT_BYTES) {
                continue;
            }

            try {
                if (VirusScanner::isEnabled()) {
                    $scan = app(VirusScanner::class)->scanString($attachment['content']);

                    if ($scan['status'] === VirusScanner::INFECTED || ($scan['status'] === VirusScanner::UNAVAILABLE && config('security.clamav.fail_closed'))) {
                        continue;
                    }
                }

                $path = (string) tempnam(sys_get_temp_dir(), 'support');
                file_put_contents($path, $attachment['content']);
                $files[] = new UploadedFile($path, mb_substr($attachment['name'], 0, 150), $attachment['mime'], null, true);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $files;
    }
}
