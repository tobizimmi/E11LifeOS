<?php

namespace App\Http\Controllers;

use App\Models\SmartHomeConnection;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\EntityStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Home Assistant reports state changes here (rest_command with the connection's secret in the
 * address), so values arrive at once and automations react without polling.
 */
class SmartHomeWebhookController extends Controller
{
    public function __invoke(Request $request, string $secret, AutomationEngine $engine): JsonResponse
    {
        $connection = SmartHomeConnection::query()->whereShareToken('webhook_secret', $secret)->first();
        abort_if($connection === null || strlen($secret) < 32, 404);

        $payload = json_decode($request->getContent(), true);
        $changes = is_array($payload) ? (array_is_list($payload) ? $payload : [$payload]) : [];
        $accepted = 0;

        foreach (array_slice($changes, 0, 50) as $change) {
            $entityId = (string) ($change['entity_id'] ?? '');

            if (! is_array($change) || ! preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $entityId)) {
                continue;
            }

            $state = mb_substr((string) ($change['state'] ?? ''), 0, 255);
            EntityStates::remember($connection, $entityId, $state, is_array($change['attributes'] ?? null) ? $change['attributes'] : null);
            $engine->entityChanged($connection->household_id, $entityId, $state);
            $accepted++;
        }

        $connection->forceFill(['last_contact_at' => now(), 'last_route' => 'webhook'])->save();

        return response()->json(['accepted' => $accepted]);
    }
}
