<?php

namespace App\Support\Finance;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CollectionTemplates;
use App\Support\ContractOverview;
use Illuminate\Support\Carbon;

/**
 * Compares recurring payments from the bank bookings with the contracts: payments without a
 * contract (adoptable in one click), contracts without a payment and differing amounts.
 */
class SubscriptionMatcher
{
    /** Bookings of this many days are searched. */
    public const HISTORY_DAYS = 400;

    /** Categories that are no contracts. */
    private const IGNORED_CATEGORIES = ['savings', FinanceCategories::TRANSFER, 'cash', 'salary', 'refunds', FinanceCategories::OTHER_INCOME];

    /** Words too common to link a payment to a contract. */
    private const STOP_WORDS = ['gmbh', 'ag', 'se', 'kg', 'co', 'mbh', 'ev', 'und', 'der', 'die', 'das', 'sepa', 'lastschrift', 'online', 'service', 'services', 'deutschland', 'germany', 'europe', 'international', 'versicherung', 'versicherungen', 'bank', 'kasse', 'beitrag', 'rechnung', 'vertrag', 'kunde', 'kundennummer', 'monat', 'abo'];

    /** Finance category → option of the "Category" field of the contracts template. */
    private const TEMPLATE_CATEGORIES = ['insurance' => 'Insurance', 'energy' => 'Energy', 'telecom' => 'Internet & phone', 'subscriptions' => 'Subscriptions', 'mobility' => 'Mobility'];

    /**
     * @return array{
     *     matched: list<array{contract: array<string, mixed>, payment: array<string, mixed>, difference: ?float}>,
     *     unmatched: list<array<string, mixed>>,
     *     without_payment: list<array<string, mixed>>,
     *     has_bookings: bool
     * }
     */
    public static function for(User $user, int $householdId): array
    {
        $transactions = Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('booked_on', '>=', today()->subDays(self::HISTORY_DAYS)->toDateString())
            ->where('amount', '<', 0)->whereNotIn('category', self::IGNORED_CATEGORIES)->get();
        $payments = array_values(array_filter(FinanceReport::recurring($transactions), fn (array $payment) => self::isActive($payment)));
        $contracts = ContractOverview::for($user, $householdId)['contracts'];
        $dismissed = $user->dismissed_subscriptions ?? [];

        $matched = [];
        $matchedContracts = [];
        $unmatched = [];

        foreach ($payments as $payment) {
            $paymentTokens = self::tokens($payment['name']);
            // A payment linked by hand wins over the match by name.
            $index = collect($contracts)->search(fn (array $contract) => in_array($payment['key'], self::linkedKeys($contract['item']), true));
            $index = $index !== false ? $index : collect($contracts)->search(fn (array $contract) => array_intersect(self::tokens($contract['item']->title.' '.$contract['provider']), $paymentTokens) !== []);

            if ($index === false) {
                if (! in_array($payment['key'], $dismissed, true)) {
                    $unmatched[] = $payment;
                }

                continue;
            }

            $contract = $contracts[$index];
            $matchedContracts[$index] = true;
            $difference = round($payment['monthly'] - $contract['monthly'], 2);
            $matched[] = [
                'contract' => $contract,
                'payment' => $payment,
                'difference' => $contract['monthly'] > 0 && abs($difference) >= max(0.5, $contract['monthly'] * 0.03) ? $difference : null,
            ];
        }

        $withoutPayment = array_values(array_filter($contracts, fn (array $contract, int $index) => $contract['monthly'] > 0 && ! isset($matchedContracts[$index]), ARRAY_FILTER_USE_BOTH));

        return ['matched' => $matched, 'unmatched' => $unmatched, 'without_payment' => $withoutPayment, 'has_bookings' => $transactions->isNotEmpty()];
    }

    /**
     * Every payee of the debits in the last months, not only the regular ones: the newest first.
     * Regular payments carry their monthly amount; for the others "monthly" is the last amount
     * and "frequency" is null (e.g. a yearly premium paid only once so far).
     *
     * @return list<array{key: string, name: string, category: ?string, frequency: ?string, amount: float, monthly: float, lastDate: Carbon, count: int}>
     */
    public static function payees(User $user, int $householdId, int $limit = 150): array
    {
        $transactions = Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('booked_on', '>=', today()->subDays(self::HISTORY_DAYS)->toDateString())
            ->where('amount', '<', 0)->whereNotIn('category', self::IGNORED_CATEGORIES)->get();
        $recurring = collect(FinanceReport::recurring($transactions))->keyBy('key');

        return $transactions->groupBy(fn (Transaction $transaction) => FinanceReport::payeeKey($transaction->counterparty, $transaction->purpose))
            ->map(function ($group, string $key) use ($recurring) {
                $last = $group->sortBy('booked_on')->last();
                $regular = $recurring->get($key);

                return [
                    'key' => $key,
                    'name' => (string) ($last->counterparty ?: $last->purpose ?: $key),
                    'category' => $last->category,
                    'frequency' => $regular['frequency'] ?? null,
                    'amount' => round(-$last->amount, 2),
                    'monthly' => (float) ($regular['monthly'] ?? round(-$last->amount, 2)),
                    'lastDate' => $last->booked_on,
                    'count' => $group->count(),
                ];
            })
            ->filter(fn (array $payee, string $key) => $key !== '')
            ->sortByDesc(fn (array $payee) => $payee['lastDate']->toDateString())
            ->take($limit)->values()->all();
    }

    /**
     * Creates a contract entry for a recurring payment in the user's contracts collection
     * (a private one is created from the template if there is none yet).
     *
     * @param  array<string, mixed>  $payment
     */
    public static function adopt(User $user, int $householdId, array $payment): Item
    {
        $collection = self::contractCollection($user, $householdId);
        $item = new Item([
            'household_id' => $householdId,
            'collection_id' => $collection->id,
            'type' => Item::TYPE_ENTRY,
            'visibility' => $collection->entryVisibility(),
            'title' => mb_substr($payment['name'], 0, 255),
            'created_by' => $user->id,
            'position' => (int) Item::where('collection_id', $collection->id)->max('position') + 1,
        ]);

        if ($provider = ContractOverview::property($collection, ['Provider'])) {
            $item->setPropertyValue($provider, mb_substr($payment['name'], 0, 255));
        }

        if ($cost = self::costProperty($collection)) {
            $item->setPropertyValue($cost, self::costValue($cost, $payment['monthly']));
        }

        $category = ContractOverview::property($collection, ['Category']);
        $optionName = self::TEMPLATE_CATEGORIES[$payment['category']] ?? 'Other';
        $option = $category ? collect($category->selectOptions())->first(fn (array $option) => in_array(mb_strtolower($option['name']), [mb_strtolower($optionName), mb_strtolower(__($optionName))], true)) : null;

        if ($option) {
            $item->setPropertyValue($category, $option['id']);
        }

        $item->save();

        return $item;
    }

    /**
     * Sets the cost of a contract entry to the monthly amount actually paid.
     */
    public static function updateCost(Item $item, float $monthly): void
    {
        $cost = self::costProperty($item->collection()->with('properties')->first());

        if ($cost) {
            $item->setPropertyValue($cost, self::costValue($cost, $monthly));
            $item->save();
        }
    }

    /**
     * Links a recurring payment (its key) to a contract, e.g. the monthly debit of an insurance.
     */
    public static function link(Item $contract, string $paymentKey): void
    {
        $content = $contract->content ?? [];
        $content['payment_keys'] = array_values(array_unique([...self::linkedKeys($contract), $paymentKey]));
        $contract->update(['content' => $content]);
    }

    public static function unlink(Item $contract, string $paymentKey): void
    {
        $content = $contract->content ?? [];
        $content['payment_keys'] = array_values(array_diff(self::linkedKeys($contract), [$paymentKey]));
        $contract->update(['content' => $content]);
    }

    /**
     * @return list<string>
     */
    public static function linkedKeys(Item $contract): array
    {
        return array_values(array_filter((array) ($contract->content['payment_keys'] ?? []), 'is_string'));
    }

    /**
     * The contracts collection new contracts go into (a private one is created from the template if needed).
     */
    public static function contractCollection(User $user, int $householdId): Collection
    {
        $existing = Collection::query()->visibleTo($user, $householdId)->whereNull('system_key')->with('properties')->get()
            ->first(fn (Collection $collection) => ContractOverview::property($collection, ['Provider']) !== null && self::costProperty($collection) !== null);

        if ($existing) {
            return $existing;
        }

        $collection = Collection::create([
            'household_id' => $householdId,
            'name' => __('Contracts & documents'),
            'icon' => CollectionTemplates::all()['contracts']['icon'],
            'visibility' => Item::VISIBILITY_PRIVATE,
            'created_by' => $user->id,
        ]);
        CollectionTemplates::apply($collection, 'contracts');

        return $collection->load('properties');
    }

    private static function costProperty(?Collection $collection): ?CollectionProperty
    {
        return $collection?->properties->first(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_NUMBER && ContractOverview::isCost($property->name));
    }

    private static function costValue(CollectionProperty $cost, float $monthly): float
    {
        return round(preg_match('/jähr|jahr|year|annual/i', $cost->name) ? $monthly * 12 : $monthly, 2);
    }

    /**
     * A payment that missed two of its dates has probably ended.
     *
     * @param  array<string, mixed>  $payment
     */
    private static function isActive(array $payment): bool
    {
        $days = ['weekly' => 7, 'monthly' => 31, 'bimonthly' => 62, 'quarterly' => 92, 'halfyearly' => 184, 'yearly' => 366][$payment['frequency']] ?? 31;

        return $payment['lastDate']->gte(today()->subDays(2 * $days));
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 3 && ! ctype_digit($word) && ! in_array($word, self::STOP_WORDS, true))));
    }
}
