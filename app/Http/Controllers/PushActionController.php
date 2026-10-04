<?php

namespace App\Http\Controllers;

use App\Models\PushAction;
use App\Support\Automation\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Buttons of push notifications ("Yes, switch off"): the service worker answers with a POST
 * straight from the notification; phones without buttons open the page with both choices.
 * The secret link is the authorisation – it works once and only for a few hours.
 */
class PushActionController extends Controller
{
    public function show(string $token): View
    {
        $action = PushAction::findByToken($token) ?? abort(404);
        $choices = PushAction::where('user_id', $action->user_id)
            ->where('payload->pending_id', $action->payload['pending_id'] ?? 0)
            ->orderBy('id')->get();

        return view('push-actions.show', ['action' => $action, 'choices' => $choices, 'token' => $token]);
    }

    public function answer(Request $request, string $token, AutomationEngine $engine): JsonResponse|RedirectResponse
    {
        $action = PushAction::findByToken($token) ?? abort(404);

        // The page posts which button of this question was chosen; the notification posts its own link.
        if ($request->filled('pick')) {
            $action = PushAction::where('user_id', $action->user_id)
                ->where('payload->pending_id', $action->payload['pending_id'] ?? 0)
                ->find((int) $request->input('pick')) ?? abort(404);
        }

        $message = $action->isOpen()
            ? $engine->answer($action, (string) ($action->payload['choice'] ?? 'no'))
            : __('This question has already been answered or has expired.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('push-actions.show', $token)->with('status', $message);
    }
}
