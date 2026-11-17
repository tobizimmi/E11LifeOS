<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\ExpenseGroupController;
use App\Models\ExpenseGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Share link, renaming people and exports of an expense group for the app.
 */
class SplitShareController extends ApiController
{
    public function create(int $group): JsonResponse
    {
        $model = $this->find($group);
        $model->update(['share_token' => Str::random(40)]);

        return $this->ok($this->shareData($model->refresh()));
    }

    public function revoke(int $group): JsonResponse
    {
        $model = $this->find($group);
        $model->update(['share_token' => null, 'share_expires_at' => null]);

        return $this->ok($this->shareData($model->refresh()));
    }

    public function expiry(Request $request, int $group): JsonResponse
    {
        $model = $this->find($group);
        $data = $request->validate(['days' => ['nullable', 'in:7,30,90']]);
        $model->forceFill(['share_expires_at' => ($data['days'] ?? null) ? now()->addDays((int) $data['days']) : null])->save();

        return $this->ok($this->shareData($model->refresh()));
    }

    public function renameMember(Request $request, int $group, int $member): JsonResponse
    {
        $model = $this->find($group);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $model->members()->whereKey($member)->firstOrFail()->update(['name' => trim($data['name'])]);

        return $this->ok();
    }

    public function export(Request $request, int $group, string $format): Response
    {
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        return app(ExpenseGroupController::class)->export($request, $this->find($group), $format);
    }

    private function find(int $id): ExpenseGroup
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return ExpenseGroup::query()->visibleTo($this->user(), $household->id)->findOrFail($id);
    }

    /**
     * @return array{share_url: ?string, share_expires_at: ?string}
     */
    private function shareData(ExpenseGroup $group): array
    {
        return [
            'share_url' => $group->share_token ? route('splits.public', $group->share_token) : null,
            'share_expires_at' => $group->share_expires_at?->toIso8601String(),
        ];
    }
}
