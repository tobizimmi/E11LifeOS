<?php

namespace App\Support\SmartHome;

use App\Models\Automation;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Support\Automation\AutomationEngine;
use Illuminate\Support\Collection;

/**
 * The conversation with Home Assistant over its WebSocket API: signs in, subscribes to state
 * changes and reads the area registry. State changes of the entities the household uses go to
 * the state cache and the automations at once; picked entities without an area get the area
 * from Home Assistant.
 */
class HomeAssistantStream
{
    private const SUBSCRIBE = 1;

    private const AREAS = 2;

    private const DEVICES = 3;

    private const ENTITIES = 4;

    public bool $authenticated = false;

    /** @var array<string, string> area id => name */
    private array $areas = [];

    /** @var array<string, ?string> device id => area id */
    private array $deviceAreas = [];

    /** @var Collection<int, string>|null */
    private ?Collection $wanted = null;

    private int $wantedAt = 0;

    /**
     * @param  callable(array<string, mixed>): void  $send
     */
    public function __construct(private SmartHomeConnection $connection, private $send, private AutomationEngine $engine) {}

    /**
     * Reacts to one message of Home Assistant.
     *
     * @param  array<string, mixed>  $message
     */
    public function handle(array $message): void
    {
        match ($message['type'] ?? null) {
            'auth_required' => ($this->send)(['type' => 'auth', 'access_token' => (string) $this->connection->token]),
            'auth_ok' => $this->subscribe(),
            'auth_invalid' => throw new SmartHomeException(__('Access denied – check the token.')),
            'event' => $this->event($message['event'] ?? []),
            'result' => $this->result((int) ($message['id'] ?? 0), $message['result'] ?? null),
            default => null,
        };
    }

    private function subscribe(): void
    {
        $this->authenticated = true;
        ($this->send)(['id' => self::SUBSCRIBE, 'type' => 'subscribe_events', 'event_type' => 'state_changed']);
        ($this->send)(['id' => self::AREAS, 'type' => 'config/area_registry/list']);
        ($this->send)(['id' => self::DEVICES, 'type' => 'config/device_registry/list']);
        ($this->send)(['id' => self::ENTITIES, 'type' => 'config/entity_registry/list']);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function event(array $event): void
    {
        $data = $event['data'] ?? [];
        $entityId = (string) ($data['entity_id'] ?? '');
        $state = $data['new_state'] ?? null;

        if (! is_array($state) || ! $this->wanted()->contains($entityId)) {
            return;
        }

        $value = mb_substr((string) ($state['state'] ?? ''), 0, 255);
        EntityStates::remember($this->connection, $entityId, $value, is_array($state['attributes'] ?? null) ? $state['attributes'] : null);
        $this->engine->entityChanged($this->connection->household_id, $entityId, $value);
    }

    private function result(int $id, mixed $result): void
    {
        if (! is_array($result)) {
            return;
        }

        if ($id === self::AREAS) {
            $this->areas = collect($result)->filter(fn ($area) => isset($area['area_id'], $area['name']))
                ->mapWithKeys(fn (array $area) => [$area['area_id'] => (string) $area['name']])->all();
        } elseif ($id === self::DEVICES) {
            $this->deviceAreas = collect($result)->filter(fn ($device) => isset($device['id']))
                ->mapWithKeys(fn (array $device) => [$device['id'] => $device['area_id'] ?? null])->all();
        } elseif ($id === self::ENTITIES) {
            $this->assignAreas($result);
        }
    }

    /**
     * Picked entities without an area take the one of Home Assistant (entity or its device).
     *
     * @param  array<int, mixed>  $registry
     */
    private function assignAreas(array $registry): void
    {
        $byEntity = collect($registry)->filter(fn ($entry) => isset($entry['entity_id']))
            ->mapWithKeys(fn (array $entry) => [$entry['entity_id'] => $entry['area_id'] ?? ($this->deviceAreas[$entry['device_id'] ?? ''] ?? null)]);

        SmartHomeEntity::where('smart_home_connection_id', $this->connection->id)
            ->where(fn ($query) => $query->whereNull('area')->orWhere('area', ''))
            ->get()
            ->each(function (SmartHomeEntity $entity) use ($byEntity) {
                $areaId = $byEntity->get($entity->entity_id);

                if ($areaId && isset($this->areas[$areaId])) {
                    $entity->update(['area' => $this->areas[$areaId]]);
                }
            });
    }

    /**
     * Entities the household works with: picked ones and those automations look at (refreshed
     * every minute, so new devices and automations are picked up without a restart).
     *
     * @return Collection<int, string>
     */
    private function wanted(): Collection
    {
        if ($this->wanted === null || time() - $this->wantedAt > 60) {
            $this->wanted = SmartHomeEntity::where('smart_home_connection_id', $this->connection->id)->pluck('entity_id')
                ->merge(Automation::where('household_id', $this->connection->household_id)->where('active', true)->get()
                    ->flatMap(fn (Automation $automation) => collect([$automation->trigger, ...($automation->conditions ?? [])])->where('type', 'entity')->pluck('entity_id')))
                ->filter()->unique()->values();
            $this->wantedAt = time();
        }

        return $this->wanted;
    }
}
