<?php

namespace App\Http\Controllers;

use App\Support\QuickCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Receives inbox entries and tasks that were captured while the device was offline
 * (queued in the browser, sent once it is online again).
 */
class OfflineCaptureController extends Controller
{
    /**
     * A page loaded from the offline cache has an outdated CSRF token; the queue asks for a fresh one.
     */
    public function token(): JsonResponse
    {
        return response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:inbox,task'],
            'text' => ['required', 'string', 'max:500'],
            'private' => ['sometimes', 'boolean'],
            'id' => ['required', 'string', 'max:64'],
        ]);

        $user = $request->user();
        $household = $user->currentHousehold();
        abort_unless($household, 403);

        // Sent twice (e.g. the answer got lost)? Only the first one counts.
        if (! Cache::add('offline-capture:'.$user->id.':'.$data['id'], true, now()->addDays(2))) {
            return response()->json(['created' => false]);
        }

        $item = $data['kind'] === 'task'
            ? QuickCapture::task($user, $household->id, $data['text'])
            : QuickCapture::inbox($user, $household->id, $data['text'], (bool) ($data['private'] ?? false));

        return response()->json(['created' => (bool) $item], $item ? 201 : 422);
    }
}
