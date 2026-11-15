<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ShoppingList;
use App\Support\AiException;
use App\Support\BrainAi;
use App\Support\BrainSearch;
use App\Support\QuickCapture;
use App\Support\ShoppingLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Ask your Second Brain" for the app: Claude answers from the member's own pages, entries and
 * recipes, and may propose actions that only run after the member confirms them.
 */
class BrainController extends ApiController
{
    private const MAX_QUESTIONS_PER_HOUR = 30;

    public function status(): JsonResponse
    {
        return $this->ok(['available' => BrainAi::isConfigured()]);
    }

    public function ask(Request $request, BrainAi $ai): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:1000']]);
        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
        $limiterKey = 'brain-ask:'.$this->user()->id;

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_QUESTIONS_PER_HOUR)) {
            return response()->json(['success' => false, 'message' => __('Too many questions in a short time. Please try again in a few minutes.')], 429);
        }

        RateLimiter::hit($limiterKey, 3600);
        $question = trim($data['question']);
        $documents = BrainSearch::documents($question, $this->user(), $household->id);

        if ($documents === []) {
            return response()->json(['success' => false, 'message' => __('Your Second Brain is still empty – add pages, tasks or entries first.')], 422);
        }

        try {
            $result = $ai->answer($question, $documents);
        } catch (AiException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 503);
        }

        $titles = collect($documents)->pluck('title', 'key');

        return $this->ok([
            'question' => $question,
            'answer' => $result['answer'],
            'sources' => collect($result['sources'])->map(fn (string $key) => ['key' => $key, 'title' => (string) ($titles[$key] ?? $key)])->values()->all(),
            'actions' => $result['actions'],
        ]);
    }

    /**
     * Carries out one proposed action after the member confirmed it. The action comes back from the
     * device, so it is cleaned again.
     */
    public function action(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'array']]);
        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
        $action = BrainAi::cleanActions([$data['action']])[0] ?? abort(422, __('This action is not valid.'));

        match ($action['type']) {
            BrainAi::ACTION_TASK => QuickCapture::task($this->user(), $household->id, $action['title'], $action['due']),
            BrainAi::ACTION_INBOX => QuickCapture::inbox($this->user(), $household->id, $action['title']),
            BrainAi::ACTION_SHOPPING => collect($action['items'])->each(fn (string $item) => ShoppingLists::add(ShoppingList::defaultFor($household->id), $item, null, $this->user()->id)),
            default => abort(422, __('This action is not valid.')),
        };

        return $this->ok();
    }
}
