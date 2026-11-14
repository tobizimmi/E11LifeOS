<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\LoyaltyCard;
use App\Support\CardCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Loyalty cards for the app: the list comes with the rendered code (SVG) so a card can be shown
 * at the checkout without a connection.
 */
class LoyaltyCardController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'formats' => collect(CardCode::FORMATS)->map(fn (string $format) => ['key' => $format, 'label' => CardCode::label($format)])->all(),
            'cards' => LoyaltyCard::query()->visibleTo($this->user(), $this->household()->id)
                ->orderBy('position')->orderBy('name')->get()
                ->map(fn (LoyaltyCard $card) => $this->cardData($card))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $household = $this->household();

        $card = LoyaltyCard::create($this->attributes($data) + [
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'position' => (int) LoyaltyCard::where('household_id', $household->id)->max('position') + 1,
        ]);

        return $this->ok($this->cardData($card), 201);
    }

    public function update(Request $request, int $card): JsonResponse
    {
        $model = $this->find($card);
        $data = $this->validated($request);
        $attributes = $this->attributes($data);

        // Only the person who added a card decides whether it is private.
        if (array_key_exists('private', $data) && $model->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $model->update($attributes);

        return $this->ok($this->cardData($model));
    }

    public function destroy(int $card): JsonResponse
    {
        $this->find($card)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:500'],
            'code_format' => ['required', Rule::in(CardCode::FORMATS)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'private' => ['sometimes', 'boolean'],
            'details' => ['sometimes', 'array', 'max:20'],
            'details.*.label' => ['nullable', 'string', 'max:60'],
            'details.*.value' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'name' => trim($data['name']),
            'code' => trim($data['code']),
            'code_format' => $data['code_format'],
            'color' => ($data['color'] ?? null) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            'link_url' => trim((string) ($data['link_url'] ?? '')) ?: null,
        ];

        if (array_key_exists('details', $data)) {
            $attributes['details'] = collect($data['details'])
                ->map(fn ($row) => ['label' => trim((string) ($row['label'] ?? '')), 'value' => trim((string) ($row['value'] ?? ''))])
                ->filter(fn (array $row) => $row['label'] !== '' || $row['value'] !== '')
                ->values()->all() ?: null;
        }

        return $attributes;
    }

    private function find(int $id): LoyaltyCard
    {
        return LoyaltyCard::query()->visibleTo($this->user(), $this->household()->id)->findOrFail($id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function cardData(LoyaltyCard $card): array
    {
        return [
            'id' => $card->id,
            'name' => $card->name,
            'code' => $card->code,
            'code_format' => $card->code_format,
            'format_label' => $card->formatLabel(),
            'two_dimensional' => $card->isTwoDimensional(),
            'code_svg' => $card->codeSvg(),
            'color' => $card->color,
            'notes' => $card->notes,
            'link_url' => $card->safeLinkUrl(),
            'details' => $card->detailRows(),
            'private' => $card->isPrivate(),
            'mine' => $card->created_by === $this->user()->id,
            'has_front_image' => $card->front_image_path !== null,
            'has_back_image' => $card->back_image_path !== null,
        ];
    }
}
