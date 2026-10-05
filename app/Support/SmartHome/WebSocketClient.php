<?php

namespace App\Support\SmartHome;

use App\Support\Vpn;
use RuntimeException;

/**
 * A small WebSocket client (RFC 6455, text messages) for the Home Assistant event stream – plain
 * or TLS, directly or through the SOCKS port of the VPN for addresses in the home network.
 */
class WebSocketClient
{
    /** @var resource */
    private $stream;

    private string $buffer = '';

    /**
     * @param  resource  $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * A client on a stream that already finished the handshake (used by the tests).
     *
     * @param  resource  $stream
     */
    public static function onStream($stream): self
    {
        return new self($stream);
    }

    /**
     * Opens ws(s)://… for an http(s) base address of Home Assistant (path /api/websocket).
     */
    public static function connect(string $baseUrl, int $timeout = 10): self
    {
        $parts = parse_url(rtrim($baseUrl, '/'));
        $secure = ($parts['scheme'] ?? 'http') === 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? ($secure ? 443 : 80));
        $path = rtrim((string) ($parts['path'] ?? ''), '/').'/api/websocket';

        if ($host === '') {
            throw new RuntimeException('No host in '.$baseUrl);
        }

        $proxy = Vpn::proxyFor($baseUrl);
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $target = $proxy ? 'tcp://'.parse_url($proxy, PHP_URL_HOST).':'.parse_url($proxy, PHP_URL_PORT) : 'tcp://'.$host.':'.$port;
        $stream = @stream_socket_client($target, $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new RuntimeException("Cannot connect to {$host}:{$port}: {$error}");
        }

        stream_set_timeout($stream, $timeout);

        if ($proxy) {
            self::socksConnect($stream, $host, $port);
        }

        if ($secure && ! @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException("TLS handshake with {$host} failed.");
        }

        $client = new self($stream);
        $client->handshake($host.(in_array($port, [80, 443], true) ? '' : ':'.$port), $path);

        return $client;
    }

    /**
     * SOCKS5 without authentication (wireproxy), CONNECT by host name.
     *
     * @param  resource  $stream
     */
    private static function socksConnect($stream, string $host, int $port): void
    {
        fwrite($stream, "\x05\x01\x00");

        if (fread($stream, 2) !== "\x05\x00") {
            throw new RuntimeException('The VPN proxy refused the connection.');
        }

        fwrite($stream, "\x05\x01\x00\x03".chr(strlen($host)).$host.pack('n', $port));
        $reply = fread($stream, 4);

        if (strlen((string) $reply) < 4 || $reply[1] !== "\x00") {
            throw new RuntimeException('The VPN could not reach '.$host.'.');
        }

        // Skip the bound address in the reply (IPv4, domain or IPv6) and the port.
        $length = match ($reply[3]) {
            "\x01" => 4,
            "\x04" => 16,
            default => ord((string) fread($stream, 1)),
        };
        fread($stream, $length + 2);
    }

    private function handshake(string $host, string $path): void
    {
        $key = base64_encode(random_bytes(16));
        fwrite($this->stream, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n");

        $response = '';

        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->stream, 1024);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('No answer to the WebSocket handshake.');
            }

            $response .= $chunk;
        }

        [$head, $this->buffer] = explode("\r\n\r\n", $response, 2);
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if (! preg_match('#^HTTP/1\.1 101#', $head) || ! str_contains(strtolower($head), strtolower('sec-websocket-accept: '.$accept))) {
            throw new RuntimeException('Home Assistant refused the WebSocket connection ('.strtok($head, "\r\n").').');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function sendJson(array $message): void
    {
        $this->sendFrame(0x1, (string) json_encode($message));
    }

    /**
     * The next message as array, null when nothing arrived within the timeout. Answers pings.
     *
     * @return array<string, mixed>|null
     */
    public function receiveJson(): ?array
    {
        $payload = '';

        while (true) {
            $frame = $this->readFrame();

            if ($frame === null) {
                return null;
            }

            [$final, $opcode, $data] = $frame;

            if ($opcode === 0x9) {
                $this->sendFrame(0xA, $data);

                continue;
            }

            if ($opcode === 0x8) {
                throw new RuntimeException('Home Assistant closed the connection.');
            }

            if ($opcode === 0xA) {
                continue;
            }

            $payload .= $data;

            if ($final) {
                $decoded = json_decode($payload, true);

                return is_array($decoded) ? $decoded : null;
            }
        }
    }

    /**
     * Whether a complete frame header is already buffered (stream_select cannot see it).
     */
    public function hasPending(): bool
    {
        return strlen($this->buffer) >= 2;
    }

    public function ping(): void
    {
        $this->sendFrame(0x9, 'ping');
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, $this->frame(0x8, ''));
            fclose($this->stream);
        }
    }

    private function sendFrame(int $opcode, string $data): void
    {
        if (@fwrite($this->stream, $this->frame($opcode, $data)) === false) {
            throw new RuntimeException('The connection to Home Assistant broke off.');
        }
    }

    /**
     * A masked client frame.
     */
    private function frame(int $opcode, string $data): string
    {
        $length = strlen($data);
        $head = chr(0x80 | $opcode);
        $head .= match (true) {
            $length < 126 => chr(0x80 | $length),
            $length < 65536 => chr(0x80 | 126).pack('n', $length),
            default => chr(0x80 | 127).pack('J', $length),
        };
        $mask = random_bytes(4);

        return $head.$mask.($data ^ str_repeat($mask, intdiv($length, 4) + 1));
    }

    /**
     * @return array{0: bool, 1: int, 2: string}|null
     */
    private function readFrame(): ?array
    {
        if (! $this->fill(2)) {
            return null;
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            $this->fill(4, true);
            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            $this->fill(10, true);
            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        $masked = ($second & 0x80) !== 0;
        $maskOffset = $offset;
        $offset += $masked ? 4 : 0;
        $this->fill($offset + $length, true);
        $data = substr($this->buffer, $offset, $length);

        if ($masked) {
            $data ^= str_repeat(substr($this->buffer, $maskOffset, 4), intdiv($length, 4) + 1);
            $data = substr($data, 0, $length);
        }

        $this->buffer = substr($this->buffer, $offset + $length);

        return [($first & 0x80) !== 0, $first & 0x0F, $data];
    }

    /**
     * Reads until the buffer holds $bytes; false on timeout before the first byte of a frame.
     */
    private function fill(int $bytes, bool $mustComplete = false): bool
    {
        $waitingSince = null;

        while (strlen($this->buffer) < $bytes) {
            $chunk = fread($this->stream, max(8192, $bytes - strlen($this->buffer)));

            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    throw new RuntimeException('The connection to Home Assistant broke off.');
                }

                if ($mustComplete || $this->buffer !== '') {
                    // The rest of a frame is on its way: wait briefly (never spin), give up after 30 s.
                    $waitingSince ??= microtime(true);

                    if (microtime(true) - $waitingSince > 30) {
                        throw new RuntimeException('The connection to Home Assistant broke off.');
                    }

                    usleep(20000);

                    continue;
                }

                return false;
            }

            $this->buffer .= $chunk;
        }

        return true;
    }
}
