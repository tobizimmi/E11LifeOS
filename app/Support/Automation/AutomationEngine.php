<?php

namespace App\Support\Automation;

use App\Models\Automation;
use App\Models\AutomationPendingAction;
use App\Models\AutomationRun;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\HouseholdMember;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\PushAction;
use App\Models\ShoppingList;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEntity;
use App\Models\User;
use App\Support\CalendarEvents;
use App\Support\DashboardWidgets;
use App\Support\Drives\WebDavClient;
use App\Support\Finance\Budgets;
use App\Support\Pantry;
use App\Support\QuickCapture;
use App\Support\ShoppingLists;
use App\Support\SmartHome\EntityStates;
use App\Support\SmartHome\PresenceSimulator;
use App\Support\Vpn;
use App\Support\Weather;
use App\Support\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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

    /** Triggers at a moment that is checked every minute. */
    private const MOMENT_TRIGGERS = ['sun', 'appointment', 'task_due', 'waste', 'deadline', 'birthday', 'expiry'];

    /** Triggers on a state that fire when it begins (or ends). */
    private const STATE_TRIGGERS = ['calendar', 'entity', 'entity_for', 'evcc', 'budget', 'presence', 'weather', 'balance'];

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

        $this->runPending($now);

        Automation::where('active', true)->with('user')->get()->each(function (Automation $automation) use ($now, &$ran) {
            $type = $automation->trigger['type'] ?? '';

            if ($type === 'time') {
                $ran += $this->checkTime($automation, $now) ? 1 : 0;
            } elseif (in_array($type, self::MOMENT_TRIGGERS, true)) {
                $ran += $this->checkMoment($automation, $now) ? 1 : 0;
            } elseif (in_array($type, self::STATE_TRIGGERS, true)) {
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
     * A mail reached the household's address: mail triggers whose word is in the subject or the
     * sender run at once (e.g. "Paket" for parcel notices).
     */
    public function mailReceived(int $householdId, string $subject, string $from): void
    {
        Automation::where('household_id', $householdId)->where('active', true)->with('user')->get()
            ->filter(fn (Automation $automation) => ($automation->trigger['type'] ?? '') === 'mail')
            ->filter(function (Automation $automation) use ($subject, $from) {
                $keyword = mb_strtolower(trim((string) ($automation->trigger['keyword'] ?? '')));

                return $keyword === '' || str_contains(mb_strtolower($subject.' '.$from), $keyword);
            })
            ->each(fn (Automation $automation) => $this->run($automation, __('Mail from :from: :subject', ['from' => $from, 'subject' => $subject])));
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

        $checked = [];

        foreach ($automation->conditions ?? [] as $condition) {
            if (! $this->conditionHolds($automation, $condition, $now)) {
                if (! $manual) {
                    return $automation->runs()->create(['reason' => mb_substr($reason, 0, 255), 'status' => 'skipped', 'log' => [...$checked, ['ok' => true, 'message' => __('Condition not met: :condition', ['condition' => AutomationCatalog::describe($condition)])]]]);
                }

                $checked[] = ['ok' => true, 'message' => __('Condition not met: :condition', ['condition' => AutomationCatalog::describe($condition)]).' – '.__('ignored when started by hand')];

                continue;
            }

            $checked[] = ['ok' => true, 'message' => __('Condition met: :condition', ['condition' => AutomationCatalog::describe($condition)])];
        }

        return $this->performAll($automation, $automation->actions ?? [], $reason, $depth, $checked);
    }

    /**
     * What would happen now, without doing anything: the trigger, each condition and the actions
     * with their placeholders filled in.
     *
     * @return list<array{ok: bool, message: string}>
     */
    public function dryRun(Automation $automation): array
    {
        $now = now();
        $trigger = $automation->trigger;
        $holds = in_array($trigger['type'], self::STATE_TRIGGERS, true) ? $this->stateHolds($automation, $now) : null;
        $log = [['ok' => true, 'message' => __('Trigger: :trigger', ['trigger' => AutomationCatalog::describe($trigger)])
            .($holds === null ? '' : ' – '.($holds ? __('holds right now') : __('does not hold right now')))]];
        $reason = __('Test run');
        $stop = false;

        foreach ($automation->conditions ?? [] as $condition) {
            $met = $this->conditionHolds($automation, $condition, $now);
            $stop = $stop || ! $met;
            $log[] = ['ok' => $met, 'message' => __($met ? 'Condition met: :condition' : 'Condition not met: :condition', ['condition' => AutomationCatalog::describe($condition)])];
        }

        if ($stop) {
            $log[] = ['ok' => false, 'message' => __('The actions would not run now.')];

            return $log;
        }

        foreach ($automation->actions ?? [] as $action) {
            $log[] = ['ok' => true, 'message' => __('Would run: :action', ['action' => AutomationCatalog::describe(Placeholders::apply($action, $automation, $reason))])];

            if ($action['type'] === 'ask') {
                $log[] = ['ok' => true, 'message' => __('The rest runs only after “yes”.')];
            }
        }

        return $log;
    }

    /**
     * Runs a list of actions; a "wait" action parks the rest until its time has come.
     *
     * Texts get their placeholders filled in with the reason of this run (also for the actions
     * that wait, so they keep the original reason).
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array{ok: bool, message: string}>  $log  checked conditions
     */
    private function performAll(Automation $automation, array $actions, string $reason, int $depth, array $log = []): AutomationRun
    {
        $now = now();
        $checked = count($log);
        $actions = array_map(fn (array $action) => Placeholders::apply($action, $automation, $reason), $actions);

        foreach ($actions as $index => $action) {
            if (($action['type'] ?? '') === 'ask') {
                $log[] = ['ok' => true, 'message' => AutomationCatalog::describe($action).' – '.$this->ask($automation, $action, array_slice($actions, $index + 1), $depth)];

                break;
            }

            if (($action['type'] ?? '') === 'delay') {
                $minutes = max(1, min(1440, (int) ($action['minutes'] ?? 5)));
                AutomationPendingAction::create(['automation_id' => $automation->id, 'actions' => array_slice($actions, $index + 1), 'depth' => $depth, 'run_at' => $now->copy()->addMinutes($minutes)]);
                $log[] = ['ok' => true, 'message' => AutomationCatalog::describe($action)];

                break;
            }

            try {
                $message = $this->perform($automation, $action, $depth, $reason);
                $log[] = ['ok' => true, 'message' => AutomationCatalog::describe($action).($message ? ' – '.$message : '')];
            } catch (Throwable $exception) {
                $log[] = ['ok' => false, 'message' => AutomationCatalog::describe($action).' – '.mb_strimwidth($exception->getMessage(), 0, 200, '…')];
            }
        }

        $errors = collect($log)->where('ok', false)->count();
        $automation->forceFill(['last_run_at' => $now])->save();

        return $automation->runs()->create([
            'reason' => mb_substr($reason, 0, 255),
            'status' => $errors === 0 ? 'done' : ($errors === count($log) - $checked ? 'failed' : 'partial'),
            'log' => $log,
        ]);
    }

    /**
     * "Ask by push": the remaining actions wait for an answer; each recipient gets a notification
     * with the two buttons (and a link to a page with them, for phones without buttons).
     *
     * @param  array<string, mixed>  $action
     * @param  list<array<string, mixed>>  $rest
     */
    private function ask(Automation $automation, array $action, array $rest, int $depth): string
    {
        $expires = now()->addHours(max(1, min(48, (int) ($action['hours'] ?? 2))));
        $pending = AutomationPendingAction::create(['automation_id' => $automation->id, 'actions' => $rest, 'depth' => $depth, 'run_at' => null, 'expires_at' => $expires]);
        $recipients = ($action['to'] ?? 'me') === 'household' ? $this->members($automation->household_id) : collect([$automation->user])->filter();

        foreach ($recipients as $user) {
            $yes = PushAction::issue($user, (string) ($action['yes'] ?? __('Yes')) ?: __('Yes'), ['kind' => 'automation', 'pending_id' => $pending->id, 'choice' => 'yes'], $expires);
            $no = PushAction::issue($user, (string) ($action['no'] ?? __('No')) ?: __('No'), ['kind' => 'automation', 'pending_id' => $pending->id, 'choice' => 'no'], $expires);
            $this->webPush->send($user, (string) ($action['title'] ?? $automation->name) ?: $automation->name, (string) ($action['body'] ?? ''), $yes['url'], urgent: true, actions: [
                ['label' => $yes['action']->label, 'url' => $yes['url']],
                ['label' => $no['action']->label, 'url' => $no['url']],
            ]);
        }

        return trans_choice(':count recipient|:count recipients', $recipients->count());
    }

    /**
     * The answer to an "ask by push": "yes" runs the waiting actions, "no" drops them. The first
     * answer counts; later ones (other members, second tap) change nothing.
     */
    public function answer(PushAction $pushAction, string $choice): string
    {
        $pending = AutomationPendingAction::whereNull('run_at')->with('automation.user')->find($pushAction->payload['pending_id'] ?? 0);
        PushAction::where('payload->pending_id', $pushAction->payload['pending_id'] ?? 0)->whereNull('answered_at')->update(['answered_at' => now()]);

        if (! $pending || ($pending->expires_at && $pending->expires_at->isPast())) {
            $pending?->delete();

            return __('This question has already been answered or has expired.');
        }

        $pending->delete();

        if ($choice !== 'yes' || ! $pending->automation?->active) {
            return __('OK, nothing happens.');
        }

        $run = $this->performAll($pending->automation, $pending->actions, __('Answered “:answer” by :name', ['answer' => $pushAction->label, 'name' => $pushAction->user->name]), $pending->depth);

        return $run->status === 'done' ? __('Done.') : __('Done with errors – see the automation.');
    }

    /**
     * Continues automations whose "wait" is over; drops questions nobody answered in time.
     */
    private function runPending(Carbon $now): void
    {
        AutomationPendingAction::whereNull('run_at')->where('expires_at', '<', $now)->delete();

        AutomationPendingAction::where('run_at', '<=', $now)->with('automation.user')->get()->each(function (AutomationPendingAction $pending) {
            $pending->delete();

            if ($pending->automation?->active) {
                $this->performAll($pending->automation, $pending->actions, __('Continued after waiting'), $pending->depth);
            }
        });
    }

    /**
     * Triggers at a moment that is not a fixed time: sunrise/sunset, before an appointment, when
     * a task is due.
     */
    private function checkMoment(Automation $automation, Carbon $now): bool
    {
        if (! $automation->user || ($automation->last_run_at && $automation->last_run_at->format('Y-m-d H:i') === $now->format('Y-m-d H:i'))) {
            return false;
        }

        $trigger = $automation->trigger;
        $minute = $now->format('Y-m-d H:i');
        $reason = null;

        if ($trigger['type'] === 'sun') {
            $at = self::sunTime($automation->user, $now, (string) ($trigger['event'] ?? 'sunset'));
            $reason = $at && $at->copy()->addMinutes((int) ($trigger['offset'] ?? 0))->format('Y-m-d H:i') === $minute ? AutomationCatalog::describe($trigger) : null;
        } elseif ($trigger['type'] === 'appointment') {
            $keyword = mb_strtolower(trim((string) ($trigger['keyword'] ?? '')));
            $events = CalendarEvents::between($automation->user, $automation->household_id, $now->copy()->startOfDay(), $now->copy()->addDay()->endOfDay(), array_keys(CalendarEvents::sources($automation->user)));

            foreach ($events as $event) {
                if ($event['time'] && ($keyword === '' || str_contains(mb_strtolower($event['title']), $keyword))
                    && Carbon::parse($event['date'].' '.$event['time'])->subMinutes((int) ($trigger['minutes'] ?? 30))->format('Y-m-d H:i') === $minute) {
                    $reason = __('Appointment soon: :title', ['title' => $event['title']]);

                    break;
                }
            }
        } elseif (in_array($trigger['type'], ['waste', 'deadline', 'birthday', 'expiry'], true)) {
            $reason = ($trigger['at'] ?? '') === $now->format('H:i') ? $this->dailyReason($automation, $automation->user, $now) : null;
        } else {
            $keyword = mb_strtolower(trim((string) ($trigger['keyword'] ?? '')));
            $task = Item::query()->forHousehold($automation->household_id)->visibleTo($automation->user)
                ->where('type', Item::TYPE_TODO)->where('done', false)->where('due_time', $now->format('H:i'))->with('collection.properties')->get()
                ->first(fn (Item $item) => $item->dueAt()?->format('Y-m-d H:i') === $minute && ($keyword === '' || str_contains(mb_strtolower($item->title), $keyword)));
            $reason = $task ? __('Task due: :title', ['title' => $task->title]) : null;
        }

        if ($reason === null) {
            return false;
        }

        $this->run($automation, $reason);

        return true;
    }

    /**
     * Once a day at the chosen time: waste collection tomorrow, a deadline, a birthday or a
     * best-before date exactly the chosen number of days ahead; null when there is none.
     */
    private function dailyReason(Automation $automation, User $user, Carbon $now): ?string
    {
        $trigger = $automation->trigger;
        $householdId = $automation->household_id;
        $days = max(0, min(365, (int) ($trigger['days'] ?? 0)));
        $target = $now->copy()->startOfDay()->addDays($days);
        $keyword = mb_strtolower(trim((string) ($trigger['keyword'] ?? '')));
        $matches = fn (string $text) => $keyword === '' || str_contains(mb_strtolower($text), $keyword);

        $titles = match ($trigger['type']) {
            'waste' => collect(CalendarEvents::between($user, $householdId, $now->copy()->addDay()->startOfDay(), $now->copy()->addDay()->endOfDay(), array_keys(CalendarEvents::sources($user))))
                ->pluck('title')->filter(fn ($title) => $matches((string) $title)),
            'deadline' => collect(DashboardWidgets::deadlines($user, $householdId, 200))
                ->filter(fn (array $row) => $row['days'] === $days && $matches($row['entry']->title.' '.$row['collection']->name))
                ->map(fn (array $row) => $row['entry']->title.' ('.$row['property']->name.')'),
            'birthday' => Contact::query()->visibleTo($user, $householdId)->whereNotNull('birthday')->get()
                ->filter(fn (Contact $contact) => $contact->nextBirthday()?->isSameDay($target))
                ->map(fn (Contact $contact) => $contact->name.($contact->turns() ? ' ('.$contact->turns().')' : '')),
            'expiry' => Pantry::expiring($user, $householdId, $days)->filter(fn (array $entry) => $entry['best_before']->isSameDay($target))->pluck('name'),
            default => collect(),
        };

        if ($titles->isEmpty()) {
            return null;
        }

        $list = $titles->unique()->take(5)->implode(', ');

        return match ($trigger['type']) {
            'waste' => __('Tomorrow: :titles', ['titles' => $list]),
            'deadline' => __('Deadline in :days days: :titles', ['days' => $days, 'titles' => $list]),
            'birthday' => __('Birthday in :days days: :titles', ['days' => $days, 'titles' => $list]),
            default => __('Best before in :days days: :titles', ['days' => $days, 'titles' => $list]),
        };
    }

    /**
     * Today's forecast at the member's weather place: chance of rain or highest/lowest temperature.
     */
    private function weatherValue(Automation $automation, string $metric): ?int
    {
        $user = $automation->user;

        if (! $user || $user->weather_latitude === null || $user->weather_longitude === null) {
            return null;
        }

        $today = Weather::today((float) $user->weather_latitude, (float) $user->weather_longitude);

        return $today ? ($today[$metric] ?? $today['rain']) : null;
    }

    /**
     * Whether a balance of the member's own accounts (name containing the word) meets the
     * comparison; null while no balance is known.
     */
    private function balanceHolds(Automation $automation): ?bool
    {
        $trigger = $automation->trigger;
        $name = mb_strtolower(trim((string) ($trigger['account'] ?? '')));
        $balances = BankAccount::where('household_id', $automation->household_id)->where('user_id', $automation->user_id)->get()
            ->filter(fn (BankAccount $account) => $name === '' || str_contains(mb_strtolower($account->name), $name))
            ->map(fn (BankAccount $account) => $account->currentBalance())
            ->filter(fn (?float $balance) => $balance !== null);

        if ($balances->isEmpty()) {
            return null;
        }

        return $balances->contains(fn (float $balance) => self::compare((string) $balance, (string) ($trigger['operator'] ?? 'lt'), (string) ($trigger['value'] ?? '0')));
    }

    /**
     * Sunrise or sunset of the day at the member's weather place (Profile), null without a place.
     */
    public static function sunTime(User $user, Carbon $day, string $event): ?Carbon
    {
        if ($user->weather_latitude === null || $user->weather_longitude === null) {
            return null;
        }

        $info = date_sun_info($day->copy()->setTime(12, 0)->getTimestamp(), (float) $user->weather_latitude, (float) $user->weather_longitude);
        $timestamp = $info[$event === 'sunrise' ? 'sunrise' : 'sunset'];

        return is_int($timestamp) ? Carbon::createFromTimestamp($timestamp, $day->getTimezone())->startOfMinute() : null;
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
            'evcc' => ($value = $this->evccValue($automation->household_id, (string) ($trigger['metric'] ?? 'pv'), (int) ($trigger['loadpoint'] ?? 1))) === null ? null : self::compare((string) $value, (string) ($trigger['operator'] ?? 'gt'), (string) ($trigger['value'] ?? '0')),
            'budget' => $automation->user ? collect(Budgets::status($automation->user, $automation->household_id, $now))->contains(fn (array $budget) => $budget['percent'] >= (float) ($trigger['percent'] ?? 100)) : null,
            'presence' => self::presence($automation->household_id, (string) ($trigger['state'] ?? 'all_away')),
            'entity_for' => $this->heldFor($automation->household_id, (string) ($trigger['entity_id'] ?? ''), (string) ($trigger['value'] ?? ''), (int) ($trigger['minutes'] ?? 10), $now),
            'weather' => ($forecast = $this->weatherValue($automation, (string) ($trigger['metric'] ?? 'rain'))) === null ? null : self::compare((string) $forecast, (string) ($trigger['operator'] ?? 'gt'), (string) ($trigger['value'] ?? '0')),
            'balance' => $this->balanceHolds($automation),
            default => null,
        };
    }

    /**
     * Whether an entity has had the value for at least the minutes ("door open for 10 minutes").
     */
    private function heldFor(int $householdId, string $entityId, string $value, int $minutes, Carbon $now): ?bool
    {
        $state = EntityStates::get($householdId, $entityId);

        if ($state === null) {
            return null;
        }

        $since = EntityStates::since($householdId, $entityId);

        return self::compare($state, 'eq', $value) && $since !== null && $since->lte($now->copy()->subMinutes(max(1, $minutes)));
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
            'sun' => $automation->user !== null && self::isDark($automation->user, $now) === (($condition['state'] ?? 'dark') === 'dark'),
            'presence' => (bool) self::presence($automation->household_id, (string) ($condition['state'] ?? 'all_away')),
            'calendar' => $automation->user !== null && CalendarMatcher::active($automation->user, $automation->household_id, (string) ($condition['keyword'] ?? ''), (array) ($condition['sources'] ?? [CalendarMatcher::CALENDAR]), $now) === (bool) ($condition['active'] ?? true),
            'weather' => ($forecast = $this->weatherValue($automation, (string) ($condition['metric'] ?? 'rain'))) !== null && self::compare((string) $forecast, (string) ($condition['operator'] ?? 'gt'), (string) ($condition['value'] ?? '0')),
            'any' => ($group = array_filter((array) ($condition['conditions'] ?? []), 'is_array')) === [] || collect($group)->contains(fn (array $inner) => $this->conditionHolds($automation, $inner, $now)),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function perform(Automation $automation, array $action, int $depth, string $reason): ?string
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
            case 'evcc_plan':
                [$hour, $minute] = array_map('intval', explode(':', (string) ($action['time'] ?? '07:00')) + [1 => 0]);
                $at = now()->setTime($hour, $minute);
                $at = $at->isPast() ? $at->addDay() : $at;
                $this->connection($householdId, SmartHomeConnection::EVCC)->evcc()->setPlan((string) ($action['vehicle'] ?? ''), (int) ($action['soc'] ?? 80), $at);

                return $at->format('d.m. H:i');
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
            case 'event':
                $date = match ($action['day'] ?? 'today') {
                    'tomorrow' => today()->addDay(),
                    'week' => today()->addWeek(),
                    default => today(),
                };
                $event = $automation->user ? QuickCapture::task($automation->user, $householdId, (string) ($action['title'] ?? ''), $date->toDateString()) : null;

                if ($event && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($action['time'] ?? ''))) {
                    $event->forceFill(['due_time' => $action['time']])->save();
                }

                return $event ? '„'.$event->title.'“ '.$date->format('d.m.') : null;
            case 'shopping':
                $list = ShoppingList::defaultFor($householdId);
                $items = array_slice(array_values(array_filter(array_map('trim', explode(',', (string) ($action['items'] ?? ''))))), 0, 20);

                foreach ($items as $item) {
                    ShoppingLists::add($list, mb_substr($item, 0, 120), null, $automation->user_id);
                }

                return __(':item is on the list “:list”.', ['item' => implode(', ', $items), 'list' => $list->name]);
            case 'email':
                $recipients = ($action['to'] ?? 'me') === 'household' ? $this->members($householdId) : collect([$automation->user])->filter();
                $subject = (string) ($action['subject'] ?? '') ?: $automation->name;
                $recipients->each(fn (User $user) => Mail::raw((string) ($action['body'] ?? ''), fn ($message) => $message->to($user->email)->subject($subject)));

                return trans_choice(':count recipient|:count recipients', $recipients->count());
            case 'tts':
                $this->connection($householdId, SmartHomeConnection::HOME_ASSISTANT)->homeAssistant()->callService('tts', 'speak', [
                    'entity_id' => (string) ($action['engine'] ?? '') ?: 'tts.home_assistant_cloud',
                    'media_player_entity_id' => (string) ($action['media_player'] ?? ''),
                    'message' => (string) ($action['message'] ?? ''),
                ]);

                return null;
            case 'scene_snapshot':
                $scene = Str::slug((string) ($action['scene'] ?? ''), '_') ?: 'e11_snapshot';
                $client = $this->connection($householdId, SmartHomeConnection::HOME_ASSISTANT)->homeAssistant();

                if (($action['mode'] ?? 'save') === 'restore') {
                    $client->callService('scene', 'turn_on', ['entity_id' => 'scene.'.$scene]);
                } else {
                    $client->callService('scene', 'create', ['scene_id' => $scene, 'snapshot_entities' => array_values(array_filter(array_map('trim', explode(',', (string) ($action['entities'] ?? '')))))]);
                }

                return null;
            case 'webhook':
                $url = (string) ($action['url'] ?? '');

                if (! WebDavClient::isAllowedUrl($url)) {
                    throw new \RuntimeException(__('This address is not allowed. Use an address in the home network that goes through the VPN, or a public address.'));
                }

                $response = Http::timeout(10)->withOptions(['allow_redirects' => false] + Vpn::httpOptions($url))
                    ->post($url, ['automation' => $automation->name, 'reason' => $reason, 'message' => (string) ($action['body'] ?? ''), 'time' => now()->toIso8601String()]);

                if (! $response->successful()) {
                    throw new \RuntimeException(__('The webhook answered with status :status.', ['status' => $response->status()]));
                }

                return (string) $response->status();
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

    private function evccValue(int $householdId, string $metric, int $loadpoint = 1): ?float
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

        $point = $state['loadpoints'][max(0, $loadpoint - 1)] ?? [];

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

    /**
     * Who is at home, from the person/device tracker entities picked under Smart home; null
     * when no such entity is known.
     */
    public static function presence(int $householdId, string $state): ?bool
    {
        // People from Home Assistant (person/device_tracker) and members whose app reports at home / away.
        $people = SmartHomeEntity::where('household_id', $householdId)->get()
            ->filter(fn (SmartHomeEntity $entity) => in_array($entity->domain(), ['person', 'device_tracker'], true))
            ->map(fn (SmartHomeEntity $entity) => (EntityStates::get($householdId, $entity->entity_id) ?? $entity->state) === 'home')
            ->values()
            ->concat(User::whereIn('id', HouseholdMember::where('household_id', $householdId)->select('user_id'))
                ->whereNotNull('presence')->where('presence_at', '>=', now()->subDays(7))->pluck('presence')
                ->map(fn (string $presence) => $presence === 'home'));

        if ($people->isEmpty()) {
            return null;
        }

        $home = $people->filter()->count();

        return match ($state) {
            'anyone_home' => $home > 0,
            'all_home' => $home === $people->count(),
            default => $home === 0,
        };
    }

    public static function isDark(User $user, Carbon $now): bool
    {
        $sunrise = self::sunTime($user, $now, 'sunrise');
        $sunset = self::sunTime($user, $now, 'sunset');

        return $sunrise && $sunset ? $now->lt($sunrise) || $now->gte($sunset) : ($now->hour < 7 || $now->hour >= 19);
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
