<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ShoppingList;
use App\Models\ShoppingListReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes pinned above the shopping list ("take the deposit bottles"): they stay visible and can be ticked off.
 */
class ShoppingNotesController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok($this->list()->reminders()->get()->map(fn (ShoppingListReminder $note): array => $this->data($note))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:255']]);
        $note = $this->list()->reminders()->create(['text' => trim($data['text'])]);

        return $this->ok($this->data($note), 201);
    }

    public function toggle(int $note): JsonResponse
    {
        $model = $this->find($note);
        $model->update(['done_at' => $model->done_at ? null : now()]);

        return $this->ok($this->data($model));
    }

    public function destroy(int $note): JsonResponse
    {
        $this->find($note)->delete();

        return $this->ok();
    }

    private function list(): ShoppingList
    {
        return ShoppingList::defaultFor(($this->user()->currentHousehold() ?? abort(404))->id);
    }

    private function find(int $id): ShoppingListReminder
    {
        return ShoppingListReminder::where('shopping_list_id', $this->list()->id)->findOrFail($id);
    }

    /**
     * @return array{id: int, text: string, done: bool}
     */
    private function data(ShoppingListReminder $note): array
    {
        return ['id' => $note->id, 'text' => $note->text, 'done' => $note->done_at !== null];
    }
}
