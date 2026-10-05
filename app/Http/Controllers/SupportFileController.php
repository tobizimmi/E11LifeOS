<?php

namespace App\Http\Controllers;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Support\Helpdesk\Tickets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An attachment of a support ticket: for administrators, and for the requester when it belongs
 * to a public reply.
 */
class SupportFileController extends Controller
{
    public function __invoke(Request $request, SupportMessage $message, int $index): StreamedResponse
    {
        $user = $request->user();
        $ticket = $message->ticket;
        abort_unless($user && ($user->isAdmin() || ($ticket->user_id === $user->id && $message->isPublic())), 404);

        $file = Tickets::file($message, $index);
        abort_if($file === null, 404);

        // Only images and PDFs are shown in the browser; everything else is downloaded.
        $inline = in_array($file['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true);

        return Storage::disk(SupportTicket::DISK)->response($file['path'], $file['name'], [
            'Content-Type' => $inline ? $file['mime'] : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }
}
