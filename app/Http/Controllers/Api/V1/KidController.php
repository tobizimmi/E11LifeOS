<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Kid;
use App\Models\KidChore;
use App\Models\KidChoreCompletion;
use App\Models\KidTransaction;
use App\Support\Life\PocketMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Kids & pocket money for the app: the children with their balance and savings goal, chores to
 * tick off, what waits for a parent's confirmation, and money in and out.
 */
class KidController extends ApiController
{
    public function index(): JsonResponse
    {
        $householdId = $this->household()->id;
        $kids = Kid::where('household_id', $householdId)->orderBy('birth_date')->orderBy('name')->get();
        $chores = KidChore::where('household_id', $householdId)->orderByDesc('active')->orderBy('title')->get();
        $active = $chores->where('active', true);
        $balances = KidTransaction::whereIn('kid_id', $kids->pluck('id'))->selectRaw('kid_id, SUM(amount) as total')->groupBy('kid_id')->pluck('total', 'kid_id');

        return $this->ok([
            'frequencies' => KidChore::FREQUENCIES,
            'kids' => $kids->map(function (Kid $kid) use ($active, $balances) {
                $balance = round((float) ($balances[$kid->id] ?? 0), 2);

                return [
                    'id' => $kid->id,
                    'name' => $kid->name,
                    'emoji' => $kid->emoji,
                    'age' => $kid->age(),
                    'birth_date' => $this->day($kid->birth_date),
                    'weekly_allowance' => $kid->weekly_allowance !== null ? (float) $kid->weekly_allowance : null,
                    'allowance_day' => (int) $kid->allowance_day,
                    'auto_approve' => (bool) $kid->auto_approve,
                    'goal_title' => $kid->goal_title,
                    'goal_amount' => $kid->goal_amount !== null ? (float) $kid->goal_amount : null,
                    'goal_progress' => PocketMoney::goalProgress($kid, $balance),
                    'balance' => $balance,
                    'open_chores' => PocketMoney::openChores($kid, $active)->map(fn (KidChore $chore) => $chore->id)->values()->all(),
                    'history' => $kid->transactions()->limit(20)->get()->map(fn (KidTransaction $transaction) => [
                        'id' => $transaction->id,
                        'date' => $this->day($transaction->date),
                        'kind' => $transaction->kind,
                        'label' => $transaction->label,
                        'amount' => (float) $transaction->amount,
                        'deletable' => $transaction->kid_chore_completion_id === null,
                    ])->all(),
                ];
            })->all(),
            'chores' => $chores->map(fn (KidChore $chore) => $this->choreData($chore))->all(),
            'pending' => KidChoreCompletion::whereIn('kid_id', $kids->pluck('id'))->where('status', KidChoreCompletion::PENDING)->with(['chore', 'kid'])->orderBy('done_on')->get()
                ->map(fn (KidChoreCompletion $completion) => [
                    'id' => $completion->id,
                    'kid_id' => $completion->kid_id,
                    'chore_id' => $completion->kid_chore_id,
                    'title' => KidChore::whereKey($completion->kid_chore_id)->value('title'),
                    'done_on' => $this->day($completion->done_on),
                    'reward' => (float) $completion->reward,
                ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Kid::create($this->kidAttributes($request) + ['household_id' => $this->household()->id, 'last_allowance_on' => today()->toDateString()]);

        return $this->index();
    }

    public function update(Request $request, int $kid): JsonResponse
    {
        $this->findKid($kid)->update($this->kidAttributes($request));

        return $this->index();
    }

    public function destroy(int $kid): JsonResponse
    {
        $this->findKid($kid)->delete();

        return $this->ok();
    }

    public function storeChore(Request $request): JsonResponse
    {
        KidChore::create($this->choreAttributes($request) + ['household_id' => $this->household()->id]);

        return $this->index();
    }

    public function updateChore(Request $request, int $chore): JsonResponse
    {
        $this->findChore($chore)->update($this->choreAttributes($request));

        return $this->index();
    }

    public function destroyChore(int $chore): JsonResponse
    {
        $this->findChore($chore)->delete();

        return $this->ok();
    }

    /**
     * A child did a chore: credited right away with auto approval, otherwise it waits for a parent.
     */
    public function choreDone(int $kid, int $chore): JsonResponse
    {
        $child = $this->findKid($kid);
        $task = $this->findChore($chore);
        abort_unless($task->isOpenFor($child, today()), 422, __('This chore is not open right now.'));
        PocketMoney::complete($child, $task, $this->user());

        return $this->index();
    }

    /**
     * A parent confirms or rejects a finished chore.
     */
    public function review(Request $request, int $completion): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $model = KidChoreCompletion::whereIn('kid_id', Kid::where('household_id', $this->household()->id)->select('id'))->find($completion) ?? abort(404);
        $data['approve'] ? PocketMoney::approve($model, $this->user()) : PocketMoney::reject($model, $this->user());

        return $this->index();
    }

    /**
     * Money in (bonus, gift) or out (spend) for a child.
     */
    public function storeTransaction(Request $request, int $kid): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['bonus', 'gift', 'spend'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'label' => ['required', 'string', 'max:120'],
        ]);
        $child = $this->findKid($kid);

        KidTransaction::create([
            'kid_id' => $child->id,
            'date' => today()->toDateString(),
            'kind' => $data['kind'],
            'label' => trim($data['label']),
            'amount' => $data['kind'] === 'spend' ? -(float) $data['amount'] : (float) $data['amount'],
            'created_by' => $this->user()->id,
        ]);

        return $this->index();
    }

    public function destroyTransaction(int $transaction): JsonResponse
    {
        $model = KidTransaction::whereIn('kid_id', Kid::where('household_id', $this->household()->id)->select('id'))->whereNull('kid_chore_completion_id')->find($transaction) ?? abort(404);
        $model->delete();

        return $this->index();
    }

    /**
     * @return array<string, mixed>
     */
    private function kidAttributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'weekly_allowance' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'allowance_day' => ['nullable', 'integer', 'between:1,7'],
            'auto_approve' => ['sometimes', 'boolean'],
            'goal_title' => ['nullable', 'string', 'max:100'],
            'goal_amount' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        return [
            'name' => trim($data['name']),
            'emoji' => ($data['emoji'] ?? null) ?: '🧒',
            'birth_date' => ($data['birth_date'] ?? null) ?: null,
            'weekly_allowance' => $data['weekly_allowance'] ?? null,
            'allowance_day' => (int) ($data['allowance_day'] ?? 1),
            'auto_approve' => (bool) ($data['auto_approve'] ?? false),
            'goal_title' => trim((string) ($data['goal_title'] ?? '')) ?: null,
            'goal_amount' => $data['goal_amount'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function choreAttributes(Request $request): array
    {
        $householdId = $this->household()->id;
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'emoji' => ['nullable', 'string', 'max:16'],
            'reward' => ['required', 'numeric', 'min:0', 'max:1000'],
            'frequency' => ['required', Rule::in(KidChore::FREQUENCIES)],
            'kid_id' => ['nullable', Rule::exists('kids', 'id')->where('household_id', $householdId)],
            'active' => ['sometimes', 'boolean'],
        ]);

        return [
            'title' => trim($data['title']),
            'emoji' => ($data['emoji'] ?? null) ?: '🧹',
            'reward' => (float) $data['reward'],
            'frequency' => $data['frequency'],
            'kid_id' => ($data['kid_id'] ?? null) ?: null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    private function findKid(int $id): Kid
    {
        return Kid::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    private function findChore(int $id): KidChore
    {
        return KidChore::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function day(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function choreData(KidChore $chore): array
    {
        return [
            'id' => $chore->id,
            'title' => $chore->title,
            'emoji' => $chore->emoji,
            'reward' => (float) $chore->reward,
            'frequency' => $chore->frequency,
            'kid_id' => $chore->kid_id,
            'active' => (bool) $chore->active,
        ];
    }
}
