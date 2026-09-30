<?php

namespace App\Support\Paperless;

use App\Models\PaperlessInstance;
use App\Models\User;
use App\Support\Vpn;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * REST client for a user's paperless-ngx instance (token authentication). The documents stay
 * in paperless; the app only searches, links, previews, uploads and edits their metadata.
 */
class PaperlessClient
{
    /**
     * API version the app is written for; older instances are asked without a version.
     */
    private const API_VERSION = 5;

    public function __construct(private string $baseUrl, private string $token) {}

    /**
     * Client for an instance the user may use (own or shared with the household).
     */
    public static function for(PaperlessInstance $instance, User $user): self
    {
        abort_unless($instance->isAvailableTo($user), 404);

        return new self($instance->baseUrl(), (string) $instance->token);
    }

    /**
     * Normalises and checks a paperless address: http(s) only, no credentials, and never the
     * server itself (loopback, link-local and metadata addresses).
     */
    public static function normaliseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($ips as $ip) {
            if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.') || $ip === '::1' || $ip === '0.0.0.0') {
                return null;
            }
        }

        return in_array($host, ['localhost', 'metadata.google.internal'], true) ? null : preg_replace('#/api$#', '', $url);
    }

    public function documentUrl(int $id): string
    {
        return $this->baseUrl.'/documents/'.$id.'/details';
    }

    /**
     * Total number of documents – used to test the connection.
     */
    public function count(): int
    {
        return (int) $this->get('/api/documents/', ['page_size' => 1])->json('count');
    }

    /**
     * Full-text search, newest first.
     *
     * @return list<array{id: int, title: string, created: ?string, correspondent: ?int}>
     */
    public function search(string $query, int $limit = 12): array
    {
        $parameters = ['page_size' => $limit, 'ordering' => '-created', 'truncate_content' => 'true'];

        if (trim($query) !== '') {
            $parameters['query'] = trim($query);
        }

        return collect($this->get('/api/documents/', $parameters)->json('results') ?? [])
            ->map(fn (array $document) => [
                'id' => (int) $document['id'],
                'title' => (string) ($document['title'] ?? ''),
                'created' => isset($document['created']) ? substr((string) $document['created'], 0, 10) : null,
                'correspondent' => $document['correspondent'] ?? null,
            ])->values()->all();
    }

    /**
     * Documents that still carry an inbox tag (newly consumed, e.g. from a mail rule), newest first.
     *
     * @return list<array{id: int, title: string, created: ?string, tags: list<int>, content: string}>
     */
    public function inbox(int $limit = 25): array
    {
        return collect($this->get('/api/documents/', ['is_in_inbox' => 'true', 'page_size' => $limit, 'ordering' => '-added', 'truncate_content' => 'true'])->json('results') ?? [])
            ->map(fn (array $document) => [
                'id' => (int) $document['id'],
                'title' => (string) ($document['title'] ?? ''),
                'created' => isset($document['created']) ? substr((string) $document['created'], 0, 10) : null,
                'tags' => array_map('intval', (array) ($document['tags'] ?? [])),
                'content' => mb_substr(trim((string) ($document['content'] ?? '')), 0, 300),
            ])->values()->all();
    }

    /**
     * Ids of the tags paperless treats as inbox tags.
     *
     * @return list<int>
     */
    public function inboxTagIds(): array
    {
        return Cache::remember('paperless-inbox-tags:'.md5($this->baseUrl.$this->token), 600, fn () => collect($this->get('/api/tags/', ['is_inbox_tag' => 'true', 'page_size' => 100])->json('results') ?? [])
            ->filter(fn (array $tag) => (bool) ($tag['is_inbox_tag'] ?? true))
            ->map(fn (array $tag) => (int) $tag['id'])->values()->all());
    }

    /**
     * Removes the inbox tags from a document, so it leaves the inbox in paperless too.
     */
    public function removeFromInbox(int $id): void
    {
        $inboxTags = $this->inboxTagIds();
        $tags = array_values(array_diff($this->document($id)['tags'], $inboxTags));

        $this->update($id, ['tags' => $tags]);
    }

    /**
     * @return array{id: int, title: string, created: ?string, tags: list<int>, notes: list<array{note: string, created: ?string}>}
     */
    public function document(int $id): array
    {
        $document = $this->get('/api/documents/'.$id.'/')->json();

        return [
            'id' => (int) $document['id'],
            'title' => (string) ($document['title'] ?? ''),
            'created' => isset($document['created']) ? substr((string) $document['created'], 0, 10) : null,
            'tags' => array_map('intval', (array) ($document['tags'] ?? [])),
            'notes' => collect($document['notes'] ?? [])->map(fn ($note) => ['note' => (string) ($note['note'] ?? ''), 'created' => isset($note['created']) ? substr((string) $note['created'], 0, 10) : null])->all(),
        ];
    }

    /**
     * All tags (id => name), cached for ten minutes.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return Cache::remember('paperless-tags:'.md5($this->baseUrl.$this->token), 600, fn () => collect($this->get('/api/tags/', ['page_size' => 1000])->json('results') ?? [])
            ->mapWithKeys(fn (array $tag) => [(int) $tag['id'] => (string) $tag['name']])->sort()->all());
    }

    /**
     * Thumbnail, preview (PDF/image) or original file.
     */
    public function file(int $id, string $variant): Response
    {
        abort_unless(in_array($variant, ['thumb', 'preview', 'download'], true), 404);

        return $this->send(fn (PendingRequest $request) => $request->timeout(30)->get($this->baseUrl.'/api/documents/'.$id.'/'.$variant.'/'));
    }

    /**
     * Uploads a file for consumption; returns the task id.
     *
     * @param  list<int>  $tagIds
     */
    public function upload(UploadedFile $file, ?string $title = null, array $tagIds = []): string
    {
        $data = array_filter(['title' => $title]);

        foreach ($tagIds as $index => $tagId) {
            $data['tags['.$index.']'] = $tagId;
        }

        $response = $this->send(fn (PendingRequest $request) => $request->timeout(60)
            ->attach('document', (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post($this->baseUrl.'/api/documents/post_document/', $data));

        return trim((string) $response->body(), "\" \n");
    }

    /**
     * The document id once paperless has consumed an upload, null while it is still running,
     * false if it failed.
     */
    public function taskResult(string $taskId): int|false|null
    {
        $task = $this->get('/api/tasks/', ['task_id' => $taskId])->json('0');

        return match ($task['status'] ?? null) {
            'SUCCESS' => isset($task['related_document']) ? (int) $task['related_document'] : false,
            'FAILURE' => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data  e.g. ['title' => …, 'tags' => [1, 2]]
     */
    public function update(int $id, array $data): void
    {
        $this->send(fn (PendingRequest $request) => $request->patch($this->baseUrl.'/api/documents/'.$id.'/', $data));
    }

    public function addNote(int $id, string $note): void
    {
        $this->send(fn (PendingRequest $request) => $request->post($this->baseUrl.'/api/documents/'.$id.'/notes/', ['note' => $note]));
    }

    private function request(): PendingRequest
    {
        $accept = Cache::get($this->versionCacheKey()) === 'none' ? 'application/json' : 'application/json; version='.self::API_VERSION;

        return Http::withHeaders(['Authorization' => 'Token '.$this->token, 'Accept' => $accept])->timeout(10)->withoutRedirecting()
            ->withOptions(Vpn::httpOptions($this->baseUrl));
    }

    /**
     * Sends a request and throws on errors. Older paperless versions reject the API version the
     * app asks for (HTTP 406 "Invalid version"): then the request is repeated without a version,
     * which is remembered for this address.
     *
     * @param  \Closure(PendingRequest): Response  $call
     */
    private function send(\Closure $call): Response
    {
        $response = $call($this->request());

        if ($response->status() === 406 && str_contains($response->body(), 'version') && Cache::get($this->versionCacheKey()) !== 'none') {
            Cache::put($this->versionCacheKey(), 'none', now()->addDay());
            $response = $call($this->request());
        }

        return $response->throw();
    }

    private function versionCacheKey(): string
    {
        return 'paperless-api-version:'.md5($this->baseUrl);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        try {
            return $this->send(fn (PendingRequest $request) => $request->get($this->baseUrl.$path, $query));
        } catch (Throwable $exception) {
            throw new PaperlessException(__('paperless is not reachable: :message', ['message' => class_basename($exception).' '.str($exception->getMessage())->limit(120)]), 0, $exception);
        }
    }
}
