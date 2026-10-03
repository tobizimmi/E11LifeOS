<?php

namespace App\Http\Controllers;

use App\Models\JournalEntry;
use App\Support\Life\Journal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a photo of a journal entry, only to the member who wrote it.
 */
class JournalPhotoController extends Controller
{
    public function __invoke(Request $request, JournalEntry $entry, int $index): StreamedResponse
    {
        abort_unless($entry->user_id === $request->user()->id, 404);
        $path = ($entry->photos ?? [])[$index] ?? null;
        abort_unless($path && Storage::disk(Journal::DISK)->exists($path), 404);

        return Storage::disk(Journal::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=2592000']);
    }
}
