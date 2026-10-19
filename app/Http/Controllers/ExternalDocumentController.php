<?php

namespace App\Http\Controllers;

use App\Models\ExternalLink;
use App\Models\PaperlessInstance;
use App\Support\ExternalLinks;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves linked documents: paperless files are fetched live through an instance the viewer may
 * use – their own or one shared with the household (nothing is stored) – and mail copies from
 * the app's storage.
 */
class ExternalDocumentController extends Controller
{
    public function paperless(Request $request, PaperlessInstance $instance, int $document, string $variant): Response
    {
        $client = PaperlessClient::for($instance, $request->user());

        // Being allowed to use the instance is not enough: the document itself must be linked
        // into the viewer's household, otherwise any document id on a shared instance would be
        // readable (IDOR).
        abort_unless(ExternalLink::query()
            ->where('paperless_instance_id', $instance->id)
            ->where('paperless_document_id', $document)
            ->where('household_id', $request->user()->currentHousehold()?->id)
            ->exists(), 404);

        return $this->stream($client, $document, $variant);
    }

    /**
     * Serves any document of an instance the viewer may use – the document archive shows the whole
     * paperless library, not only linked documents. Access is bound to the instance itself
     * (own or shared with the household), which PaperlessClient::for already enforces.
     */
    public function paperlessArchive(Request $request, PaperlessInstance $instance, int $document, string $variant): Response
    {
        return $this->stream(PaperlessClient::for($instance, $request->user()), $document, $variant);
    }

    private function stream(PaperlessClient $client, int $document, string $variant): Response
    {
        try {
            $response = $client->file($document, $variant);
        } catch (Throwable $exception) {
            report($exception);
            abort(502, __('paperless is not reachable.'));
        }

        $type = $response->header('Content-Type') ?: 'application/octet-stream';

        // Only show what a browser can display safely inline; everything else is downloaded.
        $inline = $variant !== 'download' && (str_starts_with($type, 'image/') || str_starts_with($type, 'application/pdf'));
        $disposition = $response->header('Content-Disposition') ?: 'attachment';

        return response($response->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => $inline ? 'inline' : $disposition,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function file(Request $request, ExternalLink $link): StreamedResponse
    {
        abort_unless($link->kind === ExternalLink::FILE && $link->path && ExternalLinks::canSee($request->user(), $link) && Storage::disk(ExternalLink::DISK)->exists($link->path), 404);

        return Storage::disk(ExternalLink::DISK)->download($link->path, $link->title, [
            'Content-Type' => $link->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
