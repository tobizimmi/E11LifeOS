<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PaperlessInstance;
use App\Support\Paperless\PaperlessClient;
use App\Support\Paperless\PaperlessException;
use App\Support\Paperless\PaperlessUploader;
use App\Support\Paperless\ScanPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * The document archive and scanning for the app. Documents stay in paperless: this reads and edits
 * them through an instance the member may use (own or shared with the household).
 */
class DocumentsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'tag' => ['nullable', 'integer'],
            'correspondent' => ['nullable', 'integer'],
            'document_type' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $instances = PaperlessInstance::query()->availableTo($this->user())->orderBy('name')->get();
        $instance = $this->instance($data['instance'] ?? null);
        $payload = [
            'instances' => $instances->map(fn (PaperlessInstance $i) => ['id' => $i->id, 'name' => $i->name, 'shared' => $i->shared])->values()->all(),
            'instance' => $instance?->id,
            'tags' => [],
            'correspondents' => [],
            'document_types' => [],
            'documents' => ['count' => 0, 'page' => 1, 'pages' => 1, 'results' => []],
            'error' => null,
        ];

        if ($instance === null) {
            return $this->ok($payload);
        }

        try {
            $client = PaperlessClient::for($instance, $this->user());
            $payload['tags'] = $this->named($client->tags());
            $payload['correspondents'] = $this->named($client->correspondents());
            $payload['document_types'] = $this->named($client->documentTypes());
            $payload['documents'] = $client->documents([
                'query' => $data['q'] ?? null,
                'tag' => $data['tag'] ?? null,
                'correspondent' => $data['correspondent'] ?? null,
                'document_type' => $data['document_type'] ?? null,
            ], (int) ($data['page'] ?? 1));
        } catch (PaperlessException $exception) {
            $payload['error'] = $exception->getMessage();
        }

        return $this->ok($payload);
    }

    public function show(Request $request, int $document): JsonResponse
    {
        $client = $this->client($request);

        return $this->guard(fn () => $this->ok($client->document($document)));
    }

    public function update(Request $request, int $document): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'created' => ['nullable', 'date'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['integer'],
            'correspondent' => ['nullable', 'integer'],
            'document_type' => ['nullable', 'integer'],
        ]);
        $client = $this->client($request);

        return $this->guard(function () use ($client, $document, $data) {
            $client->update($document, [
                'title' => trim($data['title']),
                'tags' => array_values(array_unique(array_map('intval', $data['tags'] ?? []))),
                'correspondent' => $data['correspondent'] ?? null,
                'document_type' => $data['document_type'] ?? null,
                'created' => ($data['created'] ?? null) ?: null,
            ]);

            return $this->ok($client->document($document));
        });
    }

    public function addNote(Request $request, int $document): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $client = $this->client($request);

        return $this->guard(function () use ($client, $document, $data) {
            $client->addNote($document, trim($data['note']));

            return $this->ok($client->document($document), 201);
        });
    }

    public function destroy(Request $request, int $document): JsonResponse
    {
        $client = $this->client($request);

        return $this->guard(function () use ($client, $document) {
            $client->deleteDocument($document);

            return $this->ok();
        });
    }

    /**
     * Thumbnail, preview or the original – streamed through, nothing is stored on the server.
     */
    public function file(Request $request, int $document, string $variant): Response|JsonResponse
    {
        $client = $this->client($request);

        try {
            $response = $client->file($document, $variant);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => __('paperless is not reachable.')], 502);
        }

        $type = $response->header('Content-Type') ?: 'application/octet-stream';
        $inline = $variant !== 'download' && (str_starts_with($type, 'image/') || str_starts_with($type, 'application/pdf'));

        return response($response->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => $inline ? 'inline' : ($response->header('Content-Disposition') ?: 'attachment'),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Turns photographed pages into one PDF and sends it to paperless (or queues it when paperless
     * is not reachable, like the web scanner).
     */
    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:150'],
            'pages' => ['required', 'array', 'min:1', 'max:'.ScanPdf::MAX_PAGES],
            'pages.*' => ['file', 'image', 'max:12288'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['integer'],
            'correspondent' => ['nullable', 'integer'],
            'document_type' => ['nullable', 'integer'],
        ]);

        $instance = $this->instance($data['instance'] ?? null) ?? abort(404, __('No paperless connection set up.'));
        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));

        $uris = collect($request->file('pages'))->map(fn ($file) => 'data:'.$file->getMimeType().';base64,'.base64_encode((string) file_get_contents($file->getRealPath())))->all();

        try {
            $pdf = ScanPdf::fromImages($uris);
        } catch (\InvalidArgumentException) {
            return response()->json(['success' => false, 'message' => __('The scan could not be processed. Please try again.')], 422);
        }

        $title = trim((string) ($data['title'] ?? '')) !== '' ? trim($data['title']) : __('Scan :date', ['date' => now()->format('d.m.Y H:i')]);
        $queued = app(PaperlessUploader::class)->store($instance, $this->user(), $household->id, $pdf, (Str::slug($title) ?: 'scan').'.pdf', $title, array_map('intval', $data['tags'] ?? []), $data['correspondent'] ?? null, $data['document_type'] ?? null);

        return $this->ok(['queued' => $queued], 201);
    }

    private function instance(?int $id): ?PaperlessInstance
    {
        $query = PaperlessInstance::query()->availableTo($this->user());

        return $id !== null ? $query->find($id) : PaperlessInstance::defaultFor($this->user());
    }

    private function client(Request $request): PaperlessClient
    {
        $id = $request->query('instance');
        $instance = $this->instance(is_numeric($id) ? (int) $id : null);

        return PaperlessClient::for($instance ?? abort(404, __('No paperless connection set up.')), $this->user());
    }

    /**
     * @param  \Closure(): JsonResponse  $call
     */
    private function guard(\Closure $call): JsonResponse
    {
        try {
            return $call();
        } catch (PaperlessException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 502);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => __('paperless is not reachable.')], 502);
        }
    }

    /**
     * @param  array<int, string>  $items
     * @return list<array{id: int, name: string}>
     */
    private function named(array $items): array
    {
        return collect($items)->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all();
    }
}
