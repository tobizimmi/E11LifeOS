<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\V1\DriveGoogleController;
use App\Models\NetworkDrive;
use App\Models\User;
use App\Support\Drives\GoogleDriveClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a member's Google Drive via the Google sign-in (OAuth) and keeps the tokens encrypted.
 */
class GoogleDriveController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        abort_unless(GoogleDriveClient::isConfigured(), 404);
        $state = Str::random(40);
        $request->session()->put('google_drive_state', $state);

        return redirect()->away(GoogleDriveClient::authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        // From the app: the state was handed out by the API and belongs to one member.
        $appUserId = Cache::pull(DriveGoogleController::STATE_PREFIX.(string) $request->query('state', ''));

        if ($appUserId) {
            $user = User::find($appUserId) ?? abort(403);
            $this->store($user, $request, false);

            return redirect()->away('e11lifeos://drives');
        }

        $user = $request->user() ?? abort(403);
        $expected = (string) $request->session()->pull('google_drive_state', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            abort(403);
        }

        return $this->store($user, $request, true);
    }

    private function store(User $user, Request $request, bool $web): RedirectResponse
    {
        if (blank($request->query('code'))) {
            return $web ? redirect()->route('drives.index')->with('error', __('Google Drive was not connected.')) : redirect()->away('e11lifeos://drives');
        }

        try {
            $token = GoogleDriveClient::exchangeCode((string) $request->query('code'));
        } catch (Throwable $exception) {
            return $web ? redirect()->route('drives.index')->with('error', $exception->getMessage()) : redirect()->away('e11lifeos://drives');
        }

        $drive = NetworkDrive::firstOrNew(['user_id' => $user->id, 'type' => 'google']);
        $previous = $drive->token ?? [];
        $drive->fill([
            'name' => $drive->name ?: 'Google Drive',
            'url' => 'https://drive.google.com/',
            'token' => ['refresh_token' => $token['refresh_token'] ?? ($previous['refresh_token'] ?? null)] + $token,
            'last_error' => null,
        ])->save();

        return redirect()->route('drives.index', ['drive' => $drive->id]);
    }
}
