<?php

namespace App\Console\Commands;

use App\Support\Mail\Mailbox;
use App\Support\Mail\MailIntake;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mail:fetch {--limit=50 : Mails per run}')]
#[Description('Fetches the mails sent to the household addresses and files them (bookings, tasks, shopping list, inbox)')]
class FetchInboundMail extends Command
{
    /** Larger mails are deleted unread (attachments beyond that are not kept anyway). */
    private const MAX_BYTES = 30 * 1024 * 1024;

    public function handle(Mailbox $mailbox): int
    {
        if (! Mailbox::isConfigured()) {
            $this->line('Mail intake is not configured.');

            return self::SUCCESS;
        }

        if (! Mailbox::isSupported()) {
            $this->error('The PHP extension "imap" is missing.');

            return self::FAILURE;
        }

        try {
            $mailbox->open();
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $processed = 0;
        $ignored = 0;

        try {
            foreach ($mailbox->messages((int) $this->option('limit')) as $number) {
                try {
                    $log = $mailbox->size($number) > self::MAX_BYTES ? null : MailIntake::process($mailbox->raw($number));
                    $log ? $processed++ : $ignored++;
                } catch (Throwable $exception) {
                    // A broken mail must not block the ones after it.
                    report($exception);
                    $ignored++;
                }

                $mailbox->delete($number);
            }
        } finally {
            $mailbox->close();
        }

        $this->info("Processed: {$processed}, not for any household: {$ignored}");

        return self::SUCCESS;
    }
}
