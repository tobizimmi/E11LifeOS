<?php

namespace App\Http\Controllers;

use App\Models\ExternalLink;
use App\Models\Trip;
use App\Models\TripParticipant;
use App\Support\Life\GroupTrip;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public pages of a group trip: the trip folder (link + password) and each participant's
 * personal page (own link, reply yes / maybe / no).
 */
class GroupTripController extends Controller
{
    public function folder(Request $request, string $token): View
    {
        $trip = $this->sharedTrip($token);

        if (! $this->isUnlocked($request, $trip)) {
            return view('trips.folder-locked', ['trip' => $trip, 'token' => $token]);
        }

        return view('trips.folder', $this->folderData($trip) + ['token' => $token, 'participant' => null]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $trip = $this->sharedTrip($token);

        if (! $trip->share_password || ! Hash::check((string) $request->input('password'), $trip->share_password)) {
            return back()->withErrors(['password' => __('The password is not correct.')]);
        }

        $request->session()->put('trip_folder.'.$trip->id, true);

        return redirect()->route('trips.folder', $token);
    }

    public function file(Request $request, string $token, int $file): StreamedResponse
    {
        $trip = $this->sharedTrip($token);
        abort_unless($this->isUnlocked($request, $trip), 403);

        return $this->download($trip, $file);
    }

    public function participant(string $token): View
    {
        $participant = $this->participantByToken($token);

        return view('trips.folder', $this->folderData($participant->trip) + ['token' => null, 'participant' => $participant, 'participantToken' => $token]);
    }

    public function participantFile(string $token, int $file): StreamedResponse
    {
        return $this->download($this->participantByToken($token)->trip, $file);
    }

    public function reply(Request $request, string $token): RedirectResponse
    {
        $participant = $this->participantByToken($token);
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'maybe', 'declined'])],
            'reply' => ['nullable', 'string', 'max:1000'],
        ]);

        $participant->update(['status' => $data['status'], 'reply' => trim((string) ($data['reply'] ?? '')) ?: null, 'replied_at' => now()]);

        return redirect()->route('trips.participant', $token)->with('status', __('Thank you, your reply has been saved.'));
    }

    private function sharedTrip(string $token): Trip
    {
        return Trip::query()->whereShareToken('share_token', $token, 'share_expires_at')->first() ?? abort(404);
    }

    private function participantByToken(string $token): TripParticipant
    {
        return TripParticipant::query()->whereShareToken('share_token', $token)->with('trip')->first() ?? abort(404);
    }

    private function isUnlocked(Request $request, Trip $trip): bool
    {
        return ! $trip->share_password || $request->session()->get('trip_folder.'.$trip->id) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function folderData(Trip $trip): array
    {
        $trip->load(['bookings', 'participants']);

        return [
            'trip' => $trip,
            'program' => GroupTrip::program($trip, $trip->share_references),
            'files' => GroupTrip::files($trip),
            'costs' => GroupTrip::costs($trip),
        ];
    }

    private function download(Trip $trip, int $file): StreamedResponse
    {
        $link = ExternalLink::where('linkable_type', 'trip')->where('linkable_id', $trip->id)->where('kind', ExternalLink::FILE)->find($file);
        abort_unless($link && $link->path && Storage::disk(ExternalLink::DISK)->exists($link->path), 404);

        return Storage::disk(ExternalLink::DISK)->download($link->path, $link->title, [
            'Content-Type' => $link->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
