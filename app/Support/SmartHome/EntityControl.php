<?php

namespace App\Support\SmartHome;

use App\Models\HouseholdMember;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Support\Security\SecurityLog;
use Closure;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Switching a device from the tool (Smart home page, dashboard): only members allowed to control,
 * critical devices (locks, doors, alarm) only after a confirmation and noted in the security log.
 */
class EntityControl
{
    /** Domains whose switching needs a confirmation. */
    public const CRITICAL = ['lock', 'cover', 'alarm_control_panel', 'valve'];

    /**
     * Whether the member may switch devices (owners always; others unless switched off on the
     * household page).
     */
    /**
     * Runs a tap or slider action of a page: Home Assistant errors come back as a message for a
     * toast, missing rights (403) and unknown devices (404) are thrown on.
     */
    public static function attempt(Closure $action): ?string
    {
        try {
            $action();

            return null;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }
    }

    public static function mayControl(User $user, int $householdId): bool
    {
        $membership = HouseholdMember::where('household_id', $householdId)->where('user_id', $user->id)->first();

        return (bool) $membership && ($membership->isOwner() || ($membership->permissions['smart_home_control'] ?? true) !== false);
    }

    public static function isCritical(SmartHomeEntity $entity): bool
    {
        return in_array($entity->domain(), self::CRITICAL, true);
    }

    /**
     * Switches the entity (on/off, open/close, start) and stores its new state.
     */
    public static function press(User $user, SmartHomeEntity $entity, bool $confirmed = false): void
    {
        abort_unless(self::mayControl($user, $entity->household_id), 403);
        abort_unless($entity->isSwitchable() || $entity->isRunnable() || self::isCritical($entity), 422);

        if (self::isCritical($entity) && ! $confirmed) {
            throw new SmartHomeException(__('Please confirm switching :name.', ['name' => $entity->name]));
        }

        $client = $entity->connection->homeAssistant();

        match ($entity->domain()) {
            'lock' => $client->callService('lock', $entity->state === 'locked' ? 'unlock' : 'lock', ['entity_id' => $entity->entity_id]),
            'cover', 'valve' => $client->callService($entity->domain(), in_array($entity->state, ['open', 'opening'], true) ? 'close_'.$entity->domain() : 'open_'.$entity->domain(), ['entity_id' => $entity->entity_id]),
            'alarm_control_panel' => $client->callService('alarm_control_panel', $entity->state === 'disarmed' ? 'alarm_arm_away' : 'alarm_disarm', ['entity_id' => $entity->entity_id]),
            default => $client->toggle($entity->entity_id, $entity->isSwitchable() ? ! $entity->isOn() : null),
        };

        if (self::isCritical($entity)) {
            SecurityLog::record($user, 'smart_home_critical', details: $entity->name.' ('.$entity->entity_id.')');
        }

        if (! $entity->isRunnable()) {
            $state = $client->state($entity->entity_id);
            EntityStates::remember($entity->connection, $entity->entity_id, $state['state'], $state['attributes']);
        }
    }

    /**
     * Sets a value: brightness of a light (0–100 %), target temperature, position of a cover.
     */
    public static function setValue(User $user, SmartHomeEntity $entity, float $value): void
    {
        abort_unless(self::mayControl($user, $entity->household_id), 403);
        $client = $entity->connection->homeAssistant();

        match ($entity->domain()) {
            'light' => $client->callService('light', 'turn_on', ['entity_id' => $entity->entity_id, 'brightness_pct' => (int) max(1, min(100, $value))]),
            'climate' => $client->callService('climate', 'set_temperature', ['entity_id' => $entity->entity_id, 'temperature' => round(max(5, min(30, $value)) * 2) / 2]),
            'cover' => $client->callService('cover', 'set_cover_position', ['entity_id' => $entity->entity_id, 'position' => (int) max(0, min(100, $value))]),
            default => abort(422),
        };

        $state = $client->state($entity->entity_id);
        EntityStates::remember($entity->connection, $entity->entity_id, $state['state'], $state['attributes']);
    }
}
