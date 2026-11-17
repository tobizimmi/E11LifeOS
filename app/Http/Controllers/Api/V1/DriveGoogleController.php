<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NetworkDrive;
use App\Support\Drives\GoogleDriveClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Connecting Google Drive from the app: the API hands out the Google sign-in address, the browser
 * returns to the web callback which recognises the member by the one-time state.
 */
class DriveGoogleController extends ApiController
{
    public const STATE_PREFIX = 'google_drive_app_state:';

    public function status(): JsonResponse
    {
        return $this->ok([
            'configured' => GoogleDriveClient::isConfigured(),
            'connected' => NetworkDrive::where('user_id', $this->user()->id)->where('type', 'google')->exists(),
        ]);
    }

    public function connect(): JsonResponse
    {
        abort_unless(GoogleDriveClient::isConfigured(), 404);
        $state = Str::random(40);
        Cache::put(self::STATE_PREFIX.$state, $this->user()->id, now()->addMinutes(10));

        return $this->ok(['url' => GoogleDriveClient::authorizationUrl($state)]);
    }
}
