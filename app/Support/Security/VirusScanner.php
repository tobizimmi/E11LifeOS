<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Virus scan of uploaded files with a ClamAV daemon (clamd) on the server, over its Unix socket or
 * TCP port with the INSTREAM command. Switched on and configured on the admin page.
 */
class VirusScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const UNAVAILABLE = 'unavailable';

    /** Harmless test string every virus scanner reports as "EICAR test file". */
    public const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    private const CHUNK = 65536;

    public static function isEnabled(): bool
    {
        return (bool) config('security.clamav.enabled') && filled(config('security.clamav.socket'));
    }

    /**
     * @return array{status: string, signature: ?string, message: ?string}
     */
    public function scanFile(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['status' => self::UNAVAILABLE, 'signature' => null, 'message' => __('The file could not be read.')];
        }

        try {
            return $this->scanStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{status: string, signature: ?string, message: ?string}
     */
    public function scanString(string $contents): array
    {
        $handle = fopen('php://temp', 'r+b');
        fwrite($handle, $contents);
        rewind($handle);

        try {
            return $this->scanStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Whether clamd answers; returns its version (e.g. "ClamAV 1.4.1/27400/…") or null.
     */
    public function version(): ?string
    {
        try {
            $socket = $this->connect();
            fwrite($socket, "zVERSION\0");
            $reply = trim((string) stream_get_contents($socket), "\0\n ");
            fclose($socket);

            return $reply !== '' ? $reply : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads clamd's answer to INSTREAM, e.g. "stream: OK" or "stream: Eicar-Signature FOUND".
     *
     * @return array{status: string, signature: ?string, message: ?string}
     */
    public static function parseReply(string $reply): array
    {
        $reply = trim($reply, "\0\n ");

        if (preg_match('/^(?:stream|\S+): OK$/', $reply)) {
            return ['status' => self::CLEAN, 'signature' => null, 'message' => null];
        }

        if (preg_match('/^(?:stream|\S+): (.+) FOUND$/', $reply, $match)) {
            return ['status' => self::INFECTED, 'signature' => $match[1], 'message' => null];
        }

        return ['status' => self::UNAVAILABLE, 'signature' => null, 'message' => $reply !== '' ? $reply : __('No answer from the virus scanner.')];
    }

    /**
     * @param  resource  $handle
     * @return array{status: string, signature: ?string, message: ?string}
     */
    private function scanStream($handle): array
    {
        try {
            $socket = $this->connect();
            fwrite($socket, "zINSTREAM\0");

            while (! feof($handle)) {
                $chunk = (string) fread($handle, self::CHUNK);

                if ($chunk === '') {
                    continue;
                }

                if (fwrite($socket, pack('N', strlen($chunk)).$chunk) === false) {
                    break;
                }
            }

            fwrite($socket, pack('N', 0));
            $reply = (string) stream_get_contents($socket);
            fclose($socket);

            return self::parseReply($reply);
        } catch (Throwable $exception) {
            $this->reportUnavailable($exception);

            return ['status' => self::UNAVAILABLE, 'signature' => null, 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        $target = (string) config('security.clamav.socket');
        $address = str_starts_with($target, 'tcp://') || str_starts_with($target, 'unix://') ? $target : 'unix://'.$target;
        $socket = @stream_socket_client($address, $code, $error, 5);

        if ($socket === false) {
            throw new \RuntimeException(__('The virus scanner is not reachable (:error).', ['error' => $error ?: $code]));
        }

        stream_set_timeout($socket, (int) config('security.clamav.timeout', 30));

        return $socket;
    }

    private function reportUnavailable(Throwable $exception): void
    {
        // At most one error report per hour, the upload page shows the problem anyway.
        if (Cache::add('clamav:unavailable-reported', true, now()->addHour())) {
            report($exception);
        }
    }
}
