<?php

namespace App\Support\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\HouseholdMember;
use App\Models\HouseholdMode;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\Support\QuickCapture;
use App\Support\SmartHome\EntityStates;
use App\Support\SmartHome\PresenceSimulator;
use App\Support\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Runs the automations of all households. Time and state triggers (calendar, Home Assistant,
 * evcc) are checked every minute; states fire on their edge (e.g. "holiday begins" once, not every
 * minute). Mode changes and webhook values fire at once. Actions that switch a mode may start
 * further automations – at most three levels deep, so automations cannot call each other forever.
 */
class AutomationEngine
{
    private const MAX_DEPTH = 3;

    /** @var array<int, array<string, mixed>|false> evcc state per household for this tick */
    private array $evccStates = [];

    public function __construct(private WebPush $webPush, private PresenceSimulator $presence) {}

    /**
     * Checks all active automations; returns how many ran.
     */
    public function tick(?Carbon $now = null): int
    {
        $now ??= now();
        $this->evccStates = [];
        $ran = 0;

        Automation::where('active', true)->with('user')->get()->each(function (Automation $automation) use ($now, &$ran) {
            $type = $automation->trigger['type'] ?? '';

            if ($type === 'time') {
                $ran += $this->checkTime($automation, $now) ? 1 : 0;
            } elseif (in_array($type, ['calendar', 'entity', 'evcc'], true)) {
                $ran += $this->checkState($automation, $this->stateHolds($automation, $now), $now) ? 1 : 0;
            }
        });

        $this->presence->tick($now);

        return $ran;
    }

    /**
     * A value from Home Assistant changed (webhook): entity triggers react at once.
     */
    public function entityChanged(int $householdId, string $entityId, string $state): void
    {
        Automation::where('household_id', $householdId)->where('active', true)->with('user')->get()
            ->filter(fn (Automation $automation) => ($automation->trigger['type'] ?? '') === 'entity' && ($automation->trigger['entity_id'] ?? '') === $entityId)
            ->each(fn (Automation $automation) => $this->checkState($automation, self::compare($state, (string) ($automation->trigger['operator'] ?? 'eq'), (string) ($automation->trigger['value'] ?? '')), now()));
    }

    /**
     * Switches a mode and starts the automations waiting for it.
     */
    public function setMode(int $householdId, string $key, bool $active, int $depth = 0): void
    {
        $mode = HouseholdMode::forHousehold($householdId)->firstWhere('key', $key);

        if (! $mode || $mode->active === $active) {
            return;
        }

        $mode->update(['active' => $active, 'changed_at' => now()]);

        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        Automation::where('household_id', $householdId)->where('active', true)->with('user')->get()
            ->filter(fn (Automation $automation) => ($automation->trigger['type'] ?? '') === 'mode' && ($automation->trigger['key'] ?? '') === $key && (($automation->trigger['edge'] ?? 'on') === 'on') === $active)
            ->each(fn (Automation $automation) => $this->run($automation, __('Mode “:mode” :state', ['mode' => $mode->name, 'state' => $active ? __('switched on') : __('switched off')]), depth: $depth + 1));
    }

    /**
     * Runs the actions if the conditions hold (or always, when started by hand).
     */
    public function run(Automation $automation, string $reason, bool $manual = false, int $depth = 0): AutomationRun
    {
        $now = now();

        if (! $manual && $automation->last_run_at && $automation->last_run_at->gt($now->copy()->subMinutes(max(1, $automation->cooldown_minutes))->addSecond())) {
            return $automation->runs()->create(['reason' => $reason, 'status' => 'skipped', 'log' => [['ok' => true, 'message' => __('Ran shortly before – paused.')]]]);
        }

        $failed = collect($automation->conditions ?? [])->first(fn (array $condition) => ! $this->conditionHolds($automation, $condition, $now));

        if ($failed && ! $manual) {
            return $automation->runs()->create(['reason' => $reason, 'status' => 'skipped', 'log' => [['ok' => true, 'message' => __('Condition not met: :condition', ['condition' => AutomationCatalog::describe($failed)])]]]);
        }

        $log = [];

        foreach ($automation->actions ?? [] as $action) {
            try {
                $message = $this->perform($automation, $action, $depth);
                $log[] = ['ok' => true, 'message' => AutomationCatalog::describe($action).($message ? ' – '.$message : '')];
            } catch (Throwable $exception) {
                $log[] = ['ok' => false, 'message' => AutomationCatalog::describe($action).' – '.mb_strimwidth($exception->getMessage(), 0, 200, '…')];
            }
        }

        $errors = collect($log)->where('ok', false)->count();
        $automation->forceFill(['last_run_at' => $now])->save();

        return $automation->runs()->create([
            'reason' => mb_substr($reason, 0, 255),
            'status' => $errors === 0 ? 'done' : ($errors === count($log) ? 'failed' : 'partial'),
            'log' => $log,
        ]);
    }

    private function checkTime(Automation $automation, Carbon $now): bool
    {
        $trigger = $automation->trigger;
        $days = array_map('intval', (array) ($trigger['days'] ?? []));

        if (($trigger['at'] ?? '') !== $now->format('H:i') || ($days !== [] && ! in_array($now->isoWeekday(), $days, true))
            || ($automation->last_run_at && $automation->last_run_at->format('Y-m-d H:i') === $now->format('Y-m-d H:i'))) {
            return false;
        }

        $this->run($automation, __('Time :time', ['time' => $trigger['at']]));

        return true;
    }

    /**
     * Fires on the edge of a state: "begins" (false → true) or, for calendar triggers, "ends".
     */
    private function checkState(Automation $automation, ?bool $holds, Carbon $now): bool
    {
        if ($holds === null || $holds === $automation->trigger_state) {
            return false;
        }

        $automation->forceFill(['trigger_state' => $holds])->save();
        $wanted = ($automation->trigger['edge'] ?? 'start') === 'end' ? ! $holds : $holds;

        if (! $wanted) {
            return false;
        }

        $this->run($automation, AutomationCatalog::describe($automation->trigger).' · '.($holds ? __('begins') : __('ends')));

        return true;
    }

    /**
     * Whether the state behind a calendar, entity or evcc trigger holds now; null if unknown.
     */
    private function stateHolds(Automation $automation, Carbon $now): ?bool
    {
        $trigger = $automation->trigger;

        return match ($trigger['type']) {
            'calendar' => $automation->user ? CalendarMatcher::active($automation->user, $automation->household_id, (string) ($trigger['keyword'] ?? ''), (array) ($trigger['sources'] ?? [CalendarMatcher::CALENDAR]), $now) : null,
            'entity' => ($state = EntityStates::get($automation->household_id, (string) ($trigger['entity_id'] ?? ''))) === null ? null : self::compare($state, (string) ($trigger['operator'] ?? 'eq'), (string) ($trigger['value'] ?? '')),
            'evcc' => ($value = $this->evccValue($automation->household_id, (string) ($trigger['metric'] ?? 'pv'))) === null ? null : self::compare((string) $value, (string) ($trigger['operator'] ?? 'gt'), (string) ($trigger['value'] ?? '0')),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function conditionHolds(Automation $automation, array $condition, Carbon $now): bool
    {
        return match ($condition['type'] ?? '') {
            'mode' => (bool) HouseholdMode::forHousehold($automation->household_id)->firstWhere('key', $condition['key'] ?? '')?->active === (bool) ($condition['active'] ?? true),
            'time_window' => self::inWindow($now, (string) ($condition['from'] ?? '00:00'), (string) ($condition['until'] ?? '23:59')),
            'weekdays' => in_array($now->isoWeekday(), array_map('intval', (array) ($condition['days'] ?? [])), true),
            'entity' => self::compare((string) EntityStates::get($automation->household_id, (string) ($condition['entity_id'] ?? '')), (string) ($condition['operator'] ?? 'eq'), (string) ($condition['value'] ?? '')),
            'calendar' => $automation->user !== null && CalendarMatcher::active($automation->user, $automation->household_id, (string) ($condition['keyword'] ?? ''), (array) ($condition['sources'] ?? [CalendarMatcher::CALENDAR]), $now) === (bool) ($condition['active'] ?? true),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function perform(Automation $automation, array $action, int $depth): ?string
    {
        $householdId = $automation->household_id;

        switch ($action['type'] ?? '') {
            case 'ha_service':
                [$domain, $service] = array_pad(explode('.', (string) ($action['service'] ?? ''), 2), 2, '');
                $data = json_decode((string) ($action['data'] ?? ''), true);
                $data = is_array($data) ? $data : [];

                if (filled($action['entity_id'] ?? null)) {
                    $data['entity_id'] = $action['entity_id'];
                }

                $this->connection($householdId, SmartHomeConnection::HOME_ASSISTANT)->homeAssistant()->callService($domain, $service, $data);

                return null;
            case 'evcc_mode':
                $this->connection($householdId, SmartHomeConnection::EVCC)->evcc()->setMode((int) ($action['loadpoint'] ?? 1), (string) ($action['mode'] ?? 'pv'));

                return null;
            case 'evcc_limit':
                $this->connection($householdId, SmartHomeConnection::EVCC)->evcc()->setLimitSoc((int) ($action['loadpoint'] ?? 1), (int) ($action['soc'] ?? 80));

                return null;
            case 'push':
                $recipients = ($action['to'] ?? 'me') === 'household' ? $this->members($householdId) : collect([$automation->user])->filter();
                $recipients->each(fn (User $user) => $this->webPush->send($user, (string) ($action['title'] ?? $automation->name), (string) ($action['body'] ?? ''), route('automations.index'), urgent: (bool) ($action['urgent'] ?? false)));

                return trans_choice(':count recipient|:count recipients', $recipients->count());
            case 'task':
                $due = match ($action['due'] ?? '') {
                    'today' => today()->toDateString(),
                    'tomorrow' => today()->addDay()->toDateString(),
                    default => null,
                };
                $task = $automation->user ? QuickCapture::task($automation->user, $householdId, (string) ($action['title'] ?? ''), $due) : null;

                return $task ? '„'.$task->title.'“' : null;
            case 'mode':
                $this->setMode($householdId, (string) ($action['key'] ?? ''), (bool) ($action['active'] ?? true), $depth);

                return null;
            case 'presence_start':
                $this->presence->start($householdId, (array) ($action['entities'] ?? []), (string) ($action['from'] ?? '18:00'), (string) ($action['until'] ?? '23:30'), $automation->id);

                return null;
            case 'presence_stop':
                $this->presence->stop($householdId);

                return null;
            default:
                throw new \RuntimeException(__('Unknown action.'));
        }
    }

    private function connection(int $householdId, string $type): SmartHomeConnection
    {
        return SmartHomeConnection::where('household_id', $householdId)->where('type', $type)->orderBy('id')->first()
            ?? throw new \RuntimeException(__(':type is not connected yet.', ['type' => SmartHomeConnection::types()[$type]['label']]));
    }

    private function evccValue(int $householdId, string $metric): ?float
    {
        if (! array_key_exists($householdId, $this->evccStates)) {
            try {
                $this->evccStates[$householdId] = $this->connection($householdId, SmartHomeConnection::EVCC)->evcc()->state();
            } catch (Throwable) {
                $this->evccStates[$householdId] = false;
            }
        }

        $state = $this->evccStates[$householdId];

        if ($state === false) {
            return null;
        }

        $point = $state['loadpoints'][0] ?? [];

        return match ($metric) {
            'charging' => isset($point['charging']) ? (float) $point['charging'] : null,
            'connected' => isset($point['connected']) ? (float) $point['connected'] : null,
            'vehicleSoc' => $point['vehicleSoc'] ?? null,
            default => $state[$metric] ?? null,
        };
    }

    /**
     * @return Collection<int, User>
     */
    private function members(int $householdId): Collection
    {
        return User::whereIn('id', HouseholdMember::where('household_id', $householdId)->pluck('user_id'))->get();
    }

    public static function compare(string $actual, string $operator, string $expected): bool
    {
        $numeric = is_numeric($actual) && is_numeric($expected);

        return match ($operator) {
            'ne' => mb_strtolower($actual) !== mb_strtolower($expected),
            'gt' => $numeric && (float) $actual > (float) $expected,
            'lt' => $numeric && (float) $actual < (float) $expected,
            default => mb_strtolower($actual) === mb_strtolower($expected),
        };
    }

    public static function inWindow(Carbon $now, string $from, string $until): bool
    {
        $time = $now->format('H:i');

        return $from <= $until ? $time >= $from && $time < $until : $time >= $from || $time < $until;
    }
}
