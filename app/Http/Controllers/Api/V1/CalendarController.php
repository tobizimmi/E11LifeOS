<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CalendarSubscription;
use App\Models\Household;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for external calendar subscriptions (ICS/webcal feeds). Their events show up in the
 * aggregated /calendar endpoint; here they can be listed, added and removed.
 */
class CalendarController extends ApiController
{
    public function subscriptions(): JsonResponse
    {
        $subscriptions = CalendarSubscription::query()
            ->visibleTo($this->user(), $this->household()->id)
            ->with('user')
            ->orderBy('name')
            ->get()
            ->map(function (CalendarSubscription $subscription): array {
                // The URL is stored encrypted and may hold a secret token, so expose only its host.
                $owner = $subscription->user;

                return [
                    'id' => $subscription->id,
                    'name' => $subscription->name,
                    'host' => parse_url($subscription->url, PHP_URL_HOST) ?: null,
                    'visibility' => $subscription->visibility,
                    'owner' => $owner instanceof User ? $owner->name : null,
                    'mine' => $subscription->user_id === $this->user()->id,
                    'error' => $subscription->error,
                ];
            })
            ->all();

        return $this->ok($subscriptions);
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:2000', 'regex:#^(https?|webcal)://#i'],
            'visibility' => ['sometimes', Rule::in([Item::VISIBILITY_SHARED, Item::VISIBILITY_PRIVATE])],
        ]);

        $subscription = CalendarSubscription::create([
            'user_id' => $this->user()->id,
            'household_id' => $this->household()->id,
            'name' => $data['name'],
            'url' => $data['url'],
            'visibility' => $data['visibility'] ?? Item::VISIBILITY_PRIVATE,
        ]);

        return $this->ok([
            'id' => $subscription->id,
            'name' => $subscription->name,
            'host' => parse_url($subscription->url, PHP_URL_HOST) ?: null,
            'visibility' => $subscription->visibility,
            'mine' => true,
        ], 201);
    }

    public function destroySubscription(CalendarSubscription $subscription): JsonResponse
    {
        // Only the owner may remove a feed – a shared one stays for the others.
        abort_unless($subscription->user_id === $this->user()->id, 404);
        $subscription->delete();

        return $this->ok();
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
