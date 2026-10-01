<?php

namespace App\Support\Mail;

use IMAP\Connection;
use RuntimeException;

/**
 * The catch-all mailbox of the mail intake on the server's own mail service, read via IMAP.
 * Processed mails are deleted from it (their content lives on in the app).
 */
class Mailbox
{
    private ?Connection $connection = null;

    public static function isConfigured(): bool
    {
        return MailIntake::domain() !== null && filled(config('services.inbound_mail.username')) && filled(config('services.inbound_mail.password'));
    }

    public static function isSupported(): bool
    {
        return function_exists('imap_open');
    }

    public function open(): void
    {
        $config = config('services.inbound_mail');
        $flags = '/imap'.(in_array($config['encryption'], ['ssl', 'tls'], true) ? '/'.$config['encryption'] : '/notls').($config['validate_cert'] ? '' : '/novalidate-cert');
        $connection = @imap_open('{'.$config['host'].':'.$config['port'].$flags.'}INBOX', (string) $config['username'], (string) $config['password'], 0, 1);

        if ($connection === false) {
            $error = imap_last_error() ?: 'unknown error';
            imap_errors();

            throw new RuntimeException('Mailbox not reachable: '.$error);
        }

        $this->connection = $connection;
    }

    /**
     * Numbers of the mails in the mailbox (oldest first, at most $limit).
     *
     * @return list<int>
     */
    public function messages(int $limit): array
    {
        $numbers = imap_search($this->connection, 'ALL') ?: [];

        return array_slice(array_map('intval', $numbers), 0, $limit);
    }

    public function size(int $number): int
    {
        $overview = imap_fetch_overview($this->connection, (string) $number);

        return (int) ($overview[0]->size ?? 0);
    }

    public function raw(int $number): string
    {
        return imap_fetchheader($this->connection, $number).(string) imap_body($this->connection, $number, FT_PEEK);
    }

    public function delete(int $number): void
    {
        imap_delete($this->connection, (string) $number);
    }

    public function close(): void
    {
        if ($this->connection) {
            imap_expunge($this->connection);
            imap_close($this->connection);
            imap_errors();
            imap_alerts();
            $this->connection = null;
        }
    }
}
