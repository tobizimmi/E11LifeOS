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

    /**
     * Opens the connection and logs in, then selects the INBOX. The catch-all intake passes no
     * config and uses the server's own mailbox; a personal mail account passes its own settings.
     *
     * @param  array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string}|null  $config
     */
    public function open(?array $config = null, bool $select = true): void
    {
        $config ??= config('services.inbound_mail');

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

        if ($select) {
            $this->command('SELECT INBOX');
        }
    }

    /**
     * Closes the connection without expunging – a client must never delete the member's server mail.
     */
    public function logout(): void
    {
        if (! $this->stream) {
            return;
        }

        try {
            $this->command('LOGOUT');
        } catch (RuntimeException) {
            // The server may close the connection right after LOGOUT.
        } finally {
            fclose($this->stream);
            $this->stream = null;
        }
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

    /**
     * The selectable mailbox folders on the server (skips \Noselect/\NonExistent containers).
     *
     * @return list<string>
     */
    public function listFolders(): array
    {
        $folders = [];

        foreach ($this->command('LIST "" "*"') as $response) {
            if (! preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL|\S+) (.+)$/i', $response['line'], $match)) {
                continue;
            }

            $flags = strtolower($match[1]);

            if (str_contains($flags, '\\noselect') || str_contains($flags, '\\nonexistent')) {
                continue;
            }

            $name = trim($match[2]);

            if (str_starts_with($name, '"') && str_ends_with($name, '"')) {
                $name = stripcslashes(substr($name, 1, -1));
            }

            if ($name !== '') {
                $folders[] = $name;
            }
        }

        return $folders;
    }

    /**
     * Selects a folder and returns its UIDVALIDITY and message count. A changed UIDVALIDITY means
     * the server reassigned UIDs, so a client must drop what it cached for that folder and resync.
     *
     * @return array{uidvalidity: int, exists: int}
     */
    public function selectFolder(string $folder = 'INBOX'): array
    {
        $uidValidity = 0;
        $exists = 0;

        foreach ($this->command('SELECT '.$this->quote($folder)) as $response) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $response['line'], $match)) {
                $uidValidity = (int) $match[1];
            }

            if (preg_match('/^\* (\d+) EXISTS/i', $response['line'], $match)) {
                $exists = (int) $match[1];
            }
        }

        return ['uidvalidity' => $uidValidity, 'exists' => $exists];
    }

    /**
     * The UIDs of all mails in the selected folder (ascending).
     *
     * @return list<int>
     */
    public function uidSearchAll(): array
    {
        $uids = [];

        foreach ($this->command('UID SEARCH ALL') as $response) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $response['line'], $match)) {
                $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY) ?: []));
            }
        }

        sort($uids);

        return $uids;
    }

    /**
     * Fetches one mail by UID: whether it is seen on the server, its size and the raw RFC822 source.
     *
     * @return array{seen: bool, size: int, raw: string}
     */
    public function uidFetch(int $uid): array
    {
        $seen = false;
        $size = 0;
        $raw = '';

        foreach ($this->command('UID FETCH '.$uid.' (FLAGS RFC822.SIZE BODY.PEEK[])') as $response) {
            if (preg_match('/FLAGS \(([^)]*)\)/i', $response['line'], $match) && stripos($match[1], '\\Seen') !== false) {
                $seen = true;
            }

            if (preg_match('/RFC822\.SIZE (\d+)/i', $response['line'], $match)) {
                $size = (int) $match[1];
            }

            if ($response['literal'] !== null) {
                $raw = $response['literal'];
            }
        }

        return ['seen' => $seen, 'size' => $size, 'raw' => $raw];
    }

    /**
     * Marks a mail as read on the server (so the state matches what the member saw in the tool).
     */
    public function uidMarkSeen(int $uid): void
    {
        $this->command('UID STORE '.$uid.' +FLAGS.SILENT (\\Seen)');
    }

    /**
     * Adds or removes an IMAP flag (e.g. \Seen, \Flagged) on a mail by its UID.
     */
    public function uidStore(int $uid, string $flag, bool $add): void
    {
        $this->command('UID STORE '.$uid.' '.($add ? '+' : '-').'FLAGS.SILENT ('.$flag.')');
    }

    /**
     * Moves a mail to another folder on the server. Uses UID MOVE (RFC 6851) and, on servers that
     * do not support it, falls back to copy + mark deleted + expunge that single UID.
     */
    public function uidMove(int $uid, string $folder): void
    {
        try {
            $this->command('UID MOVE '.$uid.' '.$this->quote($folder));
        } catch (RuntimeException) {
            $this->command('UID COPY '.$uid.' '.$this->quote($folder));
            $this->command('UID STORE '.$uid.' +FLAGS.SILENT (\\Deleted)');

            try {
                $this->command('UID EXPUNGE '.$uid);
            } catch (RuntimeException) {
                $this->command('EXPUNGE');
            }
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
