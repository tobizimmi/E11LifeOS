<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DevicePushToken;
use App\Models\MailAccount;
use App\Support\ApnsClient;
use App\Support\NotificationTopics;
use App\Support\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the member is notified about and how: per topic (push and bell, only the bell, off), quiet hours,
 * bundling, the mail accounts that announce new mail, and the devices of the native app.
 */
class NotificationSettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok($this->data());
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user();
        $data = $request->validate([
            'topics' => ['sometimes', 'array'],
            'topics.*' => ['in:'.implode(',', NotificationTopics::LEVELS)],
            'quiet_from' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'quiet_until' => ['sometimes', 'nullable', 'integer', 'between:0,23'],
            'bundle' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['topics'])) {
            NotificationTopics::save($user, $data['topics']);
        }

        if (array_key_exists('quiet_from', $data) || array_key_exists('quiet_until', $data)) {
            $from = $data['quiet_from'] ?? null;
            $until = $data['quiet_until'] ?? null;
            $off = $from === null || $until === null;
            $user->forceFill(['push_quiet_from' => $off ? null : (int) $from, 'push_quiet_until' => $off ? null : (int) $until])->save();
        }

        if (isset($data['bundle'])) {
            $user->forceFill(['push_bundle' => (bool) $data['bundle']])->save();
        }

        return $this->ok($this->data());
    }

    public function mailAccount(Request $request, int $account): JsonResponse
    {
        $data = $request->validate(['notify' => ['required', 'boolean']]);
        MailAccount::query()->where('user_id', $this->user()->id)->findOrFail($account)->update(['notify_new_mail' => $data['notify']]);

        return $this->ok($this->data());
    }

    /**
     * Registers (or takes over) this installation of the app for pushes.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32,255}$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'in:production,sandbox'],
        ]);

        DevicePushToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $this->user()->id,
            'platform' => 'ios',
            'name' => $data['name'] ?? null,
            'environment' => $data['environment'] ?? 'production',
        ]);

        return $this->ok($this->data(), 201);
    }

    public function removeDevice(int $device): JsonResponse
    {
        DevicePushToken::where('user_id', $this->user()->id)->whereKey($device)->delete();

        return $this->ok($this->data());
    }

    public function test(): JsonResponse
    {
        $reached = app(WebPush::class)->send($this->user(), __('Test notification'), __('Push notifications work on this device.'), route('notifications.index'), urgent: true, pushover: false);

        return $this->ok(['reached' => $reached, 'server_ready' => ApnsClient::isConfigured()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $user = $this->user();

        return [
            'topics' => collect(NotificationTopics::topics())->map(fn (array $topic, string $key): array => [
                'key' => $key,
                'label' => $topic['label'],
                'icon' => $topic['icon'],
                'level' => NotificationTopics::level($user, $key),
                'bell_only' => $key === 'emergency',
            ])->values()->all(),
            'quiet_from' => $user->push_quiet_from,
            'quiet_until' => $user->push_quiet_until,
            'bundle' => $user->push_bundle !== false,
            'mail_accounts' => MailAccount::query()->where('user_id', $user->id)->orderBy('label')->get()->map(fn (MailAccount $account): array => ['id' => $account->id, 'label' => $account->label, 'notify' => (bool) $account->notify_new_mail])->all(),
            'server_ready' => ApnsClient::isConfigured(),
            'devices' => DevicePushToken::where('user_id', $user->id)->latest('updated_at')->get()->map(fn (DevicePushToken $device): array => [
                'id' => $device->id,
                'name' => $device->name,
                'environment' => $device->environment,
                'last_success_at' => $device->last_success_at?->toIso8601String(),
                'last_error' => $device->last_error,
            ])->all(),
        ];
    }
}
