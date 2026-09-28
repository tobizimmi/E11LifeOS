<?php

namespace App\Support\Backup;

use App\Support\Vpn;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Uploads backups to a WebDAV folder (Hetzner Storage Box, Nextcloud, many NAS) and removes
 * old copies there.
 */
class WebDavTarget
{
    public function __construct(private string $url, private ?string $username, private ?string $password) {}

    public static function fromConfig(): ?self
    {
        $url = config('backup.remote_url');

        return filled($url) ? new self(rtrim((string) $url, '/').'/', config('backup.remote_username'), config('backup.remote_password')) : null;
    }

    public function upload(string $path, string $name): void
    {
        $this->request()->timeout(900)
            ->withBody(Utils::streamFor(fopen($path, 'rb')), 'application/octet-stream')
            ->put($this->url.rawurlencode($name))
            ->throw();
    }

    /**
     * Deletes the oldest backup copies beyond $keep; returns how many.
     */
    public function prune(int $keep): int
    {
        $response = $this->request()->withHeaders(['Depth' => '1'])->send('PROPFIND', $this->url)->throw();
        preg_match_all('#<(?:[a-z0-9]+:)?href>([^<]*e11lifeos-[^<]*\.zip\.enc)</(?:[a-z0-9]+:)?href>#i', $response->body(), $matches);
        $names = collect($matches[1])->map(fn (string $href) => rawurldecode(basename($href)))->unique()->sort()->values();
        $old = $names->slice(0, max(0, $names->count() - $keep));

        foreach ($old as $name) {
            $this->request()->delete($this->url.rawurlencode($name));
        }

        return $old->count();
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout(60)->withOptions(Vpn::httpOptions($this->url));

        return filled($this->username) ? $request->withBasicAuth((string) $this->username, (string) $this->password) : $request;
    }
}
