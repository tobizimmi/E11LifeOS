<?php

namespace App\Support\SmartHome;

use App\Models\PresenceSimulation;
use App\Models\SmartHomeConnection;
use App\Support\Automation\AutomationEngine;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Simulated presence: within the time window some of the chosen lights are on, changing at
 * random every 10–40 minutes as if someone walked through the house; afterwards all go off.
 */
class PresenceSimulator
{
    /**
     * @param  array<mixed>  $entities  entity ids as entered in the automation
     */
    public function start(int $householdId, array $entities, string $from, string $until, ?int $automationId = null): PresenceSimulation
    {
        $entities = array_values(array_filter($entities, fn ($entity) => is_string($entity) && preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/', $entity)));

        return PresenceSimulation::updateOrCreate(['household_id' => $householdId], [
            'automation_id' => $automationId, 'entities' => $entities, 'from' => $from, 'until' => $until,
            'active' => true, 'lit' => [], 'next_change_at' => null,
        ]);
    }

    public function stop(int $householdId): void
    {
        $simulation = PresenceSimulation::where('household_id', $householdId)->first();

        if (! $simulation) {
            return;
        }

        $this->switch($simulation, $simulation->lit ?? [], false);
        $simulation->update(['active' => false, 'lit' => [], 'next_change_at' => null]);
    }

    public function tick(Carbon $now): void
    {
        PresenceSimulation::where('active', true)->get()->each(function (PresenceSimulation $simulation) use ($now) {
            $lit = $simulation->lit ?? [];

            if (! AutomationEngine::inWindow($now, $simulation->from, $simulation->until)) {
                if ($lit !== []) {
                    $this->switch($simulation, $lit, false);
                    $simulation->update(['lit' => [], 'next_change_at' => null]);
                }

                return;
            }

            if ($simulation->next_change_at && $simulation->next_change_at->gt($now)) {
                return;
            }

            $entities = $simulation->entities ?? [];
            $dark = array_values(array_diff($entities, $lit));
            $wanted = max(1, (int) ceil(count($entities) / 3));

            if (count($lit) >= $wanted) {
                // Someone leaves a room …
                $off = $lit[array_rand($lit)];
                $this->switch($simulation, [$off], false);
                $lit = array_values(array_diff($lit, [$off]));
            }

            if ($dark !== [] && count($lit) < $wanted) {
                // … and turns the light on in another one.
                $on = $dark[array_rand($dark)];
                $this->switch($simulation, [$on], true);
                $lit[] = $on;
            }

            $simulation->update(['lit' => $lit, 'next_change_at' => $now->copy()->addMinutes(random_int(10, 40))]);
        });
    }

    /**
     * @param  list<string>  $entities
     */
    private function switch(PresenceSimulation $simulation, array $entities, bool $on): void
    {
        $connection = SmartHomeConnection::where('household_id', $simulation->household_id)->where('type', SmartHomeConnection::HOME_ASSISTANT)->orderBy('id')->first();

        foreach ($entities as $entity) {
            try {
                $connection?->homeAssistant()->toggle($entity, $on);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
