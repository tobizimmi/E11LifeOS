<?php

namespace App\Support\Drives;

use App\Models\NetworkDrive;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Drive through the official Drive API (OAuth). Paths are folder names like on a network
 * drive; Google documents, sheets and slides are downloaded as PDF.
 */
class GoogleDriveClient implements DriveClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    private const API = 'https://www.googleapis.com/drive/v3/files';

    private const FOLDER = 'application/vnd.google-apps.folder';

    public function __construct(private NetworkDrive $drive) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.google_drive.client_id')) && filled(config('services.google_drive.client_secret'));
    }

    public static function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => route('drives.google.callback'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the code from the Google sign-in for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     */
    public static function exchangeCode(string $code): array
    {
        return self::tokenRequest(['code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => route('drives.google.callback')]);
    }

    public function list(string $path = ''): array
    {
        $path = WebDavClient::cleanPath($path);
        $folderId = $this->folderId($path);
        $entries = [];
        $pageToken = null;

        do {
            $response = $this->request()->get(self::API, array_filter([
                'q' => "'".$folderId."' in parents and trashed = false",
                'fields' => 'nextPageToken, files(id, name, mimeType, size, modifiedTime)',
                'orderBy' => 'folder, name',
                'pageSize' => 1000,
                'pageToken' => $pageToken,
            ]));
            $this->ensureOk($response);

            foreach ($response->json('files', []) as $file) {
                $isDir = $file['mimeType'] === self::FOLDER;
                $entries[] = [
                    'name' => (string) $file['name'],
                    'path' => ltrim($path.'/'.$file['name'], '/'),
                    'is_dir' => $isDir,
                    'size' => isset($file['size']) ? (int) $file['size'] : null,
                    'modified' => isset($file['modifiedTime']) ? Carbon::parse($file['modifiedTime']) : null,
                    'type' => $isDir ? null : (string) $file['mimeType'],
                ];
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        // Names with a slash cannot be reached by path.
        $entries = array_values(array_filter($entries, fn (array $entry) => ! str_contains($entry['name'], '/')));
        usort($entries, fn (array $a, array $b) => [$b['is_dir'], mb_strtolower($a['name'])] <=> [$a['is_dir'], mb_strtolower($b['name'])]);

        return $entries;
    }

    public function download(string $path): Response
    {
        $path = WebDavClient::cleanPath($path);
        $file = $this->find($this->folderId(self::parent($path)), basename($path), false);
        $native = str_starts_with($file['mimeType'], 'application/vnd.google-apps.');
        $response = $this->request()->timeout(300)->withOptions(['stream' => true])->get(
            self::API.'/'.$file['id'].($native ? '/export' : ''),
            $native ? ['mimeType' => 'application/pdf'] : ['alt' => 'media'],
        );
        $this->ensureOk($response);

        return $response;
    }

    public function upload(string $folder, string $name, $stream): void
    {
        $name = basename(str_replace('\\', '/', $name));
        $session = $this->request()->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream'])
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable', ['name' => $name, 'parents' => [$this->folderId(WebDavClient::cleanPath($folder))]]);
        $this->ensureOk($session);
        $location = (string) $session->header('Location');

        if (! str_starts_with($location, 'https://www.googleapis.com/')) {
            throw new RuntimeException(__('Google Drive did not accept the upload.'));
        }

        $response = $this->request()->timeout(900)->withBody(Utils::streamFor($stream), 'application/octet-stream')->put($location);
        $this->ensureOk($response);
    }

    private function folderId(string $path): string
    {
        $id = 'root';

        foreach (array_filter(explode('/', $path), fn (string $part) => $part !== '') as $name) {
            $id = $this->find($id, $name, true)['id'];
        }

        return $id;
    }

    /**
     * @return array{id: string, mimeType: string}
     */
    private function find(string $parentId, string $name, bool $folder): array
    {
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);
        $response = $this->request()->get(self::API, [
            'q' => "'".$parentId."' in parents and name = '".$escaped."' and trashed = false and mimeType ".($folder ? '=' : '!=')." '".self::FOLDER."'",
            'fields' => 'files(id, mimeType)',
            'pageSize' => 1,
        ]);
        $this->ensureOk($response);
        $file = $response->json('files.0');

        if (! is_array($file)) {
            throw new RuntimeException(__('Not found on Google Drive: :name', ['name' => $name]));
        }

        return ['id' => (string) $file['id'], 'mimeType' => (string) $file['mimeType']];
    }

    private static function parent(string $path): string
    {
        $parent = dirname($path);

        return $parent === '.' ? '' : $parent;
    }

    private function request(): PendingRequest
    {
        return Http::timeout(30)->withToken($this->accessToken());
    }

    /**
     * The saved access token, renewed with the refresh token shortly before it expires.
     */
    private function accessToken(): string
    {
        $token = $this->drive->token ?? [];

        if (filled($token['access_token'] ?? null) && ($token['expires_at'] ?? 0) > now()->addMinute()->getTimestamp()) {
            return (string) $token['access_token'];
        }

        if (blank($token['refresh_token'] ?? null)) {
            throw new RuntimeException(__('The connection to Google Drive has expired. Connect it again.'));
        }

        $fresh = self::tokenRequest(['refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token']);
        $this->drive->forceFill(['token' => ['refresh_token' => $fresh['refresh_token'] ?? $token['refresh_token']] + $fresh])->save();

        return $fresh['access_token'];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}
     */
    private static function tokenRequest(array $parameters): array
    {
        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', $parameters + [
            'client_id' => (string) config('services.google_drive.client_id'),
            'client_secret' => (string) config('services.google_drive.client_secret'),
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException(__('The connection to Google Drive has expired. Connect it again.'));
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600))->getTimestamp(),
        ];
    }

    private function ensureOk(Response $response): void
    {
        if ($response->status() === 401) {
            throw new RuntimeException(__('The connection to Google Drive has expired. Connect it again.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException(__('Google Drive answered with HTTP :status.', ['status' => $response->status()]));
        }
    }
}
