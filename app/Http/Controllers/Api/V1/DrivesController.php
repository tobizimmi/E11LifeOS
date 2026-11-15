<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NetworkDrive;
use App\Support\Drives\WebDavClient;
use App\Support\Security\VirusScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Network drives (NAS, Nextcloud, storage box over WebDAV; Google Drive is connected in the web)
 * for the app: browse, download and upload. Personal – drives belong to the member.
 */
class DrivesController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(NetworkDrive::where('user_id', $this->user()->id)->orderBy('name')->get()->map(fn (NetworkDrive $drive) => $this->driveData($drive))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $drive = NetworkDrive::create($this->attributes($request) + ['user_id' => $this->user()->id]);

        return $this->ok($this->driveData($drive), 201);
    }

    public function update(Request $request, int $drive): JsonResponse
    {
        $model = $this->find($drive);

        if ($model->isGoogle()) {
            $model->update($request->validate(['name' => ['required', 'string', 'max:100']]));
        } else {
            $model->update($this->attributes($request, $model));
        }

        return $this->ok($this->driveData($model->refresh()));
    }

    public function destroy(int $drive): JsonResponse
    {
        $this->find($drive)->delete();

        return $this->ok();
    }

    public function browse(Request $request, int $drive): JsonResponse
    {
        $model = $this->find($drive);
        $path = WebDavClient::cleanPath((string) $request->query('path', ''));

        try {
            $entries = $model->client()->list($path);
            $model->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();
        } catch (Throwable $exception) {
            report($exception);
            $model->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();

            return response()->json(['success' => false, 'message' => __('The drive is not reachable.')], 502);
        }

        return $this->ok([
            'path' => $path,
            'entries' => array_map(fn (array $e) => [
                'name' => $e['name'],
                'path' => $e['path'],
                'is_dir' => (bool) $e['is_dir'],
                'size' => $e['size'],
                'modified' => $e['modified']?->toIso8601String(),
                'type' => $e['type'],
            ], $entries),
        ]);
    }

    public function download(Request $request, int $drive): StreamedResponse|JsonResponse
    {
        $model = $this->find($drive);
        $path = (string) $request->query('path', '');

        try {
            $body = $model->client()->download($path)->toPsrResponse()->getBody();
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => __('The file is not available.')], 404);
        }

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 1024);
            }
        }, Str::ascii(basename(str_replace('\\', '/', $path)) ?: 'download') ?: 'download', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function upload(Request $request, int $drive): JsonResponse
    {
        $model = $this->find($drive);
        $request->validate(['file' => ['required', 'file', 'max:102400'], 'path' => ['nullable', 'string', 'max:1000']]);
        $file = $request->file('file');

        try {
            if (VirusScanner::isEnabled() && app(VirusScanner::class)->scanFile($file->getRealPath())['status'] === VirusScanner::INFECTED) {
                return response()->json(['success' => false, 'message' => __('The file was rejected by the virus scanner.')], 422);
            }

            $stream = fopen($file->getRealPath(), 'rb');
            $model->client()->upload(WebDavClient::cleanPath((string) $request->input('path', '')), $file->getClientOriginalName(), $stream);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => __('The upload failed.')], 502);
        }

        return $this->ok(null, 201);
    }

    private function find(int $id): NetworkDrive
    {
        return NetworkDrive::where('user_id', $this->user()->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request, ?NetworkDrive $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url:http,https', 'max:500', fn ($attribute, $value, $fail) => WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed: it is neither in a VPN network nor public.'))],
            'username' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        $attributes = ['name' => $data['name'], 'url' => $data['url'], 'username' => ($data['username'] ?? null) ?: null];

        // An empty password keeps the saved one.
        if (($data['password'] ?? '') !== '') {
            $attributes['password'] = $data['password'];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function driveData(NetworkDrive $drive): array
    {
        return [
            'id' => $drive->id,
            'name' => $drive->name,
            'url' => $drive->isGoogle() ? null : $drive->url,
            'username' => $drive->username,
            'google' => $drive->isGoogle(),
            'last_error' => $drive->last_error,
        ];
    }
}
