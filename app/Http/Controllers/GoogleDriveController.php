<?php

namespace App\Http\Controllers;

use App\Models\NetworkDrive;
use App\Support\Drives\GoogleDriveClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $expected = (string) $request->session()->pull('google_drive_state', '');

        if ($expected === '' || ! hash_equals($expected, (string) $request->query('state', ''))) {
            abort(403);
        }

        if (blank($request->query('code'))) {
            return redirect()->route('drives.index')->with('error', __('Google Drive was not connected.'));
        }

        try {
            $token = GoogleDriveClient::exchangeCode((string) $request->query('code'));
        } catch (Throwable $exception) {
            return redirect()->route('drives.index')->with('error', $exception->getMessage());
        }

        $drive = NetworkDrive::firstOrNew(['user_id' => $request->user()->id, 'type' => 'google']);
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
