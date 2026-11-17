<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankAccount;
use App\Models\ExternalLink;
use App\Models\FinanceRule;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\Categorizer;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinanceReceiptAi;
use App\Support\Finance\FinanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Mobile API for the household bookings: browse and filter them, add cash expenses (also from a
 * receipt photo), keep the accounts, and the remembered categorisation rules.
 */
class FinanceExtrasController extends ApiController
{
    private const PER_PAGE = 40;

    public function bookings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'regex:/^(\d{4}-\d{2}|all)$/'],
            'account' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:40'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $household = $this->user()->currentHousehold() ?? abort(404);
        $month = $data['month'] ?? now()->format('Y-m');
        $term = mb_strtolower(trim((string) ($data['q'] ?? '')));

        $query = Transaction::where('household_id', $household->id)
            ->when($month !== 'all', fn ($builder) => $builder->whereBetween('booked_on', [Carbon::parse($month.'-01')->toDateString(), Carbon::parse($month.'-01')->endOfMonth()->toDateString()]))
            ->when(! empty($data['account']), fn ($builder) => $builder->where('bank_account_id', $data['account']))
            ->when(! empty($data['category']), fn ($builder) => $builder->where('category', $data['category']))
            ->when($term !== '', fn ($builder) => $builder->where(fn ($inner) => $inner->whereRaw('LOWER(counterparty) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.$term.'%'])));

        $income = round((float) (clone $query)->where('amount', '>', 0)->sum('amount'), 2);
        $expenses = round(abs((float) (clone $query)->where('amount', '<', 0)->sum('amount')), 2);
        $page = $query->with('account:id,name')->orderByDesc('booked_on')->orderByDesc('id')->paginate(self::PER_PAGE);

        return $this->ok([
            'items' => collect($page->items())->map(fn (Transaction $transaction): array => $this->present($transaction))->all(),
            'has_more' => $page->hasMorePages(),
            'income' => $income,
            'expenses' => $expenses,
            'accounts' => $this->accounts($household->id),
            'categories' => collect(FinanceCategories::all())->map(fn (array $category, string $key): array => ['key' => $key, 'label' => $category['label'], 'icon' => $category['icon'], 'group' => $category['group']])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        $data = $request->validate([
            'booked_on' => ['required', 'date'],
            'counterparty' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'category' => ['required', Rule::in(array_keys(FinanceCategories::all()))],
            'account' => ['nullable', 'integer'],
            'mode' => ['nullable', Rule::in(['assign', 'new'])],
        ]);

        $amount = round((float) $data['amount'], 2);
        $date = Carbon::parse($data['booked_on']);

        $match = Transaction::where('household_id', $household->id)
            ->where('amount', -$amount)
            ->whereBetween('booked_on', [$date->copy()->subDays(4)->toDateString(), $date->copy()->addDays(4)->toDateString()])
            ->orderBy('booked_on')->first();

        if ($match !== null && empty($data['mode'])) {
            return $this->ok(['saved' => false, 'match' => $this->present($match)]);
        }

        if ($match !== null && $data['mode'] === 'assign') {
            $match->update([
                'category' => $data['category'],
                'category_locked' => true,
                'purpose' => trim($match->purpose.' · '.(($data['purpose'] ?? null) ?: $data['counterparty']), ' ·'),
            ]);

            return $this->ok(['saved' => true, 'item' => $this->present($match)]);
        }

        $account = ! empty($data['account'])
            ? BankAccount::where('household_id', $household->id)->where('user_id', $this->user()->id)->findOrFail($data['account'])
            : BankAccount::firstOrCreate(['user_id' => $this->user()->id, 'household_id' => $household->id, 'name' => __('Cash wallet')]);

        $transaction = Transaction::create([
            'household_id' => $household->id,
            'user_id' => $this->user()->id,
            'bank_account_id' => $account->id,
            'booked_on' => $date->toDateString(),
            'amount' => -$amount,
            'counterparty' => trim($data['counterparty']),
            'purpose' => trim((string) ($data['purpose'] ?? '')),
            'category' => $data['category'],
            'category_locked' => true,
            'import_hash' => sha1('manual|'.Str::uuid()),
        ]);

        return $this->ok(['saved' => true, 'item' => $this->present($transaction)], 201);
    }

    public function readReceipt(Request $request, FinanceReceiptAi $ai): JsonResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:10240']]);
        $file = $request->file('photo');
        abort_if($file === null || ! in_array($file->getMimeType(), FinanceReceiptAi::MEDIA_TYPES, true), 422);

        try {
            $result = $ai->read((string) file_get_contents($file->getRealPath()), (string) $file->getMimeType());
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['photo' => $exception->getMessage()]);
        }

        return $this->ok($result);
    }

    public function renameAccount(Request $request, int $account): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $this->account($account)->update(['name' => trim($data['name'])]);

        return $this->ok($this->accounts($this->householdId()));
    }

    public function destroyAccount(int $account): JsonResponse
    {
        $model = $this->account($account);
        ExternalLink::where('linkable_type', 'transaction')->whereIn('linkable_id', $model->transactions()->select('id'))->get()->each->delete();
        $model->delete();

        return $this->ok($this->accounts($this->householdId()));
    }

    public function rules(): JsonResponse
    {
        return $this->ok(FinanceRule::where('user_id', $this->user()->id)->orderBy('pattern')->get()->map(fn (FinanceRule $rule): array => [
            'id' => $rule->id,
            'pattern' => $rule->pattern,
            'category' => $rule->category,
            'label' => FinanceCategories::label($rule->category),
        ])->all());
    }

    public function destroyRule(int $rule): JsonResponse
    {
        FinanceRule::where('user_id', $this->user()->id)->whereKey($rule)->delete();

        return $this->ok();
    }

    public function applyRules(): JsonResponse
    {
        $categorizer = new Categorizer($this->user()->id);
        $changed = 0;

        Transaction::where('user_id', $this->user()->id)->where('household_id', $this->householdId())->where('category_locked', false)->get()
            ->each(function (Transaction $transaction) use ($categorizer, &$changed): void {
                $category = $categorizer->categorize($transaction->counterparty, $transaction->purpose, (float) $transaction->amount);

                if ($category !== $transaction->category) {
                    $transaction->update(['category' => $category]);
                    $changed++;
                }
            });

        return $this->ok(['changed' => $changed]);
    }

    public function categorizeWithAi(FinanceAi $ai): JsonResponse
    {
        $open = Transaction::where('user_id', $this->user()->id)->where('household_id', $this->householdId())
            ->where('category_locked', false)->whereIn('category', [FinanceCategories::OTHER, FinanceCategories::OTHER_INCOME])
            ->latest('booked_on')->limit(400)->get();

        if ($open->isEmpty()) {
            return $this->ok(['changed' => 0, 'open' => 0]);
        }

        try {
            $categories = $ai->categorize($open->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id, 'counterparty' => $transaction->counterparty, 'purpose' => mb_substr((string) $transaction->purpose, 0, 140), 'amount' => $transaction->amount,
            ])->values()->all());
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['ai' => $exception->getMessage()]);
        }

        $changed = 0;

        foreach ($open as $transaction) {
            $category = $categories[$transaction->id] ?? null;

            if ($category && $category !== $transaction->category && ($category === FinanceCategories::TRANSFER || (FinanceCategories::group($category) === 'income') === ((float) $transaction->amount >= 0))) {
                $transaction->update(['category' => $category]);
                $changed++;
            }
        }

        return $this->ok(['changed' => $changed, 'open' => $open->count()]);
    }

    /**
     * Remembers the payee of a booking for a category: all its bookings (now and in future
     * imports) get that category.
     */
    public function remember(int $transaction): JsonResponse
    {
        $tx = Transaction::where('household_id', $this->householdId())->findOrFail($transaction);
        abort_if($tx->category === null, 422);
        $pattern = mb_substr(FinanceReport::payeeKey($tx->counterparty, $tx->purpose), 0, 100);
        abort_if($pattern === '—', 422);

        FinanceRule::updateOrCreate(['user_id' => $this->user()->id, 'pattern' => $pattern], ['category' => $tx->category]);
        $changed = Transaction::where('user_id', $this->user()->id)->where('category_locked', false)
            ->where(fn ($query) => $query->whereRaw('LOWER(counterparty) LIKE ?', ['%'.$pattern.'%'])->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.$pattern.'%']))
            ->update(['category' => $tx->category]);

        return $this->ok(['pattern' => $pattern, 'changed' => $changed]);
    }

    private function householdId(): int
    {
        return ($this->user()->currentHousehold() ?? abort(404))->id;
    }

    private function account(int $id): BankAccount
    {
        return BankAccount::where('user_id', $this->user()->id)->where('household_id', $this->householdId())->findOrFail($id);
    }

    /**
     * @return list<array{id: int, name: string, count: int}>
     */
    private function accounts(int $householdId): array
    {
        return BankAccount::where('household_id', $householdId)->withCount('transactions')->orderBy('name')->get()
            ->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'count' => (int) $account->getAttribute('transactions_count')])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'booked_on' => Carbon::parse((string) $transaction->booked_on)->toDateString(),
            'amount' => round((float) $transaction->amount, 2),
            'counterparty' => $transaction->counterparty,
            'purpose' => $transaction->purpose,
            'category' => $transaction->category,
            'category_label' => $transaction->category !== null ? FinanceCategories::label($transaction->category) : null,
            'account' => $transaction->account instanceof BankAccount ? $transaction->account->name : null,
        ];
    }
}
