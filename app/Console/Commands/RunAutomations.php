<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\SmartHomeConnection;
use App\Support\Automation\AutomationEngine;
use App\Support\SmartHome\EntityStates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('automations:run')]
#[Description('Refresh Home Assistant values, check the automations and run the presence simulation (every minute)')]
class RunAutomations extends Command
{
    public function handle(AutomationEngine $engine): int
    {
        $this->refreshStates();
        $ran = $engine->tick();
        $this->info('Automations run: '.$ran);

        return self::SUCCESS;
    }

    /**
     * Polls Home Assistant for the picked entities and those used by automations – unless the
     * webhook or the live connection (smart-home:listen) delivered values within the last five minutes.
     */
    private function refreshStates(): void
    {
        SmartHomeConnection::where('type', SmartHomeConnection::HOME_ASSISTANT)->get()->each(function (SmartHomeConnection $connection) {
            if (in_array($connection->last_route, ['webhook', 'websocket'], true) && $connection->last_contact_at?->gt(now()->subMinutes(5))) {
                return;
            }

            $wanted = $connection->entities()->pluck('entity_id')->merge(
                Automation::where('household_id', $connection->household_id)->where('active', true)->get()
                    ->flatMap(fn (Automation $automation) => collect([$automation->trigger, ...($automation->conditions ?? [])])->where('type', 'entity')->pluck('entity_id'))
            )->filter()->unique();

            if ($wanted->isEmpty()) {
                return;
            }

            try {
                foreach ($connection->homeAssistant()->states() as $state) {
                    if ($wanted->contains($state['entity_id'])) {
                        EntityStates::remember($connection, $state['entity_id'], $state['state'], $state['attributes']);
                    }
                }
            } catch (Throwable) {
                // Noted on the connection; the page shows the error.
            }
        });
    }
}
