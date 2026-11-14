<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Contact;
use App\Models\GiftIdea;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * People & occasions for the app: contacts with birthday, anniversary, notes and gift ideas.
 */
class ContactController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(
            Contact::query()->visibleTo($this->user(), $this->household()->id)->with('giftIdeas')->orderBy('name')->get()
                ->map(fn (Contact $person) => $this->personData($person))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $person = Contact::create($this->attributes($data) + [
            'household_id' => $this->household()->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
        ]);

        return $this->ok($this->personData($person->refresh()), 201);
    }

    public function update(Request $request, int $person): JsonResponse
    {
        $model = $this->find($person);
        $data = $this->validated($request);
        $attributes = $this->attributes($data);

        if (array_key_exists('private', $data) && $model->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $model->update($attributes);

        return $this->ok($this->personData($model));
    }

    public function destroy(int $person): JsonResponse
    {
        $model = $this->find($person);
        $model->giftIdeas()->delete();
        $model->delete();

        return $this->ok();
    }

    /**
     * Notes that the person was contacted today.
     */
    public function contacted(int $person): JsonResponse
    {
        $model = $this->find($person);
        $model->update(['last_contact_on' => today()]);

        return $this->ok($this->personData($model));
    }

    public function storeGift(Request $request, int $person): JsonResponse
    {
        $model = $this->find($person);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'url:http,https', 'max:2000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'occasion' => ['nullable', 'string', 'max:64'],
        ]);

        $gift = $model->giftIdeas()->create([
            'created_by' => $this->user()->id,
            'title' => trim($data['title']),
            'url' => ($data['url'] ?? null) ?: null,
            'price' => ($data['price'] ?? null) !== null && $data['price'] !== '' ? round((float) $data['price'], 2) : null,
            'occasion' => trim((string) ($data['occasion'] ?? '')) ?: null,
            'status' => 'idea',
        ]);

        return $this->ok($this->giftData($gift), 201);
    }

    public function updateGift(Request $request, int $gift): JsonResponse
    {
        $model = $this->findGift($gift);
        $data = $request->validate(['status' => ['required', Rule::in(GiftIdea::STATUSES)]]);
        $model->update(['status' => $data['status'], 'given_on' => $data['status'] === 'given' ? today() : null]);

        return $this->ok($this->giftData($model));
    }

    public function destroyGift(int $gift): JsonResponse
    {
        $this->findGift($gift)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'relation' => ['nullable', 'string', 'max:64'],
            'birthday' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_year_known' => ['sometimes', 'boolean'],
            'anniversary' => ['nullable', 'date_format:Y-m-d'],
            'anniversary_label' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'remind_days' => ['sometimes', 'integer', 'between:0,60'],
            'private' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = ['name' => trim($data['name'])];

        foreach (['relation', 'birthday', 'anniversary', 'anniversary_label', 'phone', 'email', 'address', 'notes'] as $field) {
            $attributes[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }

        if (array_key_exists('birth_year_known', $data)) {
            $attributes['birth_year_known'] = (bool) $data['birth_year_known'];
        }

        if (array_key_exists('remind_days', $data)) {
            $attributes['remind_days'] = (int) $data['remind_days'];
        }

        return $attributes;
    }

    private function find(int $id): Contact
    {
        return Contact::query()->visibleTo($this->user(), $this->household()->id)->findOrFail($id);
    }

    private function findGift(int $id): GiftIdea
    {
        $gift = GiftIdea::findOrFail($id);
        $this->find($gift->contact_id);

        return $gift;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function personData(Contact $person): array
    {
        $nextBirthday = $person->nextBirthday();

        return [
            'id' => $person->id,
            'name' => $person->name,
            'relation' => $person->relation,
            'birthday' => $person->birthday?->toDateString(),
            'birth_year_known' => (bool) $person->birth_year_known,
            'next_birthday' => $nextBirthday?->toDateString(),
            'days_until_birthday' => $nextBirthday ? (int) today()->diffInDays($nextBirthday) : null,
            'turns' => $person->turns(),
            'anniversary' => $person->anniversary?->toDateString(),
            'anniversary_label' => $person->anniversary_label,
            'phone' => $person->phone,
            'email' => $person->email,
            'address' => $person->address,
            'notes' => $person->notes,
            'remind_days' => (int) $person->remind_days,
            'last_contact_on' => $person->last_contact_on?->toDateString(),
            'private' => $person->visibility === Item::VISIBILITY_PRIVATE,
            'mine' => $person->created_by === $this->user()->id,
            'gifts' => $person->giftIdeas->map(fn (GiftIdea $gift) => $this->giftData($gift))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function giftData(GiftIdea $gift): array
    {
        return [
            'id' => $gift->id,
            'contact_id' => $gift->contact_id,
            'title' => $gift->title,
            'url' => $gift->url,
            'price' => $gift->price !== null ? (float) $gift->price : null,
            'occasion' => $gift->occasion,
            'status' => $gift->status,
        ];
    }
}
