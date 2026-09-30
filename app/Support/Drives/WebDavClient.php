<?php

namespace App\Support\Drives;

use App\Support\Vpn;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal WebDAV client for browsing, downloading and uploading on a network drive. Paths are
 * relative to the drive's base address; ".." is refused. Home-network addresses go through the VPN.
 */
class WebDavClient implements DriveClient
{
    private string $baseUrl;

    public function __construct(string $url, private ?string $username = null, private ?string $password = null)
    {
        $this->baseUrl = rtrim($url, '/').'/';
    }

    /**
     * Only http(s) addresses that go through the VPN or point to a public host – never the server
     * itself or its own local network.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }

        if (Vpn::routes($url)) {
            return true;
        }

        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        return $ips !== [] && collect($ips)->every(fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    /**
     * Folders first, then files, each alphabetically.
     *
     * @return list<array{name: string, path: string, is_dir: bool, size: ?int, modified: ?Carbon, type: ?string}>
     */
    public function list(string $path = ''): array
    {
        $path = self::cleanPath($path);
        $response = $this->request()->withHeaders(['Depth' => '1', 'Content-Type' => 'application/xml'])
            ->withBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getcontentlength/><d:getlastmodified/><d:getcontenttype/></d:prop></d:propfind>', 'application/xml')
            ->send('PROPFIND', $this->url($path, true));
        $this->ensureOk($response);

        $document = new DOMDocument;

        if (! @$document->loadXML($response->body(), LIBXML_NONET)) {
            throw new RuntimeException(__('The drive sent an answer that is not WebDAV.'));
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $basePath = rtrim((string) parse_url($this->url($path, true), PHP_URL_PATH), '/');
        $entries = [];

        foreach ($xpath->query('//d:response') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $href = rawurldecode((string) $xpath->evaluate('string(d:href)', $node));
            $hrefPath = rtrim((string) (parse_url($href, PHP_URL_PATH) ?? $href), '/');

            // The folder itself is part of the answer.
            if ($hrefPath === '' || $hrefPath === rawurldecode($basePath)) {
                continue;
            }

            $name = basename($hrefPath);
            $isDir = (bool) $xpath->evaluate('count(.//d:resourcetype/d:collection)', $node);
            $size = (string) $xpath->evaluate('string(.//d:getcontentlength)', $node);
            $modified = (string) $xpath->evaluate('string(.//d:getlastmodified)', $node);

            $entries[] = [
                'name' => $name,
                'path' => ltrim($path.'/'.$name, '/'),
                'is_dir' => $isDir,
                'size' => $isDir || $size === '' ? null : (int) $size,
                'modified' => $modified !== '' ? rescue(fn () => Carbon::parse($modified), null, false) : null,
                'type' => ((string) $xpath->evaluate('string(.//d:getcontenttype)', $node)) ?: null,
            ];
        }

        usort($entries, fn (array $a, array $b) => [$b['is_dir'], mb_strtolower($a['name'])] <=> [$a['is_dir'], mb_strtolower($b['name'])]);

        return $entries;
    }

    public function download(string $path): Response
    {
        $response = $this->request()->timeout(300)->withOptions(['stream' => true])->get($this->url(self::cleanPath($path)));
        $this->ensureOk($response);

        return $response;
    }

    /**
     * @param  resource  $stream
     */
    public function upload(string $folder, string $name, $stream): void
    {
        $name = basename(str_replace('\\', '/', $name));
        $response = $this->request()->timeout(900)
            ->withBody(Utils::streamFor($stream), 'application/octet-stream')
            ->put($this->url(ltrim(self::cleanPath($folder).'/'.$name, '/')));
        $this->ensureOk($response);
    }

    /**
     * Throws on anything but an allowed address, so a request never leaves for a forbidden target.
     */
    private function request(): PendingRequest
    {
        if (! self::isAllowedUrl($this->baseUrl)) {
            throw new RuntimeException(__('This address is not allowed. Use an address in the home network that goes through the VPN, or a public address.'));
        }

        $request = Http::timeout(30)->withOptions(['allow_redirects' => false] + Vpn::httpOptions($this->baseUrl));

        return filled($this->username) ? $request->withBasicAuth((string) $this->username, (string) $this->password) : $request;
    }

    private function url(string $path, bool $folder = false): string
    {
        $encoded = implode('/', array_map('rawurlencode', array_filter(explode('/', $path), fn ($part) => $part !== '')));

        return $this->baseUrl.$encoded.($folder && $encoded !== '' ? '/' : '');
    }

    private function ensureOk(Response $response): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(__('The drive refused the login (HTTP :status). Check user name and password.', ['status' => $response->status()]));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('The drive answered with HTTP :status.', ['status' => $response->status()]));
        }
    }

    public static function cleanPath(string $path): string
    {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), fn ($part) => $part !== '' && $part !== '.'));

        if (in_array('..', $parts, true)) {
            throw new InvalidArgumentException(__('Invalid path.'));
        }

        return implode('/', $parts);
    }
}
