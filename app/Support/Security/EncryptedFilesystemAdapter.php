<?php

namespace App\Support\Security;

use Illuminate\Contracts\Encryption\Encrypter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

/**
 * Stores files encrypted with the application key (AES-256) on top of another adapter, so
 * documents, ID scans and receipts are unreadable on disk and in server backups without the key.
 * Files written before encryption was introduced (without the marker) are still read as they are.
 */
class EncryptedFilesystemAdapter implements FilesystemAdapter
{
    /** Prefix of every encrypted file. */
    public const MARKER = "E11ENC1\n";

    public function __construct(private FilesystemAdapter $inner, private Encrypter $encrypter) {}

    public static function isEncrypted(string $contents): bool
    {
        return str_starts_with($contents, self::MARKER);
    }

    public function encrypt(string $contents): string
    {
        return self::MARKER.$this->encrypter->encrypt($contents, false);
    }

    public function decrypt(string $contents): string
    {
        return self::isEncrypted($contents) ? $this->encrypter->decrypt(substr($contents, strlen(self::MARKER)), false) : $contents;
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $this->encrypt($contents), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        return $this->decrypt($this->inner->read($path));
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    /**
     * The content is encrypted, so the type comes from the file extension.
     */
    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, mimeType: (new ExtensionMimeTypeDetector)->detectMimeTypeFromPath($path) ?? 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->read($path)));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
