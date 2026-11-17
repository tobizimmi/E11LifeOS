<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\TrustedPerson;
use App\Notifications\EmergencyAccessUpdate;
use App\Notifications\TrustedPersonInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/**
 * Trusted people of the emergency folder: invite by e-mail, allow or decline requests, send a new link.
 */
class EmergencyPeopleController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(TrustedPerson::where('user_id', $this->user()->id)->orderBy('name')->get()->map(fn (TrustedPerson $person): array => $this->data($person))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'wait_days' => ['required', 'integer', 'between:1,60'],
        ]);

        $person = new TrustedPerson(['user_id' => $this->user()->id, 'name' => $data['name'], 'email' => mb_strtolower($data['email']), 'wait_days' => (int) $data['wait_days']]);
        $this->invite($person);

        return $this->ok($this->data($person), 201);
    }

    public function resend(int $person): JsonResponse
    {
        $model = $this->find($person);
        $this->invite($model);

        return $this->ok($this->data($model));
    }

    public function allow(int $person): JsonResponse
    {
        $model = $this->find($person);
        $model->update(['requested_at' => $model->requested_at ?? now(), 'granted_at' => now()]);
        Notification::route('mail', $model->email)->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::GRANTED, $this->user()->name, $model->name));

        return $this->ok($this->data($model));
    }

    public function decline(int $person): JsonResponse
    {
        $model = $this->find($person);
        $wasOpen = $model->requested_at !== null;
        $model->update(['requested_at' => null, 'granted_at' => null]);

        if ($wasOpen) {
            Notification::route('mail', $model->email)->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::DECLINED, $this->user()->name, $model->name));
        }

        return $this->ok($this->data($model));
    }

    public function destroy(int $person): JsonResponse
    {
        $this->find($person)->delete();

        return $this->ok();
    }

    private function invite(TrustedPerson $person): void
    {
        $token = $person->issueToken();
        $person->invited_at = now();
        $person->save();

        Notification::route('mail', $person->email)->notify(new TrustedPersonInvitation($this->user()->name, $person->name, route('emergency.access', ['token' => $token]), $person->wait_days));
    }

    private function find(int $id): TrustedPerson
    {
        return TrustedPerson::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(TrustedPerson $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'wait_days' => $person->wait_days,
            'status' => $person->hasAccess() ? 'granted' : ($person->isWaiting() ? 'waiting' : 'invited'),
            'grants_on' => $person->isWaiting() ? $person->grantsOn()?->toIso8601String() : null,
            'last_viewed_at' => $person->last_viewed_at?->toIso8601String(),
        ];
    }
}
