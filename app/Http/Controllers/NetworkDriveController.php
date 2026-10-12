<?php

namespace App\Http\Controllers;

use App\Models\NetworkDrive;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Downloads a file from a member's network drive through the server (and the VPN).
 */
class NetworkDriveController extends Controller
{
    public function download(Request $request, NetworkDrive $drive): StreamedResponse
    {
        abort_unless($drive->user_id === $request->user()->id, 404);
        $path = (string) $request->query('path', '');

        try {
            $response = $drive->client()->download($path);
        } catch (Throwable $exception) {
            // The NAS's raw error (status, sometimes an internal hostname or path) stays in the
            // log; the member only sees a plain "not available" message.
            report($exception);
            abort(404, __('The file is not available.'));
        }

        $body = $response->toPsrResponse()->getBody();
        $name = basename(str_replace('\\', '/', $path)) ?: 'download';

        return response()->streamDownload(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 1024);
            }
        }, Str::ascii($name) ?: 'download', [
            // Always offered as a download: files from the drive are never rendered in the app.
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
