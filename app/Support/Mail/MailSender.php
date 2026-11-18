<?php

namespace App\Support\Mail;

use App\Models\MailAccount;
use RuntimeException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/**
 * Sends a mail through a {@see MailAccount}'s SMTP server (e.g. Proton Bridge on 127.0.0.1:1025).
 * Uses Symfony Mailer so MIME, encoding and STARTTLS are handled correctly; the certificate check
 * is skipped when the account turned it off (the Bridge uses a local, self-signed certificate).
 */
class MailSender
{
    /**
     * @param  array{cc?: string, in_reply_to?: ?string, references?: ?string}  $options
     */
    public function send(MailAccount $account, string $to, string $subject, string $body, array $options = []): void
    {
        if (! $account->smtp_host) {
            throw new RuntimeException(__('This account has no SMTP server set up.'));
        }

        $recipients = $this->addresses($to);

        if ($recipients === []) {
            throw new RuntimeException(__('Please enter at least one valid recipient.'));
        }

        $email = (new Email)
            ->from($account->username)
            ->subject($subject !== '' ? $subject : __('(No subject)'))
            ->text($body !== '' ? $body : ' ');

        foreach ($recipients as $address) {
            $email->addTo($address);
        }

        foreach ($this->addresses($options['cc'] ?? '') as $address) {
            $email->addCc($address);
        }

        if (! empty($options['in_reply_to'])) {
            $email->getHeaders()->addTextHeader('In-Reply-To', $options['in_reply_to']);
        }

        if (! empty($options['references'])) {
            $email->getHeaders()->addTextHeader('References', $options['references']);
        }

        $this->transport($account)->send($email);
    }

    protected function transport(MailAccount $account): EsmtpTransport
    {
        // "ssl" = implicit TLS (SMTPS); "tls"/"none" connect plain and STARTTLS is negotiated
        // automatically when the server offers it (the Bridge does on port 1025).
        $transport = new EsmtpTransport((string) $account->smtp_host, (int) ($account->smtp_port ?: 587), $account->smtp_encryption === 'ssl');
        $transport->setUsername($account->username);
        $transport->setPassword($account->password);

        if ($account->smtp_encryption === 'none') {
            $transport->setAutoTls(false);
        }

        if (! $account->validate_cert) {
            $stream = $transport->getStream();

            if ($stream instanceof SocketStream) {
                $stream->setStreamOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
            }
        }

        return $transport;
    }

    /**
     * @return list<string>
     */
    private function addresses(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[,;]+/', $raw) ?: []),
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }
}
