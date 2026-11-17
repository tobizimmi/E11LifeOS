<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\ExpenseMember;
use App\Models\ExpenseShare;
use App\Models\Household;
use App\Models\Item;
use App\Support\Life\ExpenseSplitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Split costs for the app: groups with their people, expenses, balances and the transfers that
 * settle them.
 */
class SplitController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();
        $groups = ExpenseGroup::query()->visibleTo($user, $this->household()->id)->with(['members', 'expenses.shares'])->latest()->get();

        return $this->ok([
            'icons' => ExpenseGroup::ICONS,
            'groups' => $groups->map(function (ExpenseGroup $group) use ($user) {
                $me = $group->members->firstWhere('user_id', $user->id);

                return [
                    'id' => $group->id,
                    'title' => $group->title,
                    'icon' => $group->icon,
                    'archived' => $group->archived_at !== null,
                    'members' => $group->members->count(),
                    'total' => round((float) $group->expenses->reject->is_transfer->sum('amount'), 2),
                    'my_balance' => $me ? (ExpenseSplitter::balances($group)[$me->id]['balance'] ?? 0.0) : null,
                ];
            })->all(),
        ]);
    }

    /**
     * A new group: the member takes part, further people come as a list of names.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'icon' => ['nullable', Rule::in(ExpenseGroup::ICONS)],
            'people' => ['sometimes', 'array', 'max:50'],
            'people.*' => ['string', 'max:80'],
            'private' => ['sometimes', 'boolean'],
        ]);

        $user = $this->user();
        $group = ExpenseGroup::create([
            'household_id' => $this->household()->id,
            'created_by' => $user->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => trim($data['title']),
            'icon' => $data['icon'] ?? ExpenseGroup::ICONS[0],
        ]);

        $group->members()->create(['user_id' => $user->id, 'name' => $user->name, 'position' => 0]);
        collect($data['people'] ?? [])->map(fn (string $name) => trim($name))->filter()
            ->reject(fn (string $name) => mb_strtolower($name) === mb_strtolower($user->name))->unique()->values()
            ->each(fn (string $name, int $index) => $group->members()->create(['name' => $name, 'position' => $index + 1]));

        return $this->ok($this->groupData($group->id), 201);
    }

    public function show(int $group): JsonResponse
    {
        return $this->ok($this->groupData($this->find($group)->id));
    }

    public function archive(int $group): JsonResponse
    {
        $model = $this->find($group);
        $model->update(['archived_at' => $model->archived_at ? null : now()]);

        return $this->ok($this->groupData($model->id));
    }

    public function destroy(int $group): JsonResponse
    {
        $model = $this->find($group);
        // People who only got the group shared may edit, but not delete it.
        abort_unless($model->household_id === $this->household()->id, 403);
        $model->delete();

        return $this->ok();
    }

    public function storeMember(Request $request, int $group): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $model = $this->find($group);
        $model->members()->create(['name' => trim($data['name']), 'position' => (int) $model->members->max('position') + 1]);

        return $this->ok($this->groupData($model->id), 201);
    }

    /**
     * Removes a person who has no expenses yet.
     */
    public function destroyMember(int $group, int $member): JsonResponse
    {
        $model = $this->find($group);
        $person = $model->members->firstWhere('id', $member) ?? abort(404);
        $used = $model->expenses()->where('paid_by', $person->id)->exists()
            || $model->expenses()->whereHas('shares', fn ($query) => $query->where('expense_member_id', $person->id))->exists();
        abort_if($used, 422, __('This person already has expenses and cannot be removed.'));
        $person->delete();

        return $this->ok($this->groupData($model->id));
    }

    public function storeExpense(Request $request, int $group): JsonResponse
    {
        $model = $this->find($group);
        [$attributes, $values] = $this->expenseInput($request, $model);

        $expense = $model->expenses()->create($attributes + ['created_by' => $this->user()->id]);
        ExpenseSplitter::saveShares($expense->fresh(), $values);

        return $this->ok($this->groupData($model->id), 201);
    }

    public function updateExpense(Request $request, int $group, int $expense): JsonResponse
    {
        $model = $this->find($group);
        $entry = $model->expenses()->where('is_transfer', false)->findOrFail($expense);
        [$attributes, $values] = $this->expenseInput($request, $model);

        $entry->update($attributes);
        ExpenseSplitter::saveShares($entry->fresh(), $values);

        return $this->ok($this->groupData($model->id));
    }

    public function destroyExpense(int $group, int $expense): JsonResponse
    {
        $model = $this->find($group);
        $model->expenses()->findOrFail($expense)->delete();

        return $this->ok($this->groupData($model->id));
    }

    /**
     * Records a repayment from one person to another.
     */
    public function settle(Request $request, int $group): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'integer', 'different:to'],
            'to' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
        ]);
        $model = $this->find($group);
        abort_unless(count(ExpenseSplitter::validMemberIds($model, [$data['from'], $data['to']])) === 2, 422, __('Unknown person.'));
        ExpenseSplitter::recordTransfer($model, (int) $data['from'], (int) $data['to'], (float) $data['amount'], $this->user()->id);

        return $this->ok($this->groupData($model->id), 201);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, float>}
     */
    private function expenseInput(Request $request, ExpenseGroup $group): array
    {
        $memberIds = $group->members->pluck('id')->all();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:10000000'],
            'paid_by' => ['required', 'integer', Rule::in($memberIds)],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'category' => ['nullable', Rule::in(array_keys(ExpenseSplitter::categories()))],
            'split_mode' => ['nullable', Rule::in(Expense::SPLIT_MODES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'shares' => ['sometimes', 'array', 'min:1'],
            'shares.*.member' => ['required', 'integer'],
            'shares.*.value' => ['nullable', 'numeric', 'min:0'],
        ]);

        $mode = $data['split_mode'] ?? 'equal';
        // Without an explicit list everyone takes part equally.
        $rows = $data['shares'] ?? array_map(fn (int $id) => ['member' => $id, 'value' => 1], $memberIds);
        $valid = ExpenseSplitter::validMemberIds($group, array_column($rows, 'member'));
        $values = collect($rows)->filter(fn (array $row) => in_array((int) $row['member'], $valid, true))
            ->mapWithKeys(fn (array $row) => [(int) $row['member'] => (float) ($row['value'] ?? 1)])->all();

        try {
            ExpenseSplitter::split((float) $data['amount'], $mode, $values);
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return [[
            'title' => trim($data['title']),
            'amount' => (float) $data['amount'],
            'paid_by' => (int) $data['paid_by'],
            'spent_on' => $data['spent_on'],
            'category' => $data['category'] ?? 'other',
            'split_mode' => $mode,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ], $values];
    }

    private function find(int $id): ExpenseGroup
    {
        return ExpenseGroup::query()->visibleTo($this->user(), $this->household()->id)->with('members')->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function groupData(int $groupId): array
    {
        $group = ExpenseGroup::with(['members', 'expenses.shares'])->findOrFail($groupId);
        $balances = ExpenseSplitter::balances($group);
        $stats = ExpenseSplitter::statistics($group);
        $me = $group->members->firstWhere('user_id', $this->user()->id);

        return [
            'id' => $group->id,
            'title' => $group->title,
            'icon' => $group->icon,
            'archived' => $group->archived_at !== null,
            'share_url' => $group->share_token ? route('splits.public', $group->share_token) : null,
            'share_expires_at' => $group->share_expires_at?->toIso8601String(),
            'total' => $stats['total'],
            'per_person' => $stats['per_person'],
            'me' => $me?->id,
            'categories' => collect(ExpenseSplitter::categories())->map(fn (array $category, string $key) => ['key' => $key] + $category)->values()->all(),
            'members' => $group->members->map(fn (ExpenseMember $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'paid' => $balances[$member->id]['paid'] ?? 0.0,
                'owed' => $balances[$member->id]['owed'] ?? 0.0,
                'balance' => $balances[$member->id]['balance'] ?? 0.0,
            ])->values()->all(),
            'settlements' => ExpenseSplitter::settlements($balances),
            'expenses' => $group->expenses->sortByDesc(fn (Expense $expense) => $expense->spent_on->toDateString().str_pad((string) $expense->id, 10, '0', STR_PAD_LEFT))
                ->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'amount' => (float) $expense->amount,
                    'paid_by' => $expense->paid_by,
                    'spent_on' => $expense->spent_on->toDateString(),
                    'category' => $expense->category,
                    'split_mode' => $expense->split_mode,
                    'is_transfer' => (bool) $expense->is_transfer,
                    'notes' => $expense->notes,
                    'shares' => $expense->shares->map(fn (ExpenseShare $share) => ['member' => $share->expense_member_id, 'value' => (float) $share->value, 'amount' => (float) $share->amount])->values()->all(),
                ])->values()->all(),
        ];
    }
}
