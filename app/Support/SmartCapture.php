<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\HouseholdMode;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\SmartHomeEntity;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Automation\AutomationEngine;
use App\Support\Finance\Categorizer;
use App\Support\SmartHome\EntityControl;
use Illuminate\Support\Str;

/**
 * One input for everything: decides from the text what it is and files it there.
 *
 * - "Einkauf: Milch, Brot" / "kaufen Milch" → shopping list (several items by comma)
 * - "50 € Tanken" / "Tanken 49,90 €" → expense in Finances (cash wallet, category guessed)
 * - "Licht Wohnzimmer an", "Urlaubsmodus aus" → Smart home device or household mode
 * - text with a date or time ("Zahnarzt Do 10 Uhr") → task with date and time (in the calendar)
 * - anything else → inbox
 */
class SmartCapture
{
    /**
     * What the text would become, without saving anything (for the hint under the field).
     *
     * @return array{kind: string, label: string}
     */
    public static function preview(User $user, int $householdId, string $text): array
    {
        $kind = self::detect($user, $householdId, $text)['kind'];

        return ['kind' => $kind, 'label' => self::labels()[$kind]];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'shopping' => '🛒 '.__('Shopping list'),
            'expense' => '💶 '.__('Expense'),
            'device' => '💡 '.__('Switch device'),
            'mode' => '🏷️ '.__('Switch mode'),
            'task' => '✅ '.__('Task / appointment'),
            'inbox' => '📥 '.__('Inbox'),
        ];
    }

    /**
     * Files the text and returns the message for the toast (null when nothing was left to save).
     */
    public static function capture(User $user, int $householdId, string $text, bool $private = false): ?string
    {
        return self::file($user, $householdId, $text, $private)['message'];
    }

    /**
     * Files the text: the message for the toast and what "Undo" removes or switches back.
     *
     * @return array{message: ?string, undo: ?array{kind: string, ids: list<int>}}
     */
    public static function file(User $user, int $householdId, string $text, bool $private = false): array
    {
        $detected = self::detect($user, $householdId, $text);
        $undo = null;

        $message = match ($detected['kind']) {
            'shopping' => self::addToShopping($user, $householdId, $detected['items'], $undo),
            'expense' => self::addExpense($user, $householdId, $detected['amount'], $detected['label'], $undo),
            'device' => self::switchDevice($user, $detected['entity'], $detected['on']),
            'mode' => self::switchMode($user, $detected['mode'], $detected['on'], $undo),
            'task' => ($task = QuickCapture::task($user, $householdId, $text, private: $private)) ? __('Task “:title” created.', ['title' => $task->title]) : null,
            default => ($task = QuickCapture::inbox($user, $householdId, $text, $private)) ? __('Saved to inbox.') : null,
        };

        if (in_array($detected['kind'], ['task', 'inbox'], true) && isset($task)) {
            $undo = ['kind' => 'item', 'ids' => [$task->id]];
        }

        return ['message' => $message, 'undo' => $undo];
    }

    /**
     * Takes back what file() did: removes the created entries (only the member's own) or
     * switches the mode back.
     *
     * @param  array{kind: string, ids: list<int>}  $undo
     */
    public static function undo(User $user, int $householdId, array $undo): void
    {
        $ids = array_map('intval', $undo['ids']);

        match ($undo['kind']) {
            'item' => Item::where('household_id', $householdId)->where('created_by', $user->id)->whereIn('id', $ids)->get()->each->delete(),
            'shopping' => ShoppingListEntry::where('household_id', $householdId)->whereIn('id', $ids)->delete(),
            'expense' => Transaction::where('household_id', $householdId)->where('user_id', $user->id)->whereIn('id', $ids)->delete(),
            'mode' => ($mode = HouseholdMode::where('household_id', $householdId)->find($ids[0] ?? 0))
                ? app(AutomationEngine::class)->setMode($householdId, $mode->key, ! $mode->active) : null,
            default => null,
        };
    }

    /**
     * @return array{kind: string, items?: list<string>, amount?: float, label?: string, entity?: SmartHomeEntity, mode?: HouseholdMode, on?: bool}
     */
    public static function detect(User $user, int $householdId, string $text): array
    {
        $text = trim($text);

        if (preg_match('/^(?:einkauf(?:en)?|kaufen|einkaufsliste|shopping|🛒)\s*[:\-]?\s+(.+)$/iu', $text, $match)) {
            $items = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\bund\b)\s*/u', $match[1]) ?: [])));

            return ['kind' => 'shopping', 'items' => $items];
        }

        if ($user->canUseAddon(Addons::FINANCE) && ($expense = self::parseExpense($text))) {
            return ['kind' => 'expense', ...$expense];
        }

        if (preg_match('/^(?:schalte\s+)?(.+?)\s+(an|ein|aus|on|off)$/iu', $text, $match)) {
            $name = mb_strtolower(trim($match[1]));
            $on = in_array(mb_strtolower($match[2]), ['an', 'ein', 'on'], true);

            if ($mode = HouseholdMode::forHousehold($householdId)->first(fn (HouseholdMode $mode) => in_array($name, [mb_strtolower($mode->name), mb_strtolower($mode->name).'modus', mb_strtolower($mode->key)], true))) {
                return ['kind' => 'mode', 'mode' => $mode, 'on' => $on];
            }

            if ($user->canUseAddon(Addons::SMART_HOME) && ($entity = self::findEntity($householdId, $name))) {
                return ['kind' => 'device', 'entity' => $entity, 'on' => $on];
            }
        }

        $parsed = TaskInput::parse(mb_substr($text, 0, 500));

        if ($parsed['due'] !== null || $parsed['time'] !== null || $parsed['repeat'] !== null) {
            return ['kind' => 'task'];
        }

        return ['kind' => 'inbox'];
    }

    /**
     * "50 € Tanken", "Tanken 49,90€", "12.50 EUR Bäcker".
     *
     * @return array{amount: float, label: string}|null
     */
    private static function parseExpense(string $text): ?array
    {
        $amount = '(\d{1,6}(?:[.,]\d{1,2})?)\s*(?:€|eur|euro)';

        if (preg_match('/^'.$amount.'\s+(.+)$/iu', $text, $match) || preg_match('/^(.+?)\s+'.$amount.'$/iu', $text, $reversed)) {
            [$value, $label] = isset($reversed) && $reversed !== [] ? [$reversed[2], $reversed[1]] : [$match[1], $match[2]];
            $number = (float) str_replace(',', '.', $value);

            return $number > 0 ? ['amount' => $number, 'label' => trim($label)] : null;
        }

        return null;
    }

    private static function findEntity(int $householdId, string $name): ?SmartHomeEntity
    {
        $entities = SmartHomeEntity::where('household_id', $householdId)->get();

        return $entities->first(fn (SmartHomeEntity $entity) => mb_strtolower($entity->name) === $name)
            ?? $entities->first(fn (SmartHomeEntity $entity) => ($entity->isSwitchable() || $entity->isRunnable()) && Str::contains(mb_strtolower($entity->name.' '.$entity->area), explode(' ', $name), true) && collect(explode(' ', $name))->every(fn (string $word) => Str::contains(mb_strtolower($entity->name.' '.$entity->area), $word)));
    }

    /**
     * @param  list<string>  $items
     */
    private static function addToShopping(User $user, int $householdId, array $items, ?array &$undo): ?string
    {
        if ($items === []) {
            return null;
        }

        $list = ShoppingList::defaultFor($householdId);
        $ids = [];

        foreach ($items as $item) {
            $entry = ShoppingLists::add($list, mb_substr($item, 0, 120), null, $user->id);

            // Items already on the list stay there on "Undo".
            if ($entry->wasRecentlyCreated) {
                $ids[] = $entry->id;
            }
        }

        $undo = ['kind' => 'shopping', 'ids' => $ids];

        return __(':item is on the list “:list”.', ['item' => implode(', ', $items), 'list' => $list->name]);
    }

    /**
     * @param-out array{kind: string, ids: list<int>} $undo
     */
    private static function addExpense(User $user, int $householdId, float $amount, string $label, ?array &$undo): string
    {
        $account = BankAccount::firstOrCreate(['user_id' => $user->id, 'household_id' => $householdId, 'name' => __('Cash wallet')]);

        $transaction = Transaction::create([
            'household_id' => $householdId,
            'user_id' => $user->id,
            'bank_account_id' => $account->id,
            'booked_on' => today()->toDateString(),
            'amount' => -$amount,
            'counterparty' => mb_substr($label, 0, 255),
            'purpose' => '',
            'category' => (new Categorizer($user->id))->categorize($label, null, -$amount),
            'import_hash' => sha1('capture|'.Str::uuid()),
        ]);
        $undo = ['kind' => 'expense', 'ids' => [$transaction->id]];

        return __('Expense of :amount for “:label” saved.', ['amount' => number_format($amount, 2, ',', '.').' €', 'label' => $label]);
    }

    private static function switchDevice(User $user, SmartHomeEntity $entity, bool $on): string
    {
        if (EntityControl::isCritical($entity)) {
            return __(':name needs a confirmation – please switch it on the Smart home page.', ['name' => $entity->name]);
        }

        if (! EntityControl::mayControl($user, $entity->household_id)) {
            return __('You may only view the smart home devices.');
        }

        $entity->loadMissing('connection');
        $error = EntityControl::attempt(function () use ($user, $entity, $on) {
            if ($entity->isRunnable()) {
                EntityControl::press($user, $entity);
            } else {
                $entity->connection->homeAssistant()->callService('homeassistant', $on ? 'turn_on' : 'turn_off', ['entity_id' => $entity->entity_id]);
            }
        });

        return $error ?? ($on ? __(':name switched on.', ['name' => $entity->name]) : __(':name switched off.', ['name' => $entity->name]));
    }

    /**
     * @param-out array{kind: string, ids: list<int>} $undo
     */
    private static function switchMode(User $user, HouseholdMode $mode, bool $on, ?array &$undo): string
    {
        app(AutomationEngine::class)->setMode($mode->household_id, $mode->key, $on);
        $undo = ['kind' => 'mode', 'ids' => [$mode->id]];

        return $on ? __(':mode is on.', ['mode' => $mode->name]) : __(':mode is off.', ['mode' => $mode->name]);
    }
}
