<?php

namespace App\Support\Mail;

use Throwable;

/**
 * A received e-mail split into headers, text, HTML and attachments (nested multipart, base64,
 * quoted-printable and other charsets). Small on purpose: only what the mail intake needs.
 */
class MimeMessage
{
    private const MAX_DEPTH = 8;

    /**
     * A single MIME part larger than this is skipped unparsed: decoded it would exceed the 20 MB
     * attachment limit anyway, and copying it while parsing would blow the memory limit.
     */
    private const MAX_PART_BYTES = 30 * 1024 * 1024;

    /**
     * @param  array<string, string>  $headers  lower-case names, last value wins
     * @param  list<string>  $recipients  addresses from To, Cc, Delivered-To and X-Original-To
     * @param  list<array{name: string, mime: string, content: string}>  $attachments
     */
    public function __construct(
        public array $headers,
        public string $subject,
        public string $from,
        public string $fromName,
        public array $recipients,
        public string $text,
        public string $html,
        public array $attachments,
    ) {}

    public static function parse(string $raw): self
    {
        // Decoding a mail with a large (up to 20 MB) attachment copies the part several times, which
        // overruns PHP's default 128 MB limit. Give the parse enough headroom (raising only upward).
        self::ensureMemoryHeadroom();

        [$headers, $body, $all] = self::split($raw);
        $parts = ['text' => '', 'html' => '', 'attachments' => []];
        self::walk($headers, $body, $parts);
        [$from, $fromName] = self::address(self::decodeHeader($headers['from'] ?? ''));

        $recipients = [];

        foreach (['to', 'cc', 'delivered-to', 'x-original-to', 'envelope-to'] as $name) {
            foreach ($all[$name] ?? [] as $value) {
                preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches);
                array_push($recipients, ...array_map('strtolower', $matches[0]));
            }
        }

        return new self(
            $headers,
            self::decodeHeader($headers['subject'] ?? ''),
            $from,
            $fromName,
            array_values(array_unique($recipients)),
            $parts['text'],
            $parts['html'],
            $parts['attachments'],
        );
    }

    /**
     * Raises PHP's memory limit to at least 512 MB for the parse (only upward, never lowering an
     * already higher or unlimited limit). A platform that forbids the change is left as it is.
     */
    private static function ensureMemoryHeadroom(): void
    {
        $target = 512 * 1024 * 1024;
        $current = self::bytes((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < $target) {
            @ini_set('memory_limit', (string) $target);
        }
    }

    /**
     * Turns a php.ini size ("128M", "1G", "-1") into bytes; -1 means unlimited.
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * The readable text: the plain part, otherwise the HTML part as text.
     */
    public function plainText(): string
    {
        return trim($this->text) !== '' ? trim($this->text) : self::htmlToText($this->html);
    }

    /**
     * @return array{0: array<string, string>, 1: string, 2: array<string, list<string>>}
     */
    private static function split(string $part): array
    {
        $part = str_replace("\r\n", "\n", $part);
        [$head, $body] = array_pad(explode("\n\n", $part, 2), 2, '');
        $headers = [];
        $all = [];

        foreach (preg_split('/\n(?![ \t])/', $head) ?: [] as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim((string) preg_replace('/\n[ \t]+/', ' ', $value));
                $headers[$name] = $value;
                $all[$name][] = $value;
            }
        }

        return [$headers, $body, $all];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array{text: string, html: string, attachments: list<array{name: string, mime: string, content: string}>}  $parts
     */
    private static function walk(array $headers, string $body, array &$parts, int $depth = 0): void
    {
        $contentType = $headers['content-type'] ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (str_starts_with($type, 'multipart/')) {
            if ($depth < self::MAX_DEPTH && preg_match('/boundary="?([^";]+)"?/i', $contentType, $match)) {
                foreach (array_slice(explode('--'.$match[1], $body), 1) as $part) {
                    if (str_starts_with($part, '--')) {
                        break;
                    }

                    // A part bigger than the limit would be dropped as an oversized attachment later;
                    // skip it here so decoding it never blows the memory limit.
                    if (strlen($part) > self::MAX_PART_BYTES) {
                        continue;
                    }

                    [$partHeaders, $partBody] = self::split(ltrim($part, "\n"));
                    self::walk($partHeaders, $partBody, $parts, $depth + 1);
                }
            }

            return;
        }

        $content = match (strtolower($headers['content-transfer-encoding'] ?? '')) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $disposition = strtolower($headers['content-disposition'] ?? '');
        $name = self::parameter($headers['content-disposition'] ?? '', 'filename') ?? self::parameter($contentType, 'name');

        if ($name !== null || str_starts_with($disposition, 'attachment') || ! in_array($type, ['text/plain', 'text/html'], true)) {
            if ($type !== 'message/delivery-status') {
                $parts['attachments'][] = ['name' => self::safeName($name ?? 'anhang'), 'mime' => $type, 'content' => $content];
            }

            return;
        }

        if (preg_match('/charset="?([\w-]+)"?/i', $contentType, $charset) && ! in_array(strtolower($charset[1]), ['utf-8', 'us-ascii'], true)) {
            try {
                $content = (string) mb_convert_encoding($content, 'UTF-8', $charset[1]);
            } catch (Throwable) {
                // Keep the bytes as they are.
            }
        }

        $key = $type === 'text/html' ? 'html' : 'text';

        if ($parts[$key] === '') {
            $parts[$key] = $content;
        }
    }

    private static function parameter(string $header, string $name): ?string
    {
        // RFC 2231 (filename*=UTF-8''...) first, then the plain form.
        if (preg_match('/'.$name.'\*=(?:"?)([^\'";]*)\'[^\']*\'([^";]+)/i', $header, $match)) {
            return rawurldecode($match[2]);
        }

        if (preg_match('/'.$name.'="([^"]*)"|'.$name.'=([^;\s]+)/i', $header, $match)) {
            return self::decodeHeader(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
        }

        return null;
    }

    private static function safeName(string $name): string
    {
        $name = trim((string) preg_replace('/[^\pL\pN ._()\-]+/u', '_', basename(str_replace('\\', '/', $name))), ' ._');

        return mb_substr($name !== '' ? $name : 'anhang', 0, 120);
    }

    public static function decodeHeader(string $value): string
    {
        // Unencoded 8-bit headers are taken as they are (UTF-8, otherwise Latin-1).
        if (! str_contains($value, '=?')) {
            return trim(mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'));
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded !== false ? $decoded : $value);
    }

    /**
     * "Anna Muster <anna@example.com>" → ["anna@example.com", "Anna Muster"].
     *
     * @return array{0: string, 1: string}
     */
    private static function address(string $value): array
    {
        if (preg_match('/^\s*"?([^"<]*)"?\s*<([^>]+)>/', $value, $match)) {
            return [strtolower(trim($match[2])), trim($match[1])];
        }

        return [strtolower(trim($value)), ''];
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', ' ', $html);
        $html = (string) preg_replace('~<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n"], $text));
    }
}
