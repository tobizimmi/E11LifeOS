<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\HouseholdMember;
use App\Models\SmartHomeDashboard;
use App\Models\SmartHomeDashboardCard;
use App\Models\SmartHomeEntity;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\SmartHome\EntityControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Smart home dashboards for the app: open one with live values, create, rename and delete them, and
 * arrange the cards (devices, headings, energy flow, an area at once, widgets).
 */
class SmartHomeDashboardsController extends ApiController
{
    public function show(int $dashboard): JsonResponse
    {
        $model = $this->visible($dashboard);
        $household = $this->user()->currentHousehold() ?? abort(404);

        return $this->ok([
            'id' => $model->id,
            'name' => $model->name,
            'icon' => $model->icon,
            'personal' => (bool) $model->personal,
            'editable' => $this->mayEdit($model),
            'may_control' => EntityControl::mayControl($this->user(), $household->id),
            'energy' => EnergyRecorder::latest($household->id) ?: null,
            'cards' => $model->cards()->with('entity')->get()->map(fn (SmartHomeDashboardCard $card): array => [
                'id' => $card->id,
                'kind' => $card->kind,
                'display' => $card->display,
                'size' => $card->size,
                'title' => $card->title,
                'displays' => $card->entity ? SmartHomeDashboardCard::displaysFor($card->entity) : [],
                'device' => $card->entity ? $this->device($card->entity) : null,
            ])->all(),
            'sizes' => collect(SmartHomeDashboardCard::sizeLabels())->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'display_labels' => SmartHomeDashboardCard::displayLabels(),
            'devices' => SmartHomeEntity::where('household_id', $household->id)->orderBy('position')->orderBy('name')->get(['id', 'name', 'area'])->map(fn (SmartHomeEntity $entity): array => ['id' => $entity->id, 'name' => $entity->name, 'area' => $entity->area])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        $data = $request->validate(['name' => ['nullable', 'string', 'max:60'], 'icon' => ['nullable', 'string', 'max:16']]);

        $dashboard = SmartHomeDashboard::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'name' => trim((string) ($data['name'] ?? '')) ?: __('New dashboard'),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: '🧩',
            'position' => (int) SmartHomeDashboard::where('household_id', $household->id)->max('position') + 1,
        ]);

        return $this->ok(['id' => $dashboard->id], 201);
    }

    public function update(Request $request, int $dashboard): JsonResponse
    {
        $model = $this->editable($dashboard);
        $data = $request->validate(['name' => ['required', 'string', 'max:60'], 'icon' => ['nullable', 'string', 'max:16'], 'personal' => ['sometimes', 'boolean']]);

        $model->update([
            'name' => trim($data['name']),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: null,
            // Only the creator can make a dashboard personal – otherwise it would vanish for them.
            'personal' => isset($data['personal']) && $model->created_by === $this->user()->id ? (bool) $data['personal'] : $model->personal,
        ]);

        return $this->ok();
    }

    public function destroy(int $dashboard): JsonResponse
    {
        $this->editable($dashboard)->delete();

        return $this->ok();
    }

    /**
     * Adds a card: a device (`entity_id`), all devices of an area (`area`, "-" for none), a heading (`title`),
     * the energy flow (`kind: energy`) or a widget (`kind: weather|agenda|scenes`).
     */
    public function addCard(Request $request, int $dashboard): JsonResponse
    {
        $model = $this->editable($dashboard);
        $household = $this->user()->currentHousehold() ?? abort(404);
        $data = $request->validate([
            'entity_id' => ['nullable', 'integer'],
            'area' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:80'],
            'kind' => ['nullable', 'in:energy,weather,agenda,scenes'],
        ]);

        if (! empty($data['entity_id'])) {
            $entity = SmartHomeEntity::where('household_id', $household->id)->findOrFail($data['entity_id']);
            $this->append($model, ['smart_home_entity_id' => $entity->id, 'display' => SmartHomeDashboardCard::defaultDisplay($entity)]);
        } elseif (isset($data['area'])) {
            $area = $data['area'];
            $entities = SmartHomeEntity::where('household_id', $household->id)
                ->where(fn ($query) => $area === '-' ? $query->whereNull('area')->orWhere('area', '') : $query->where('area', $area))
                ->orderBy('position')->orderBy('name')->get();
            abort_if($entities->isEmpty(), 422, __('No devices in this area.'));
            $this->append($model, ['kind' => 'heading', 'title' => $area === '-' ? __('Other') : $area, 'size' => 'full']);

            foreach ($entities as $entity) {
                $this->append($model, ['smart_home_entity_id' => $entity->id, 'display' => SmartHomeDashboardCard::defaultDisplay($entity)]);
            }
        } elseif (! empty($data['title'])) {
            $this->append($model, ['kind' => 'heading', 'title' => trim($data['title']), 'size' => 'full']);
        } elseif (! empty($data['kind'])) {
            $this->append($model, ['kind' => $data['kind'], 'display' => 'value', 'size' => $data['kind'] === 'scenes' ? 'full' : 'medium']);
        } else {
            abort(422);
        }

        return $this->ok(null, 201);
    }

    public function updateCard(Request $request, int $card): JsonResponse
    {
        $model = $this->card($card);
        $data = $request->validate(['display' => ['sometimes', 'string'], 'size' => ['sometimes', 'in:'.implode(',', SmartHomeDashboardCard::SIZES)], 'title' => ['sometimes', 'nullable', 'string', 'max:80']]);

        if (isset($data['display'])) {
            abort_unless($model->entity && in_array($data['display'], SmartHomeDashboardCard::displaysFor($model->entity), true), 422);
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = mb_substr(trim((string) $data['title']), 0, 80) ?: null;
        }

        $model->update($data);

        return $this->ok();
    }

    public function removeCard(int $card): JsonResponse
    {
        $this->card($card)->delete();

        return $this->ok();
    }

    /**
     * Puts the card at a new position (0 = first) and renumbers the others.
     */
    public function moveCard(Request $request, int $card): JsonResponse
    {
        $model = $this->card($card);
        $data = $request->validate(['position' => ['required', 'integer', 'min:0']]);
        $ids = SmartHomeDashboard::findOrFail($model->smart_home_dashboard_id)->cards()->pluck('id')->reject(fn (int $other): bool => $other === $model->id)->values()->all();
        array_splice($ids, min($data['position'], count($ids)), 0, [$model->id]);

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $cardId) {
                SmartHomeDashboardCard::whereKey($cardId)->update(['position' => $index]);
            }
        });

        return $this->ok();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function append(SmartHomeDashboard $dashboard, array $attributes): void
    {
        $dashboard->cards()->create($attributes + ['kind' => 'entity', 'display' => 'tile', 'size' => 'small', 'position' => (int) $dashboard->cards()->max('position') + 1]);
    }

    private function visible(int $id): SmartHomeDashboard
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return SmartHomeDashboard::query()->visibleTo($this->user(), $household->id)->findOrFail($id);
    }

    private function editable(int $id): SmartHomeDashboard
    {
        $dashboard = $this->visible($id);
        abort_unless($this->mayEdit($dashboard), 403);

        return $dashboard;
    }

    private function card(int $id): SmartHomeDashboardCard
    {
        $card = SmartHomeDashboardCard::with('entity')->findOrFail($id);
        $this->editable($card->smart_home_dashboard_id);

        return $card;
    }

    private function mayEdit(SmartHomeDashboard $dashboard): bool
    {
        $household = $this->user()->currentHousehold();
        $isOwner = $household && (bool) HouseholdMember::where('household_id', $household->id)->where('user_id', $this->user()->id)->first()?->isOwner();

        return $dashboard->isEditableBy($this->user(), $isOwner);
    }

    /**
     * @return array<string, mixed>
     */
    private function device(SmartHomeEntity $entity): array
    {
        return [
            'id' => $entity->id,
            'name' => $entity->name,
            'area' => $entity->area,
            'icon' => $entity->icon(),
            'state' => $entity->state,
            'display_state' => $entity->displayState(),
            'unit' => $entity->unit,
            'on' => $entity->isOn(),
            'switchable' => $entity->isSwitchable() || $entity->isRunnable(),
            'critical' => EntityControl::isCritical($entity),
        ];
    }
}
