<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Support\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Registers the browsers that get push notifications, and hands the service worker
 * the texts to show when a (payload-less) push arrives.
 */
class PushController extends Controller
{
    /**
     * Push services of Chrome/Android, Firefox, Safari/iOS and Edge. The server posts to
     * the endpoint, so arbitrary URLs must not be accepted.
     */
    private const ALLOWED_HOSTS = '/^(fcm\.googleapis\.com|android\.googleapis\.com|updates\.push\.services\.mozilla\.com|([a-z0-9-]+\.)*push\.apple\.com|([a-z0-9-]+\.)*notify\.windows\.com)$/i';

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000', function (string $attribute, string $value, $fail) {
                if (! preg_match(self::ALLOWED_HOSTS, (string) parse_url($value, PHP_URL_HOST))) {
                    $fail(__('This push service is not supported.'));
                }
            }],
            'keys.p256dh' => ['nullable', 'string', 'max:255'],
            'keys.auth' => ['nullable', 'string', 'max:255'],
        ]);

        // A device that was used by someone else before now belongs to the current user.
        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'] ?? null,
                'auth_token' => $data['keys']['auth'] ?? null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->noContent(201);
    }

    public function destroy(Request $request): Response
    {
        $endpoint = (string) $request->input('endpoint');

        $request->user()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        return response()->noContent();
    }

    /**
     * Called by the service worker on a push.
     */
    public function pending(Request $request, WebPush $webPush): JsonResponse
    {
        return response()->json([
            'messages' => WebPush::present($request->user(), $webPush->takePending($request->user())),
        ])->header('Cache-Control', 'no-store');
    }
}
