<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ApiToken;
use App\Models\Collection;
use App\Models\FinanceBudget;
use App\Models\Household;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CalendarEvents;
use App\Support\Finance\Budgets;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\NetWorth;
use App\Support\InAppNotifications;
use App\Support\QuickCapture;
use App\Support\ShoppingLists;
use App\Support\SmartCapture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Mobile API for the everyday modules: tasks, calendar, notifications, shopping list and net worth.
 */
class LifeController extends ApiController
{
    private const MAX_CALENDAR_DAYS = 62;

    /**
     * Open tasks; ?filter=today (due today or overdue), upcoming (next 7 days) or all.
     */
    public function tasks(Request $request): JsonResponse
    {
        $household = $this->household();
        $collection = Collection::tasksFor($household->id)->load('properties');
        $due = $collection->systemProperty('due');
        $filter = (string) $request->query('filter', 'all');
        $today = today()->toDateString();
        $week = today()->addDays(7)->toDateString();

        $tasks = Item::query()->forHousehold($household->id)->visibleTo($this->user())
            ->where('collection_id', $collection->id)->where('done', false)->orderBy('position')->limit(500)->get()
            ->map(fn (Item $task) => ['task' => $task, 'due' => $due ? ((string) $task->propertyValue($due) ?: null) : null])
            ->filter(fn (array $row) => match ($filter) {
                'today' => $row['due'] !== null && $row['due'] <= $today,
                'upcoming' => $row['due'] !== null && $row['due'] > $today && $row['due'] <= $week,
                default => true,
            })
            ->sortBy(fn (array $row) => $row['due'] ?? '9999-99-99')
            ->values()
            ->map(fn (array $row) => $this->taskData($row['task'], $row['due']));

        return $this->ok($tasks);
    }

    /**
     * Adds a task from one line of natural language ("Call dentist tomorrow 10:00 !high").
     */
    public function storeTask(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $task = QuickCapture::task($this->user(), $this->household()->id, $data['text']);
        abort_if($task === null, 422, __('Please enter a title.'));

        $due = Collection::tasksFor($task->household_id)->systemProperty('due');

        return $this->ok($this->taskData($task, $due ? ((string) $task->propertyValue($due) ?: null) : null), 201);
    }

    public function toggleTask(int $task): JsonResponse
    {
        $household = $this->household();
        $item = Item::query()->forHousehold($household->id)->visibleTo($this->user())
            ->where('collection_id', Collection::tasksFor($household->id)->id)->findOrFail($task);
        $item->update(['done' => ! $item->done]);

        return $this->ok(['id' => $item->id, 'done' => $item->done]);
    }

    /**
     * Calendar events between ?from and ?to (at most two months), from all modules the member may use.
     */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = Carbon::parse($data['from'] ?? today()->toDateString());
        $to = Carbon::parse($data['to'] ?? $from->copy()->addDays(13)->toDateString());
        abort_if($from->diffInDays($to) > self::MAX_CALENDAR_DAYS, 422, __('Please choose at most two months.'));

        $user = $this->user();
        $events = CalendarEvents::between($user, $this->household()->id, $from, $to, array_keys(CalendarEvents::sources($user)));

        return $this->ok(array_map(fn (array $event) => [
            'date' => $event['date'],
            'end' => $event['end'],
            'time' => $event['time'],
            'source' => $event['source'],
            'title' => $event['title'],
            'url' => $event['url'],
            'task_id' => $event['task_id'],
            'overdue' => $event['overdue'],
        ], $events));
    }

    public function notifications(): JsonResponse
    {
        $user = $this->user();

        return $this->ok([
            'unread' => InAppNotifications::unreadCount($user),
            'items' => $user->notifications()->latest()->limit(50)->get()->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? '',
                'body' => $notification->data['body'] ?? '',
                'url' => $notification->data['url'] ?? null,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function readNotifications(): JsonResponse
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok();
    }

    /**
     * The household's first shopping list with its open and checked items.
     */
    public function shopping(): JsonResponse
    {
        $list = ShoppingList::defaultFor($this->household()->id);

        return $this->ok([
            'id' => $list->id,
            'name' => $list->name,
            'items' => ShoppingListEntry::where('shopping_list_id', $list->id)->orderBy('checked')->orderBy('position')->get()
                ->map(fn (ShoppingListEntry $entry) => $this->entryData($entry)),
        ]);
    }

    public function storeShopping(Request $request): JsonResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:120'], 'quantity' => ['nullable', 'string', 'max:40']]);
        $entry = ShoppingLists::add(ShoppingList::defaultFor($this->household()->id), trim($data['label']), $data['quantity'] ?? null, $this->user()->id);

        return $this->ok($this->entryData($entry), 201);
    }

    /**
     * One dictated line to the shopping list, e.g. from a Siri shortcut: "2 Liter Milch, Butter
     * und 6 Eier". Several items at once, each with an optional leading quantity.
     */
    public function quickShopping(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:500']]);
        $list = ShoppingList::defaultFor($this->household()->id);
        $entries = ShoppingLists::addFromText($list, $data['text'], $this->user()->id);

        return $this->ok([
            'added' => count($entries),
            'list' => $list->name,
            'items' => array_map(fn (ShoppingListEntry $entry) => $this->entryData($entry), $entries),
        ], 201);
    }

    /**
     * One dictated line, authenticated by a token in the query, so an iOS shortcut only needs a
     * single "Get Contents of URL" action (no headers, no JSON body). ?type =
     * shopping|task|note|inbox, or "auto" to pick the target from a leading keyword ("Aufgabe …",
     * "Notiz …", "Einkauf …") so a single shortcut can reach every target.
     */
    public function quickViaToken(Request $request, string $type): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'text' => ['required', 'string', 'max:500'],
        ]);

        $token = ApiToken::findByPlain($data['token']);

        if ($token?->isExpired()) {
            $token->delete();
            $token = null;
        }

        $user = $token?->user;
        abort_unless($user instanceof User, 401, __('Please sign in again.'));

        $token->forceFill(['last_used_at' => now()])->saveQuietly();
        Auth::setUser($user);
        $household = $user->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
        $text = trim($data['text']);

        if ($type === 'auto') {
            [$type, $text] = $this->routeByKeyword($text);
        }

        return match ($type) {
            'shopping' => $this->ok((function () use ($household, $text, $user) {
                $entries = ShoppingLists::addFromText(ShoppingList::defaultFor($household->id), $text, $user->id);

                return ['added' => count($entries), 'message' => trans_choice(':count item added to the shopping list.|:count items added to the shopping list.', count($entries))];
            })(), 201),
            'task' => $this->ok((function () use ($household, $text, $user) {
                $task = QuickCapture::task($user, $household->id, $text);
                abort_if($task === null, 422, __('Please enter a title.'));

                return ['message' => __('Task added: :title', ['title' => $task->title])];
            })(), 201),
            'note' => $this->ok((function () use ($household, $text, $user) {
                $note = QuickCapture::note($user, $household->id, $text);
                abort_if($note === null, 422, __('Please enter a title.'));

                return ['message' => __('Note added: :title', ['title' => $note->title])];
            })(), 201),
            'inbox' => $this->ok(['message' => SmartCapture::capture($user, $household->id, $text) ?? __('Captured.')], 201),
            default => abort(404),
        };
    }

    /**
     * Picks the target from a leading keyword and strips it from the text. Without a keyword the
     * dictation goes to the shopping list (the most common everyday case).
     *
     * @return array{0: string, 1: string} The resolved type and the remaining text.
     */
    private function routeByKeyword(string $text): array
    {
        if (preg_match('/^\s*(?<keyword>aufgabe|todo|to-?do|task|notiz(?:en)?|note|idee|inbox|merken?|einkauf(?:en|sliste)?|kaufen|shopping)\b\s*[:\-]?\s*(?<rest>.*)$/isu', $text, $match)) {
            $type = match (mb_strtolower($match['keyword'])) {
                'aufgabe', 'todo', 'to-do', 'task' => 'task',
                'notiz', 'notizen', 'note' => 'note',
                'idee', 'inbox', 'merke', 'merken' => 'inbox',
                default => 'shopping',
            };

            return [$type, trim($match['rest'])];
        }

        return ['shopping', $text];
    }

    public function toggleShopping(int $entry): JsonResponse
    {
        $item = ShoppingListEntry::where('household_id', $this->household()->id)->findOrFail($entry);
        $item->update(['checked' => ! $item->checked]);

        return $this->ok($this->entryData($item));
    }

    public function netWorth(): JsonResponse
    {
        $netWorth = NetWorth::for($this->user());

        return $this->ok([
            'assets' => $netWorth['assets'],
            'liabilities' => $netWorth['liabilities'],
            'net' => $netWorth['net'],
            'groups' => array_map(fn (array $group) => [
                'key' => $group['key'],
                'label' => $group['label'],
                'liability' => $group['liability'],
                'total' => $group['total'],
                'rows' => array_map(fn (array $row) => ['label' => $row['label'], 'value' => $row['value'], 'hint' => $row['hint']], $group['rows']),
            ], $netWorth['groups']),
        ]);
    }

    /**
     * Finance overview: this month's income/expenses, budget status and the most recent bookings.
     */
    public function finances(): JsonResponse
    {
        $user = $this->user();
        $household = $this->household();
        $month = Carbon::now()->startOfMonth();

        $monthTx = Transaction::query()
            ->where('household_id', $household->id)
            ->whereBetween('booked_on', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])
            ->get();
        $income = round((float) $monthTx->where('amount', '>', 0)->sum('amount'), 2);
        $expenses = round(abs((float) $monthTx->where('amount', '<', 0)->sum('amount')), 2);

        $budgets = [];
        foreach (Budgets::status($user, $household->id, $month) as $row) {
            /** @var array<string, mixed> $row */
            $rawBudget = $row['budget'] ?? null;
            $budget = $rawBudget instanceof FinanceBudget ? $rawBudget : null;

            $budgets[] = [
                'category' => $budget?->category,
                'label' => $row['label'],
                'icon' => $row['icon'],
                'spent' => round((float) $row['spent'], 2),
                'limit' => round((float) $row['limit'], 2),
                'available' => round((float) $row['available'], 2),
                'remaining' => round((float) $row['remaining'], 2),
                'percent' => (float) $row['percent'],
            ];
        }

        $transactions = Transaction::query()
            ->where('household_id', $household->id)
            ->with('account')
            ->orderByDesc('booked_on')
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'booked_on' => $transaction->booked_on->toDateString(),
                'amount' => round((float) $transaction->amount, 2),
                'counterparty' => $transaction->counterparty,
                'purpose' => $transaction->purpose,
                'category' => $transaction->category,
                'category_label' => $transaction->category !== null ? FinanceCategories::label($transaction->category) : null,
                'account' => $transaction->account?->name,
            ])
            ->values()
            ->all();

        return $this->ok([
            'month' => $month->format('Y-m'),
            'income' => $income,
            'expenses' => $expenses,
            'balance' => round($income - $expenses, 2),
            'budgets' => $budgets,
            'transactions' => $transactions,
        ]);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array{id: int, title: string, due: ?string, time: ?string, done: bool, url: string}
     */
    private function taskData(Item $task, ?string $due): array
    {
        return ['id' => $task->id, 'title' => $task->title, 'due' => $due, 'time' => $task->due_time, 'done' => (bool) $task->done, 'url' => route('tasks.index', ['task' => $task->id])];
    }

    /**
     * @return array{id: int, label: string, quantity: ?string, aisle: ?string, checked: bool}
     */
    private function entryData(ShoppingListEntry $entry): array
    {
        return ['id' => $entry->id, 'label' => $entry->label, 'quantity' => $entry->quantity, 'aisle' => $entry->aisle, 'checked' => (bool) $entry->checked];
    }
}
