<?php

namespace App\Support\SmartHome;

use Illuminate\Http\Client\PendingRequest;

/**
 * Home Assistant REST API with a long-lived access token: states and services.
 */
class HomeAssistantClient
{
    use ConnectionRoutes;

    protected function prepare(PendingRequest $request): PendingRequest
    {
        return $request->withToken((string) $this->connection->token);
    }

    /**
     * All entities with their state, sorted by name.
     *
     * @return list<array{entity_id: string, state: string, name: string, unit: ?string, attributes: array<string, mixed>}>
     */
    public function states(): array
    {
        $states = (array) $this->send(fn (PendingRequest $request, string $base) => $request->get($base.'/api/states'))->json();

        return collect($states)
            ->filter(fn ($state) => is_array($state) && isset($state['entity_id']))
            ->map(fn (array $state) => self::normalize($state))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * @return array{entity_id: string, state: string, name: string, unit: ?string, attributes: array<string, mixed>}
     */
    public function state(string $entityId): array
    {
        return self::normalize((array) $this->send(fn (PendingRequest $request, string $base) => $request->get($base.'/api/states/'.$entityId))->json());
    }

    /**
     * Calls a service, e.g. light.turn_on with {"entity_id": "light.flur", "brightness_pct": 40}.
     *
     * @param  array<string, mixed>  $data
     */
    public function callService(string $domain, string $service, array $data = []): void
    {
        if (! preg_match('/^[a-z0-9_]+$/', $domain) || ! preg_match('/^[a-z0-9_]+$/', $service)) {
            throw new SmartHomeException(__('Invalid service.'));
        }

        $this->send(fn (PendingRequest $request, string $base) => $request->post($base.'/api/services/'.$domain.'/'.$service, (object) $data));
    }

    /**
     * Switches an entity on/off or starts a scene or script, as fits its domain.
     */
    public function toggle(string $entityId, ?bool $on = null): void
    {
        $domain = (string) strtok($entityId, '.');

        match (true) {
            in_array($domain, ['scene', 'script'], true) => $this->callService($domain, 'turn_on', ['entity_id' => $entityId]),
            in_array($domain, ['button', 'input_button'], true) => $this->callService($domain, 'press', ['entity_id' => $entityId]),
            $on === null => $this->callService('homeassistant', 'toggle', ['entity_id' => $entityId]),
            default => $this->callService('homeassistant', $on ? 'turn_on' : 'turn_off', ['entity_id' => $entityId]),
        };
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{entity_id: string, state: string, name: string, unit: ?string, attributes: array<string, mixed>}
     */
    private static function normalize(array $state): array
    {
        $attributes = (array) ($state['attributes'] ?? []);

        return [
            'entity_id' => (string) ($state['entity_id'] ?? ''),
            'state' => (string) ($state['state'] ?? ''),
            'name' => (string) ($attributes['friendly_name'] ?? $state['entity_id'] ?? ''),
            'unit' => isset($attributes['unit_of_measurement']) ? (string) $attributes['unit_of_measurement'] : null,
            'attributes' => $attributes,
        ];
    }
}
