<?php

namespace App\Support\Mail;

use RuntimeException;

/**
 * The catch-all mailbox of the mail intake on the server's own mail service, read via IMAP.
 * Processed mails are deleted from it (their content lives on in the app).
 *
 * Speaks the few IMAP commands it needs over a plain socket, so no PHP extension is required
 * ("imap" is no longer part of PHP since 8.4).
 */
class Mailbox
{
    private const TIMEOUT = 20;

    /** @var resource|null */
    private $stream = null;

    private int $tag = 0;

    public static function isConfigured(): bool
    {
        return MailIntake::domain() !== null && filled(config('services.inbound_mail.username')) && filled(config('services.inbound_mail.password'));
    }

    public static function isSupported(): bool
    {
        return in_array(config('services.inbound_mail.encryption'), ['ssl', 'tls'], true) ? extension_loaded('openssl') : true;
    }

    /**
     * Uses an already connected stream (tests talk to a scripted server this way).
     *
     * @param  resource  $stream
     */
    public function useStream($stream): void
    {
        $this->stream = $stream;
    }

    public function open(): void
    {
        $config = config('services.inbound_mail');

        if (! $this->stream) {
            $this->connect((string) $config['host'], (int) $config['port'], (string) $config['encryption'], (bool) $config['validate_cert']);
        }

        $greeting = $this->line();

        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Mailbox not reachable: unexpected greeting');
        }

        if ($config['encryption'] === 'tls' && ! $this->isEncrypted()) {
            $this->command('STARTTLS');

            if (! @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Mailbox not reachable: STARTTLS failed');
            }
        }

        $this->command('LOGIN '.$this->quote((string) $config['username']).' '.$this->quote((string) $config['password']), 'Mailbox login failed');
        $this->command('SELECT INBOX');
    }

    /**
     * Numbers of the mails in the mailbox (oldest first, at most $limit).
     *
     * @return list<int>
     */
    public function messages(int $limit): array
    {
        $numbers = [];

        foreach ($this->command('SEARCH ALL') as $response) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $response['line'], $match)) {
                $numbers = array_merge($numbers, array_map('intval', preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        sort($numbers);

        return array_slice($numbers, 0, $limit);
    }

    public function size(int $number): int
    {
        foreach ($this->command('FETCH '.$number.' (RFC822.SIZE)') as $response) {
            if (preg_match('/RFC822\.SIZE (\d+)/i', $response['line'], $match)) {
                return (int) $match[1];
            }
        }

        return 0;
    }

    public function raw(int $number): string
    {
        foreach ($this->command('FETCH '.$number.' (BODY.PEEK[])') as $response) {
            if ($response['literal'] !== null) {
                return $response['literal'];
            }
        }

        return '';
    }

    public function delete(int $number): void
    {
        $this->command('STORE '.$number.' +FLAGS.SILENT (\\Deleted)');
    }

    public function close(): void
    {
        if (! $this->stream) {
            return;
        }

        try {
            $this->command('EXPUNGE');
            $this->command('LOGOUT');
        } catch (RuntimeException) {
            // The server may close the connection right after LOGOUT.
        } finally {
            fclose($this->stream);
            $this->stream = null;
        }
    }

    /**
     * Logs in and counts the mails waiting – for the test button on the admin page.
     */
    public function check(): int
    {
        $this->open();

        try {
            return count($this->messages(PHP_INT_MAX));
        } finally {
            $this->command('LOGOUT');
            fclose($this->stream);
            $this->stream = null;
        }
    }

    private function connect(string $host, int $port, string $encryption, bool $validateCertificate): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $validateCertificate,
            'verify_peer_name' => $validateCertificate,
            'allow_self_signed' => ! $validateCertificate,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);
        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port;
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new RuntimeException('Mailbox not reachable: '.($errorMessage ?: 'connection failed'));
        }

        stream_set_timeout($stream, self::TIMEOUT);
        $this->stream = $stream;
    }

    private function isEncrypted(): bool
    {
        return (bool) (stream_get_meta_data($this->stream)['crypto'] ?? false);
    }

    /**
     * Sends a command and reads the untagged responses until the tagged answer.
     *
     * @return list<array{line: string, literal: ?string}>
     */
    private function command(string $command, string $failure = 'Mailbox command failed'): array
    {
        if (! $this->stream) {
            throw new RuntimeException('Mailbox is not open');
        }

        $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        fwrite($this->stream, $tag.' '.$command."\r\n");
        $responses = [];

        while (true) {
            $line = $this->line();

            if (str_starts_with($line, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK\b/i', $line)) {
                    throw new RuntimeException($failure.': '.mb_substr(trim(substr($line, strlen($tag) + 1)), 0, 200));
                }

                return $responses;
            }

            $literal = null;

            // "{123}" at the end: that many bytes of data follow, then the rest of the response.
            if (preg_match('/\{(\d+)\}$/', $line, $match)) {
                $literal = $this->bytes((int) $match[1]);
                $line .= ' '.$this->line();
            }

            $responses[] = ['line' => $line, 'literal' => $literal];
        }
    }

    private function line(): string
    {
        $line = fgets($this->stream);

        if ($line === false) {
            throw new RuntimeException('Mailbox connection closed');
        }

        return rtrim($line, "\r\n");
    }

    private function bytes(int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Mailbox connection closed');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
