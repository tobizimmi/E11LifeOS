<?php

namespace App\Http\Controllers;

use App\Models\BookkeepingEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the receipt of a bookkeeping entry to its owner.
 */
class BookkeepingReceiptController extends Controller
{
    public function __invoke(Request $request, BookkeepingEntry $entry): StreamedResponse
    {
        abort_unless($entry->user_id === $request->user()->id && $entry->receipt_path && Storage::disk(BookkeepingEntry::RECEIPT_DISK)->exists($entry->receipt_path), 404);

        $inline = str_starts_with((string) $entry->receipt_mime, 'image/') || $entry->receipt_mime === 'application/pdf';

        return Storage::disk(BookkeepingEntry::RECEIPT_DISK)->response($entry->receipt_path, $entry->receipt_name, [
            'Content-Type' => $entry->receipt_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ], $inline ? 'inline' : 'attachment');
    }
}
