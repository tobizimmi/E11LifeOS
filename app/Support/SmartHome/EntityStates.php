<?php

namespace App\Support\SmartHome;

use App\Events\HouseholdItemsChanged;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use Illuminate\Support\Facades\Cache;

/**
 * The last known state of every entity a household works with: picked entities in the database,
 * all others (e.g. used only by an automation) in the cache.
 */
class EntityStates
{
    /**
     * @param  array<string, mixed>|null  $attributes
     */
    public static function remember(SmartHomeConnection $connection, string $entityId, string $state, ?array $attributes = null): void
    {
        Cache::put(self::key($connection->household_id, $entityId), $state, now()->addDays(7));

        $entity = SmartHomeEntity::where('smart_home_connection_id', $connection->id)->where('entity_id', $entityId)->first();

        if (! $entity || ($entity->state === $state && $attributes === null)) {
            return;
        }

        $changed = $entity->state !== $state;
        $entity->forceFill(array_filter([
            'state' => $state,
            'ha_attributes' => $attributes,
            'state_changed_at' => $changed ? now() : $entity->state_changed_at,
        ], fn ($value) => $value !== null))->save();

        if ($changed) {
            // Open Smart home pages and dashboards of the household update live (Reverb).
            HouseholdItemsChanged::dispatch($connection->household_id, 'smart_home');
        }
    }

    public static function get(int $householdId, string $entityId): ?string
    {
        return Cache::get(self::key($householdId, $entityId))
            ?? SmartHomeEntity::where('household_id', $householdId)->where('entity_id', $entityId)->value('state');
    }

    private static function key(int $householdId, string $entityId): string
    {
        return 'smarthome:state:'.$householdId.':'.$entityId;
    }
}
